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
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;
use Techork\PaymentService\Laravel\Serializer\ChallengeNormalizer;
use Techork\PaymentService\Laravel\Serializer\PiiAttributeLoader;
use Techork\PaymentService\Laravel\Serializer\PiiAwareObjectNormalizer;
use Techork\PaymentService\Laravel\Serializer\UuidNormalizer;
use Techork\PaymentService\Laravel\Shredding\PiiStore;

/**
 * No challenge VO carries `#[Pii]` today, but the normalizer delegates to the PII
 * pipeline unconditionally, so a store still has to be there. Recording what was
 * written also lets a test assert that nothing about a challenge is treated as PII.
 */
final class ChallengeNormalizerTestStore implements PiiStore
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
 * Rebuilds the chain `GatewayServiceProvider` registers, narrowed to the parts a
 * challenge touches. The order is the production order and is load-bearing:
 * `PiiAwareObjectNormalizer::supportsNormalization()` answers true for *any*
 * object, so it would swallow challenges — and their `type` discriminator —
 * if it came first. `$challengeFirst: false` reproduces exactly that mistake.
 */
function challengeNormalizerSerializer(?PiiStore $store = null, bool $challengeFirst = true): Serializer
{
    $metadataFactory = new ClassMetadataFactory(
        new LoaderChain([new AttributeLoader, new PiiAttributeLoader]),
    );

    $piiAware = new PiiAwareObjectNormalizer(
        new PropertyNormalizer(
            classMetadataFactory: $metadataFactory,
            propertyTypeExtractor: new ReflectionExtractor,
        ),
        $store ?? new ChallengeNormalizerTestStore,
        $metadataFactory,
    );

    $challenge = new ChallengeNormalizer($piiAware);
    $tail = $challengeFirst ? [$challenge, $piiAware] : [$piiAware, $challenge];

    return new Serializer([
        new UuidNormalizer,
        new BackedEnumNormalizer,
        new DateTimeNormalizer,
        new ArrayDenormalizer,
        ...$tail,
    ]);
}

// ─────────────────────────────────────────────────────────
//  Round trip — every Challenge implementation in the repo
// ─────────────────────────────────────────────────────────

it('round-trips a direct-MPI ThreeDSChallenge (acsUrl + creq)', function () {
    $serializer = challengeNormalizerSerializer();
    $original = new ThreeDSChallenge(
        transactionId: 'txn-3ds-1',
        acsUrl: 'https://acs.example.test/challenge',
        creq: 'eyJ0aHJlZURTU2VydmVyVHJhbnNJRCI6ImFiYyJ9',
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), Challenge::class);

    expect($rebuilt)->toEqual($original);
});

it('round-trips an SDK-mode ThreeDSChallenge (acsUrl + clientSecret, no creq)', function () {
    // The two integration shapes the VO documents differ only in which nullable
    // fields are populated; both have to survive, nulls included.
    $serializer = challengeNormalizerSerializer();
    $original = new ThreeDSChallenge(
        transactionId: 'txn-3ds-2',
        acsUrl: 'https://acs.example.test/challenge',
        clientSecret: 'pi_123_secret_456',
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), Challenge::class);

    expect($rebuilt)->toEqual($original)
        ->and($rebuilt->creq)->toBeNull();
});

it('round-trips a ThreeDSChallenge that carries nothing but a transaction id', function () {
    // Every optional field defaulted: the minimum a gateway can hand back.
    $serializer = challengeNormalizerSerializer();
    $original = new ThreeDSChallenge(transactionId: 'txn-3ds-3');

    expect($serializer->denormalize($serializer->normalize($original), Challenge::class))
        ->toEqual($original);
});

it('round-trips a RedirectChallenge including its form fields map', function () {
    // formFields is an untyped array in the VO; ArrayDenormalizer is not involved
    // for a plain string map, so this pins that it passes through verbatim rather
    // than being coerced into objects.
    $serializer = challengeNormalizerSerializer();
    $original = new RedirectChallenge(
        transactionId: 'txn-redirect-1',
        url: 'https://hpp.example.test/pay',
        formFields: ['sessionToken' => 'abc123', 'merchantId' => '42'],
    );

    $rebuilt = $serializer->denormalize($serializer->normalize($original), Challenge::class);

    expect($rebuilt)->toEqual($original)
        ->and($rebuilt->formFields)->toBe(['sessionToken' => 'abc123', 'merchantId' => '42']);
});

it('round-trips a RedirectChallenge with an empty form fields map', function () {
    // A GET-style redirect has no hidden inputs; an empty array must not be
    // confused with a missing key on the way back in.
    $serializer = challengeNormalizerSerializer();
    $original = new RedirectChallenge('txn-redirect-2', 'https://hpp.example.test/pay', []);

    expect($serializer->denormalize($serializer->normalize($original), Challenge::class))
        ->toEqual($original);
});

// ─────────────────────────────────────────────────────────
//  The discriminator itself — this is stored wire format
// ─────────────────────────────────────────────────────────

it('stamps the type discriminator each concrete challenge is stored under', function () {
    // The two tokens are private constants, but they are written into the event
    // stream, so they are a compatibility contract with every already-stored row.
    $serializer = challengeNormalizerSerializer();

    expect($serializer->normalize(new ThreeDSChallenge('txn-1'))['type'])->toBe('3ds')
        ->and($serializer->normalize(new RedirectChallenge('txn-2', 'https://x.test', []))['type'])
        ->toBe('redirect');
});

it('keeps the challenge fields alongside the discriminator', function () {
    $payload = challengeNormalizerSerializer()->normalize(
        new ThreeDSChallenge('txn-1', 'https://acs.test', 'creq-blob', 'secret'),
    );

    expect($payload)->toBe([
        'transactionId' => 'txn-1',
        'acsUrl' => 'https://acs.test',
        'creq' => 'creq-blob',
        'clientSecret' => 'secret',
        'type' => '3ds',
    ]);
});

it('treats no challenge field as PII', function () {
    // A hash in place of a field would round-trip silently while making the
    // challenge unusable once the store is shredded, so pin that the store stays
    // empty for challenges.
    $store = new ChallengeNormalizerTestStore;
    challengeNormalizerSerializer($store)->normalize(
        new RedirectChallenge('txn-1', 'https://hpp.test', ['token' => 'abc']),
    );

    expect($store->byHash)->toBe([]);
});

// ─────────────────────────────────────────────────────────
//  Denormalize failure modes
// ─────────────────────────────────────────────────────────

it('refuses to denormalize a payload with no type key', function () {
    // Without a discriminator there is no way to pick a class, and guessing would
    // produce a challenge the gateway never issued.
    expect(fn () => challengeNormalizerSerializer()->denormalize(['transactionId' => 'txn-1'], Challenge::class))
        ->toThrow(InvalidArgumentException::class, 'missing or unknown "type" key (NULL)');
});

it('refuses to denormalize a payload whose type is unknown', function () {
    expect(fn () => challengeNormalizerSerializer()->denormalize(['type' => 'otp'], Challenge::class))
        ->toThrow(InvalidArgumentException::class, "missing or unknown \"type\" key ('otp')");
});

it('names the offending value in the failure so a bad stored row can be found', function () {
    // The message is the only diagnostic available when replay hits a poisoned row.
    expect(fn () => challengeNormalizerSerializer()->denormalize(['type' => 42], Challenge::class))
        ->toThrow(InvalidArgumentException::class, 'Cannot denormalize Challenge');
});

// ─────────────────────────────────────────────────────────
//  supports* — what decides whether we are consulted
// ─────────────────────────────────────────────────────────

it('claims normalization for challenges only', function () {
    $normalizer = new ChallengeNormalizer(challengeNormalizerTestPiiAware());

    expect($normalizer->supportsNormalization(new ThreeDSChallenge('txn-1')))->toBeTrue()
        ->and($normalizer->supportsNormalization(new RedirectChallenge('txn-1', 'https://x.test', [])))->toBeTrue()
        ->and($normalizer->supportsNormalization(new Cash))->toBeFalse()
        ->and($normalizer->supportsNormalization(['type' => '3ds']))->toBeFalse()
        ->and($normalizer->supportsNormalization(null))->toBeFalse();
});

it('claims denormalization for the Challenge interface and its implementations only', function () {
    // ChallengeResult shares the '3ds'/'redirect' tokens and sits in the same chain,
    // so the two normalizers must not answer for each other's target types.
    $normalizer = new ChallengeNormalizer(challengeNormalizerTestPiiAware());

    expect($normalizer->supportsDenormalization([], Challenge::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], ThreeDSChallenge::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], RedirectChallenge::class))->toBeTrue()
        ->and($normalizer->supportsDenormalization([], ChallengeResult::class))->toBeFalse()
        ->and($normalizer->supportsDenormalization([], stdClass::class))->toBeFalse()
        ->and($normalizer->supportsDenormalization([], 'Not\\A\\Class'))->toBeFalse();
});

it('advertises the Challenge interface as its supported type', function () {
    expect((new ChallengeNormalizer(challengeNormalizerTestPiiAware()))->getSupportedTypes(null))
        ->toBe([Challenge::class => true]);
});

// ─────────────────────────────────────────────────────────
//  Chain position
// ─────────────────────────────────────────────────────────

it('loses the discriminator when the PII normalizer is placed first', function () {
    // PiiAwareObjectNormalizer supports every object and every existing type, so
    // ahead of us it wins both lookups: nothing stamps a `type` on the way out, and
    // on the way in it tries to instantiate the bare interface instead of letting us
    // resolve it. Hence the provider orders the interface resolvers before it.
    $serializer = challengeNormalizerSerializer(challengeFirst: false);
    $payload = $serializer->normalize(new ThreeDSChallenge('txn-1', 'https://acs.test'));

    expect($payload)->not->toHaveKey('type');
    expect(fn () => $serializer->denormalize($payload, Challenge::class))
        ->toThrow(NotNormalizableValueException::class);
});

it('resolves the interface that the inner normalizer cannot instantiate', function () {
    // The failure the normalizer exists to prevent: handed `Challenge::class`
    // directly, the reflection-based normalizer tries to instantiate the interface.
    $payload = ['transactionId' => 'txn-1', 'acsUrl' => 'https://acs.test', 'type' => '3ds'];

    expect(fn () => challengeNormalizerTestPiiAware()->denormalize($payload, Challenge::class))
        ->toThrow(NotNormalizableValueException::class, 'is not instantiable');

    // Same payload through the chain that includes the resolver: a usable object.
    expect(challengeNormalizerSerializer()->denormalize($payload, Challenge::class))
        ->toEqual(new ThreeDSChallenge('txn-1', 'https://acs.test'));
});

/**
 * Bare delegate for the supports-and-supported-types cases, which never serialize
 * anything and so need no working store behind them.
 */
function challengeNormalizerTestPiiAware(): PiiAwareObjectNormalizer
{
    $metadataFactory = new ClassMetadataFactory(
        new LoaderChain([new AttributeLoader, new PiiAttributeLoader]),
    );

    return new PiiAwareObjectNormalizer(
        new PropertyNormalizer(
            classMetadataFactory: $metadataFactory,
            propertyTypeExtractor: new ReflectionExtractor,
        ),
        new ChallengeNormalizerTestStore,
        $metadataFactory,
    );
}
