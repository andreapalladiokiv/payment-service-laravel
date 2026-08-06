<?php

declare(strict_types=1);

use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Mapping\Loader\LoaderChain;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Laravel\Serializer\ChallengeNormalizer;
use Techork\PaymentService\Laravel\Serializer\ChallengeResultNormalizer;
use Techork\PaymentService\Laravel\Serializer\PiiAttributeLoader;
use Techork\PaymentService\Laravel\Serializer\PiiAwareObjectNormalizer;
use Techork\PaymentService\Laravel\Serializer\UuidNormalizer;
use Techork\PaymentService\Laravel\Shredding\PiiStore;

final class ChallengeResultNormalizerTestStore implements PiiStore
{
    /** @var array<string, string> */
    public array $byHash = [];

    public function store(string $plaintext): string
    {
        $hash = hash('sha256', $plaintext);
        $this->byHash[$hash] = $plaintext;

        return $hash;
    }

    public function retrieve(string $hash): ?string
    {
        return $this->byHash[$hash] ?? null;
    }
}

/**
 * The production chain from `GatewayServiceProvider`, including `ChallengeNormalizer`
 * ahead of the result normalizer. Its presence is deliberate: both normalizers write
 * the same `3ds`/`redirect` discriminators and both sit in one chain, so the tests
 * below also prove the two do not intercept each other's types.
 */
function challengeResultNormalizerSerializer(?PiiStore $store = null): Serializer
{
    $piiAware = challengeResultNormalizerPiiAware($store);

    return new Serializer([
        new UuidNormalizer,
        new BackedEnumNormalizer,
        new DateTimeNormalizer,
        new ArrayDenormalizer,
        new ChallengeNormalizer($piiAware),
        new ChallengeResultNormalizer($piiAware),
        $piiAware,
    ]);
}

function challengeResultNormalizerPiiAware(?PiiStore $store = null): PiiAwareObjectNormalizer
{
    $metadataFactory = new ClassMetadataFactory(
        new LoaderChain([new AttributeLoader, new PiiAttributeLoader]),
    );

    return new PiiAwareObjectNormalizer(
        new PropertyNormalizer(
            classMetadataFactory: $metadataFactory,
            propertyTypeExtractor: new ReflectionExtractor,
        ),
        $store ?? new ChallengeResultNormalizerTestStore,
        $metadataFactory,
    );
}

// ─────────────────────────────────────────────────────────
//  Round trip — every ChallengeResult implementation
// ─────────────────────────────────────────────────────────

it('round-trips a fully populated ThreeDSResult including all three enums', function () {
    // The liability-shift evidence a merchant forwards to the acquirer: if any of
    // status/eci/version came back as a raw string the adapter would break at the
    // point of use, long after the event was stored.
    $serializer = challengeResultNormalizerSerializer();
    $original = new ThreeDSResult(
        status: ThreeDSStatus::Successful,
        authenticationValue: 'AAABBEg0VhI0VniQEjRWAAAAAAA=',
        eci: ECICode::VisaSuccessful,
        dsTransactionId: 'ds-txn-1',
        acsTransactionId: 'acs-txn-1',
        version: ThreeDSVersion::V220,
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), ChallengeResult::class);

    expect($rebuilt)->toEqual($original)
        ->and($rebuilt->status)->toBe(ThreeDSStatus::Successful)
        ->and($rebuilt->eci)->toBe(ECICode::VisaSuccessful)
        ->and($rebuilt->version)->toBe(ThreeDSVersion::V220);
});

it('round-trips a failed ThreeDSResult whose optional evidence is absent', function () {
    // A declined authentication carries no CAVV, no ECI and often no version;
    // the nulls must survive as nulls rather than becoming empty strings.
    $serializer = challengeResultNormalizerSerializer();
    $original = new ThreeDSResult(
        status: ThreeDSStatus::NotAuthenticated,
        authenticationValue: null,
        eci: null,
        dsTransactionId: 'ds-txn-2',
        acsTransactionId: 'acs-txn-2',
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), ChallengeResult::class);

    expect($rebuilt)->toEqual($original)
        ->and($rebuilt->authenticationValue)->toBeNull()
        ->and($rebuilt->eci)->toBeNull()
        ->and($rebuilt->version)->toBeNull();
});

it('round-trips a RedirectResult', function () {
    $serializer = challengeResultNormalizerSerializer();
    $original = new RedirectResult('txn-redirect-1');

    expect($serializer->denormalize($serializer->normalize($original), ChallengeResult::class))
        ->toEqual($original);
});

// ─────────────────────────────────────────────────────────
//  Wire format
// ─────────────────────────────────────────────────────────

it('stamps the type discriminator each concrete result is stored under', function () {
    // Private constants, but written into the event stream — a compatibility
    // contract with every already-stored row.
    $serializer = challengeResultNormalizerSerializer();
    $threeDS = new ThreeDSResult(ThreeDSStatus::Successful, null, null, 'ds-1', 'acs-1');

    expect($serializer->normalize($threeDS)['type'])->toBe('3ds')
        ->and($serializer->normalize(new RedirectResult('txn-1'))['type'])->toBe('redirect');
});

it('writes enum-backed values as their scalar backing values', function () {
    // Confirms the delegate really goes through BackedEnumNormalizer rather than
    // reflecting into the enum object, which would store an unreadable shape.
    $payload = challengeResultNormalizerSerializer()->normalize(new ThreeDSResult(
        ThreeDSStatus::NotAvailable,
        'cavv',
        ECICode::MastercardAttempted,
        'ds-1',
        'acs-1',
        ThreeDSVersion::V220,
    ));

    expect($payload)->toBe([
        'status' => 'A',
        'authenticationValue' => 'cavv',
        'eci' => '01',
        'dsTransactionId' => 'ds-1',
        'acsTransactionId' => 'acs-1',
        'version' => '2.2.0',
        'type' => '3ds',
    ]);
});

it('does not treat authentication evidence as PII', function () {
    // The CAVV is sensitive but not personal data; hashing it into the shreddable
    // store would destroy liability-shift evidence on an unrelated erasure request.
    $store = new ChallengeResultNormalizerTestStore;
    challengeResultNormalizerSerializer($store)->normalize(
        new ThreeDSResult(ThreeDSStatus::Successful, 'cavv', ECICode::VisaSuccessful, 'ds-1', 'acs-1'),
    );

    expect($store->byHash)->toBe([]);
});

// ─────────────────────────────────────────────────────────
//  Denormalize failure modes
// ─────────────────────────────────────────────────────────

it('refuses to denormalize a result payload with no type key', function () {
    expect(fn () => challengeResultNormalizerSerializer()->denormalize(
        ['dsTransactionId' => 'ds-1'],
        ChallengeResult::class,
    ))->toThrow(InvalidArgumentException::class, 'Cannot denormalize ChallengeResult');
});

it('refuses to denormalize a result payload whose type is unknown', function () {
    expect(fn () => challengeResultNormalizerSerializer()->denormalize(
        ['type' => 'frictionless'],
        ChallengeResult::class,
    ))->toThrow(InvalidArgumentException::class, "missing or unknown \"type\" key ('frictionless')");
});

// ─────────────────────────────────────────────────────────
//  supports* — what decides whether we are consulted
// ─────────────────────────────────────────────────────────

it('claims normalization for challenge results only', function () {
    // A ThreeDSChallenge is the interim state, not a result; it must fall through
    // to ChallengeNormalizer even though both live under the '3ds' token.
    $normalizer = new ChallengeResultNormalizer(challengeResultNormalizerPiiAware());
    $result = new ThreeDSResult(ThreeDSStatus::Successful, null, null, 'ds-1', 'acs-1');

    expect($normalizer->supportsNormalization($result))->toBeTrue()
        ->and($normalizer->supportsNormalization(new RedirectResult('txn-1')))->toBeTrue()
        ->and($normalizer->supportsNormalization(new ThreeDSChallenge('txn-1', 'https://acs.test/step')))->toBeFalse()
        ->and($normalizer->supportsNormalization(['type' => '3ds']))->toBeFalse();
});

it('claims denormalization for the ChallengeResult interface and its implementations only', function () {
    $normalizer = new ChallengeResultNormalizer(challengeResultNormalizerPiiAware());

    expect($normalizer->supportsDenormalization([], ChallengeResult::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], ThreeDSResult::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], RedirectResult::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], Challenge::class))->toBeFalse()
        ->and($normalizer->supportsDenormalization([], stdClass::class))->toBeFalse();
});

it('advertises the ChallengeResult interface as its supported type', function () {
    expect((new ChallengeResultNormalizer(challengeResultNormalizerPiiAware()))->getSupportedTypes(null))
        ->toBe([ChallengeResult::class => true]);
});

// ─────────────────────────────────────────────────────────
//  Coexistence with ChallengeNormalizer in one chain
// ─────────────────────────────────────────────────────────

it('keeps the 3ds token disambiguated by target interface, not by the token itself', function () {
    // Both normalizers are registered together and both answer to '3ds'. The chain
    // resolves the ambiguity purely from the requested interface, so the same token
    // has to produce a challenge in one direction and a result in the other.
    $serializer = challengeResultNormalizerSerializer();

    $asChallenge = $serializer->denormalize(
        ['authenticationId' => 'txn-1', 'url' => 'https://acs.test/step', 'type' => '3ds'],
        Challenge::class,
    );
    $asResult = $serializer->denormalize(
        ['status' => 'Y', 'dsTransactionId' => 'ds-1', 'acsTransactionId' => 'acs-1', 'type' => '3ds'],
        ChallengeResult::class,
    );

    expect($asChallenge)->toBeInstanceOf(ThreeDSChallenge::class)
        ->and($asResult)->toBeInstanceOf(ThreeDSResult::class);
});

it('resolves the interface the inner normalizer cannot instantiate', function () {
    $payload = ['status' => 'Y', 'dsTransactionId' => 'ds-1', 'acsTransactionId' => 'acs-1', 'type' => '3ds'];

    expect(fn () => challengeResultNormalizerPiiAware()->denormalize($payload, ChallengeResult::class))
        ->toThrow(NotNormalizableValueException::class, 'is not instantiable');

    expect(challengeResultNormalizerSerializer()->denormalize($payload, ChallengeResult::class))
        ->toBeInstanceOf(ThreeDSResult::class);
});
