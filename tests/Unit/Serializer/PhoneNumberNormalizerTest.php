<?php

declare(strict_types=1);

use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Common\ValueObject\PhoneNumber;
use Techork\PaymentService\Laravel\Serializer\PhoneNumberNormalizer;

/**
 * The property-reflection path that {@see PropertyNormalizer} would otherwise
 * take for {@see PhoneNumber}. Present in the chain to prove the dedicated
 * normalizer short-circuits it — without the short-circuit this same setup
 * throws MissingConstructorArgumentsException on denormalize.
 */
function phoneSerializer(): Serializer
{
    return new Serializer([
        new PhoneNumberNormalizer,
        new PropertyNormalizer,
    ]);
}

it('normalizes a PhoneNumber to its E164 string', function () {
    expect(phoneSerializer()->normalize(new PhoneNumber('+19074861000')))
        ->toBe('+19074861000');
});

it('round-trips a PhoneNumber through normalize/denormalize', function () {
    $serializer = phoneSerializer();

    $normalized = $serializer->normalize(new PhoneNumber('+19074861000'));
    $rebuilt = $serializer->denormalize($normalized, PhoneNumber::class);

    expect($rebuilt)->toBeInstanceOf(PhoneNumber::class)
        ->and((string) $rebuilt)->toBe('+19074861000');
});

it('denormalizes an E164 string into a PhoneNumber', function () {
    expect((string) phoneSerializer()->denormalize('+442071838750', PhoneNumber::class))
        ->toBe('+442071838750');
});

it('supports PhoneNumber for both directions and nothing else', function () {
    $normalizer = new PhoneNumberNormalizer;

    expect($normalizer->supportsNormalization(new PhoneNumber('+19074861000')))->toBeTrue()
        ->and($normalizer->supportsNormalization('+19074861000'))->toBeFalse()
        ->and($normalizer->supportsDenormalization('+19074861000', PhoneNumber::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization('+19074861000', stdClass::class))->toBeFalse();
});
