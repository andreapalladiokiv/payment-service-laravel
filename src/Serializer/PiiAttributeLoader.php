<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use LogicException;
use Override;
use ReflectionAttribute;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Serializer\Mapping\AttributeMetadata;
use Symfony\Component\Serializer\Mapping\ClassMetadataInterface;
use Symfony\Component\Serializer\Mapping\Loader\LoaderInterface;
use Techork\PaymentService\Common\Pii;

/**
 * Loader that records #[Pii] attribute info into symfony's standard
 * {@see ClassMetadataInterface} via per-attribute (normalization|denormalization)
 * context entries — so consumers (e.g. {@see PiiAwareObjectNormalizer}) just read
 * AttributeMetadata instead of doing their own reflection scan.
 *
 * Stored under two context keys:
 *  - `__pii`     — the {@see Pii} attribute instance (carries the stub).
 *  - `__pii_raw` — true when the property's declared type is plain `string`,
 *    so the value can be stored verbatim; false means values are
 *    `serialize()`-d so non-string types survive storage round-trips.
 *
 * Combine with symfony's stock {@see \Symfony\Component\Serializer\Mapping\Loader\AttributeLoader}
 * via {@see \Symfony\Component\Serializer\Mapping\Loader\LoaderChain} if you also need to load
 * #[Context], #[Groups], #[SerializedName], etc.
 */
final class PiiAttributeLoader implements LoaderInterface
{
    public const string CONTEXT_PII = '__pii';

    public const string CONTEXT_RAW = '__pii_raw';

    #[Override]
    public function loadClassMetadata(ClassMetadataInterface $classMetadata): bool
    {
        $existing = $classMetadata->getAttributesMetadata();
        $touched = false;

        foreach ($classMetadata->getReflectionClass()->getProperties() as $property) {
            $attributes = $property->getAttributes(Pii::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            $pii = $attributes[0]->newInstance();
            $this->assertStubMatchesDeclaredType($classMetadata->getName(), $property, $pii->stub);

            $name = $property->getName();
            $metadata = $existing[$name] ?? new AttributeMetadata($name);
            $context = [
                self::CONTEXT_PII => $pii,
                self::CONTEXT_RAW => $this->isPlainStringType($property),
            ];
            $metadata->setNormalizationContextForGroups($context);
            $metadata->setDenormalizationContextForGroups($context);

            if (! isset($existing[$name])) {
                $classMetadata->addAttributeMetadata($metadata);
            }

            $touched = true;
        }

        return $touched;
    }

    private function isPlainStringType(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === 'string';
    }

    /**
     * Guards against silent data corruption: a `#[Pii(stub)]` whose value cannot be
     * assigned back to its property (e.g. `string` stub on an `Email`-typed property)
     * would normalize fine but yield an unreadable record once the key is shredded.
     */
    private function assertStubMatchesDeclaredType(string $class, ReflectionProperty $property, mixed $stub): void
    {
        $type = $property->getType();
        if (! $type instanceof ReflectionNamedType) {
            return;
        }

        if ($stub === null) {
            if (! $type->allowsNull()) {
                throw new LogicException(\sprintf(
                    'Pii stub for %s::$%s is null but the property type "%s" is not nullable.',
                    $class,
                    $property->getName(),
                    $type->getName(),
                ));
            }

            return;
        }

        $typeName = $type->getName();
        $matches = $type->isBuiltin() ? $this->matchesBuiltinType($stub, $typeName) : $stub instanceof $typeName;

        if (! $matches) {
            throw new LogicException(\sprintf(
                'Pii stub for %s::$%s is of type "%s" but the property declares "%s".',
                $class,
                $property->getName(),
                get_debug_type($stub),
                $typeName,
            ));
        }
    }

    private function matchesBuiltinType(mixed $stub, string $typeName): bool
    {
        return match ($typeName) {
            'string' => is_string($stub),
            'int' => is_int($stub),
            'float' => is_float($stub) || is_int($stub),
            'bool' => is_bool($stub),
            'array' => is_array($stub),
            'iterable' => is_iterable($stub),
            'object' => is_object($stub),
            'mixed' => true,
            default => false,
        };
    }
}
