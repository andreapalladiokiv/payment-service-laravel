<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use Override;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;

/**
 * Reduce {@see PhoneNumber} to its canonical E164 string on the way out and
 * rebuild it through the constructor on the way in.
 *
 * Without this, {@see PropertyNormalizer} recurses into the value object's
 * private `\libphonenumber\PhoneNumber $number` property and emits a nested
 * `{"number":{"countryCode":…,"nationalNumber":…,…}}` array. On denormalize
 * it then tries to satisfy the `__construct(string|PhoneNumber $number)`
 * argument from that inner libphonenumber array — which is neither a string
 * nor a {@see PhoneNumber} — and throws
 * `MissingConstructorArgumentsException: … requires "$number"`, making every
 * phone-bearing aggregate impossible to reconstitute from the event stream.
 *
 * The E164 string is the single storage form: {@see PhoneNumber} re-parses it
 * into a `\libphonenumber\PhoneNumber` itself, so no other shape is needed.
 */
final class PhoneNumberNormalizer implements DenormalizerInterface, NormalizerInterface
{
    #[Override]
    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        return (string) $object;
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof PhoneNumber;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): PhoneNumber
    {
        return new PhoneNumber((string) $data);
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, PhoneNumber::class, true);
    }

    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [PhoneNumber::class => true];
    }
}
