<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use Override;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;

/**
 * Reduce {@see UuidInterface} to its canonical string form on the way out
 * and rebuild it through {@see Uuid::fromString} on the way in.
 *
 * Without this {@see PropertyNormalizer} recurses into the Ramsey object's
 * private internals (codec, fields, bytes…) and on denormalize tries to
 * assign that nested array back to the `UuidInterface`-typed property —
 * which is impossible. Short-circuiting at the interface level keeps every
 * aggregate id stable across the event-stream round-trip.
 */
final readonly class UuidNormalizer implements DenormalizerInterface, NormalizerInterface
{
    #[Override]
    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        return $data->toString();
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof UuidInterface;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): UuidInterface
    {
        return Uuid::fromString((string) $data);
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, UuidInterface::class, true);
    }

    /**
     * @inheritDoc
     *
     * @return array<class-string, bool>
     */
    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [UuidInterface::class => true];
    }
}
