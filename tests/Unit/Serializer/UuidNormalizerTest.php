<?php

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Common\ValueObject\TokenId;
use Techork\PaymentService\Laravel\Serializer\UuidNormalizer;

/**
 * An aggregate-shaped holder for a `UuidInterface`-typed slot. The normalizer's
 * whole reason to exist is the *nested* case: a bare id would survive any number
 * of ways, but a property typed as the interface is what `PropertyNormalizer`
 * cannot rebuild on its own.
 */
final readonly class UuidNormalizerTestAggregate
{
    public function __construct(
        public UuidInterface $id,
        public string $label,
    ) {}
}

/**
 * Mirrors the production chain shape from `GatewayServiceProvider`: the UUID
 * normalizer sits in front of the reflection-based `PropertyNormalizer`, which
 * is configured with a `ReflectionExtractor` there — without the extractor the
 * inner normalizer never learns a private property's declared type and so never
 * delegates it to us.
 */
function uuidNormalizerSerializer(bool $withUuidNormalizer = true): Serializer
{
    $inner = new PropertyNormalizer(propertyTypeExtractor: new ReflectionExtractor);

    return new Serializer($withUuidNormalizer ? [new UuidNormalizer, $inner] : [$inner]);
}

const UUID_NORMALIZER_TEST_UUID = '0192b1d0-8f2a-7c3e-9a1b-2c3d4e5f6071';

it('normalizes a UuidInterface to its canonical string form', function () {
    expect((new UuidNormalizer)->normalize(Uuid::fromString(UUID_NORMALIZER_TEST_UUID)))
        ->toBe(UUID_NORMALIZER_TEST_UUID);
});

it('denormalizes a canonical string back into a Uuid', function () {
    $rebuilt = (new UuidNormalizer)->denormalize(UUID_NORMALIZER_TEST_UUID, UuidInterface::class);

    expect($rebuilt)->toBeInstanceOf(UuidInterface::class)
        ->and($rebuilt->toString())->toBe(UUID_NORMALIZER_TEST_UUID);
});

it('round-trips a bare Uuid to an equal value', function () {
    $serializer = uuidNormalizerSerializer();
    $original = Uuid::uuid7();

    $rebuilt = $serializer->denormalize($serializer->normalize($original), UuidInterface::class);

    expect($rebuilt->equals($original))->toBeTrue();
});

it('round-trips a UuidInterface-typed property nested in an object', function () {
    // The failure this guards: without the short-circuit, PropertyNormalizer
    // recurses into Ramsey's private internals on the way out and then tries to
    // assign that nested array back to a UuidInterface-typed slot on the way in.
    $serializer = uuidNormalizerSerializer();
    $original = new UuidNormalizerTestAggregate(Uuid::fromString(UUID_NORMALIZER_TEST_UUID), 'intent');

    $payload = $serializer->normalize($original);
    $rebuilt = $serializer->denormalize($payload, UuidNormalizerTestAggregate::class);

    expect($payload)->toBe(['id' => UUID_NORMALIZER_TEST_UUID, 'label' => 'intent'])
        ->and($rebuilt->id->equals($original->id))->toBeTrue()
        ->and($rebuilt->label)->toBe('intent');
});

it('is required for the nested case: PropertyNormalizer alone cannot rebuild the interface slot', function () {
    // Proves the normalizer is load-bearing rather than a convenience. Drop it
    // from the chain and the very same round-trip stops working.
    $serializer = uuidNormalizerSerializer(withUuidNormalizer: false);
    $payload = $serializer->normalize(new UuidNormalizerTestAggregate(Uuid::uuid7(), 'intent'));

    expect($payload['id'])->not->toBeString();
    expect(fn () => $serializer->denormalize($payload, UuidNormalizerTestAggregate::class))
        ->toThrow(NotNormalizableValueException::class);
});

it('round-trips a UuidValueObject that hides a UuidInterface behind a private property', function () {
    // TokenId (and every other UuidValueObject) keeps its Ramsey instance private,
    // so the chain reaches UuidNormalizer only through PropertyNormalizer's
    // reflection. This pins that the two cooperate.
    $serializer = uuidNormalizerSerializer();
    $original = TokenId::fromString(UUID_NORMALIZER_TEST_UUID);

    $payload = $serializer->normalize($original);
    $rebuilt = $serializer->denormalize($payload, TokenId::class);

    expect($payload)->toBe(['uuid' => UUID_NORMALIZER_TEST_UUID])
        ->and($rebuilt)->toBeInstanceOf(TokenId::class)
        ->and($rebuilt->toString())->toBe(UUID_NORMALIZER_TEST_UUID);
});

it('accepts only UuidInterface instances for normalization', function () {
    // supportsNormalization is what decides whether the normalizer is consulted
    // at all — a string id must fall through to whatever handles scalars.
    $normalizer = new UuidNormalizer;

    expect($normalizer->supportsNormalization(Uuid::uuid7()))->toBeTrue()
        ->and($normalizer->supportsNormalization(UUID_NORMALIZER_TEST_UUID))->toBeFalse()
        ->and($normalizer->supportsNormalization(new stdClass))->toBeFalse()
        ->and($normalizer->supportsNormalization(null))->toBeFalse();
});

it('accepts UuidInterface and its implementations for denormalization and nothing else', function () {
    // is_a(..., allow_string: true) means concrete Ramsey classes match too;
    // a VO that merely *wraps* a uuid (TokenId) must not, or it would be
    // rebuilt as a bare Uuid and lose its type.
    $normalizer = new UuidNormalizer;

    expect($normalizer->supportsDenormalization(UUID_NORMALIZER_TEST_UUID, UuidInterface::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization(UUID_NORMALIZER_TEST_UUID, Uuid::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization(UUID_NORMALIZER_TEST_UUID, TokenId::class))->toBeFalse()
        ->and($normalizer->supportsDenormalization(UUID_NORMALIZER_TEST_UUID, stdClass::class))->toBeFalse();
});

it('advertises UuidInterface as its supported type regardless of format', function () {
    // Symfony caches normalizer lookups off this map; an entry naming a concrete
    // class instead of the interface would silently disable the cache hit.
    expect((new UuidNormalizer)->getSupportedTypes(null))->toBe([UuidInterface::class => true])
        ->and((new UuidNormalizer)->getSupportedTypes('json'))->toBe([UuidInterface::class => true]);
});

it('rejects a string that is not a uuid', function () {
    expect(fn () => (new UuidNormalizer)->denormalize('not-a-uuid', UuidInterface::class))
        ->toThrow(InvalidArgumentException::class);
});
