<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use Override;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State;

/**
 * Store {@see State} as its code plus the country that gives the code meaning, and rebuild
 * it through the constructor.
 *
 * The same defect {@see PhoneNumberNormalizer} was written for, and it had reached further:
 * `State` declares `private ?string $country` while its constructor takes `?Country`, so
 * {@see PropertyNormalizer} wrote `{"name":"ALASKA","country":"US","state":"AK"}` and then,
 * on the way back, tried to satisfy a `Country`-typed argument from the scalar `"US"` —
 * `MissingConstructorArgumentsException: … requires "$country"`. Since
 * {@see \Techork\PaymentService\Laravel\EventSourcing\Serialization\SymfonyPayloadSerializer}
 * normalizes the event object itself rather than calling `toPayload()`, that made any stored
 * event carrying an address with a state unreplayable — which is the ordinary US, Canadian,
 * Australian, British, Indian or New Zealand address, not a corner case.
 *
 * Two keys rather than one, unlike the phone number. `(string) $state` is only the code, and
 * `new State('AK')` without a country is a different object: it keeps `AK` as its own name
 * and reports no country, so `getName()` would answer `AK` where it used to answer `ALASKA`.
 * A state code means nothing without the country whose list it belongs to.
 *
 * `name` is deliberately not stored — the constructor derives it — but a payload that
 * carries one is read without complaint, so the rows PropertyNormalizer already wrote
 * denormalize through this class unchanged.
 */
final class StateNormalizer implements DenormalizerInterface, NormalizerInterface
{
    /**
     * @return array{state: string, country?: string}
     */
    #[Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        /** @var State $data */
        $country = $data->getCountry();

        return $country === null
            ? ['state' => (string) $data]
            : ['state' => (string) $data, 'country' => $country];
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof State;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): State
    {
        // A bare string is accepted because BillingAddress::toArray() has always written one,
        // and that shape reaches here through anything that stores an address that way.
        if (is_string($data)) {
            return new State($data);
        }

        /** @var array<string, mixed> $data */
        $country = $data['country'] ?? null;

        return new State(
            (string) ($data['state'] ?? ''),
            is_string($country) && $country !== '' ? new Country($country) : null,
        );
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, State::class, true);
    }

    /**
     * @inheritDoc
     *
     * @return array<class-string, bool>
     */
    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [State::class => true];
    }
}
