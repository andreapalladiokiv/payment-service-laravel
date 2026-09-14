<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use Override;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;

/**
 * Store nothing for {@see Cvc}, and rebuild it empty.
 *
 * PCI DSS 3.3.1 forbids retaining Sensitive Authentication Data after authorization, and it
 * allows no exception for encryption: a CVC that can be decrypted is a CVC that was retained.
 * {@see Cvc} implements that guarantee twice over — `jsonSerialize()` answers `'***'` and
 * `__serialize()` answers `[]` — and the event stream reached neither.
 *
 * `__serialize()` covers the saga path, where the subject is round-tripped through native
 * `serialize()`. The event stream is the other path, and it normalizes the event **object**
 * ({@see \Techork\PaymentService\Laravel\EventSourcing\Serialization\SymfonyPayloadSerializer}),
 * so the stored shape is the property shape {@see PropertyNormalizer} produces by walking the
 * graph — and `PropertyNormalizer` reads `Cvc::$data`, which holds the ciphertext.
 * `jsonSerialize()` does not run either, because {@see PayloadSerializerFactory} leaves
 * `JsonSerializableNormalizer` out of the chain on purpose. The guarantee the value object
 * implements was real, and unconsulted.
 *
 * So this class does for the event stream what `__serialize()` does for the saga: the CVC is
 * dropped, and a replayed card carries none. That is the required outcome rather than a
 * compromise — a saga round trip has always produced a CVC-less subject, and
 * `ConnexPay\ReturnRetry` already reads a null CVC without complaint. `CreditCard::isValid()`
 * asks only about expiry, so a card without a CVC still validates, and every gateway visitor
 * builds its request from the command's instrument rather than from a replayed one, so no
 * authorization loses the CVC it needs.
 *
 * The PAN is deliberately left alone. It is not Sensitive Authentication Data, PCI DSS 3.4
 * permits storing it while it is unreadable, and {@see \Techork\PaymentService\Common\ValueObject\CreditCard\Number}
 * has no `__serialize()` hook — unlike `Cvc` — because the refund-retry path reads `CardNumber`.
 *
 * The slot is stored as `[]` rather than omitted, because the card has the property and the
 * payload should say so. Payloads already written as `{"data": "…"}` are read without complaint,
 * and their ciphertext is discarded on the way in: this change cannot un-store bytes already in
 * `stored_events`, but nothing reads them back out again.
 */
final class CvcNormalizer implements DenormalizerInterface, NormalizerInterface
{
    /**
     * @return array{}
     */
    #[Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        return [];
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Cvc;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Cvc
    {
        // `$data` is ignored, including the `{"data": "…"}` the reflection normalizer wrote
        // before this class existed. Returning an empty `Cvc` leaves `$data` uninitialized, so
        // `getCvc()` answers null — the same state `Cvc::__unserialize()` establishes on the
        // saga path.
        return new Cvc;
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, Cvc::class, true);
    }

    /**
     * @inheritDoc
     *
     * @return array<class-string, bool>
     */
    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [Cvc::class => true];
    }
}
