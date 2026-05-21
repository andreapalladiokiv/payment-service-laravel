<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\EventSourcing\Serialization;

use EventSauce\EventSourcing\Serialization\PayloadSerializer;
use Override;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class SymfonyPayloadSerializer implements PayloadSerializer
{
    public function __construct(
        private NormalizerInterface&DenormalizerInterface $serializer,
    ) {}

    #[Override]
    public function serializePayload(object $event): array
    {
        /** @var array $normalized */
        $normalized = $this->serializer->normalize($event);

        return $normalized;
    }

    #[Override]
    public function unserializePayload(string $className, array $payload): object
    {
        return $this->serializer->denormalize($payload, $className);
    }
}
