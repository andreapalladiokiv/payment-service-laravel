<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use Override;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Laravel\Shredding\PiiStore;

/**
 * PII layer over symfony's ObjectNormalizer.
 *
 * Strategy: composition, not inheritance — actual object (de)normalization is delegated
 * to a wrapped {@see ObjectNormalizer}; this class only adds the PII envelope wrap/unwrap.
 *
 *  - on normalize: ObjectNormalizer reduces the object to an array (Stringable VOs become
 *    strings via JsonSerializableNormalizer, enums via BackedEnumNormalizer, etc.); we
 *    then look up `#[Pii]`-marked attributes for the class via
 *    {@see ClassMetadataFactoryInterface} (populated by {@see PiiAttributeLoader}) and
 *    replace their values with `{__pii, hash, stub}` envelopes.
 *  - on denormalize: same lookup, replace each envelope with either the retrieved
 *    plaintext or the stub, hand the cleaned array to ObjectNormalizer.
 *
 * Per-property storage form is decided by the property's declared type:
 *  - `string`-typed properties → value stored verbatim.
 *  - any other type → value `serialize()`-d so the original type survives storage.
 *
 * Circular references inside the object graph are caught by the inner ObjectNormalizer
 * via {@see \Symfony\Component\Serializer\Normalizer\AbstractNormalizer::isCircularReference()},
 * so supports* answers depend only on type — safe to cache.
 */
final class PiiAwareObjectNormalizer implements NormalizerInterface, DenormalizerInterface, NormalizerAwareInterface, DenormalizerAwareInterface, SerializerAwareInterface
{
    use NormalizerAwareTrait;
    use DenormalizerAwareTrait;

    public function __construct(
        private readonly AbstractObjectNormalizer $inner,
        private readonly PiiStore $store,
        private readonly ClassMetadataFactoryInterface $classMetadataFactory,
    ) {}

    #[Override]
    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        $this->normalizer = $normalizer;
    }

    #[Override]
    public function setDenormalizer(DenormalizerInterface $denormalizer): void
    {
        $this->denormalizer = $denormalizer;
    }

    #[Override]
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->inner->setSerializer($serializer);
    }

    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return ['object' => true];
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_object($data);
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return class_exists($type) || interface_exists($type, false);
    }

    #[Override]
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $data = $this->inner->normalize($object, $format, $context);

        if (! is_array($data) && ! $data instanceof \ArrayObject) {
            return $data;
        }

        foreach ($this->piiAttributes($object::class) as $attribute => [, $raw]) {
            if (! isset($data[$attribute])) {
                continue;
            }

            $stored = $raw ? $data[$attribute] : serialize($data[$attribute]);
            $data[$attribute] = $this->store->store($stored);
        }

        return $data;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (is_array($data)) {
            foreach ($this->piiAttributes($type) as $attribute => [$pii, $raw]) {
                $hash = $data[$attribute] ?? null;
                if (! is_string($hash)) {
                    continue;
                }

                $retrieved = $this->store->retrieve($hash);
                if ($retrieved === null) {
                    $data[$attribute] = $this->normalizer->normalize($pii->stub, $format, $context);
                    continue;
                }

                $data[$attribute] = $raw ? $retrieved : unserialize($retrieved, ['allowed_classes' => false]);
            }
        }

        return $this->inner->denormalize($data, $type, $format, $context);
    }

    /**
     * @param  class-string  $class
     * @return iterable<string, array{0: Pii, 1: bool}>  attribute => [pii, raw]
     */
    private function piiAttributes(string $class): iterable
    {
        if (! class_exists($class) && ! interface_exists($class, false)) {
            return;
        }

        $attributes = $this->classMetadataFactory->getMetadataFor($class)->getAttributesMetadata();

        foreach ($attributes as $attribute => $attributeMetadata) {
            $context = $attributeMetadata->getNormalizationContextForGroups([]);

            if (isset($context[PiiAttributeLoader::CONTEXT_PII])) {
                yield $attribute => [
                    $context[PiiAttributeLoader::CONTEXT_PII],
                    $context[PiiAttributeLoader::CONTEXT_RAW] ?? false,
                ];
            }
        }
    }
}
