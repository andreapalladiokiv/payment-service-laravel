<?php

declare(strict_types=1);

use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Mapping\Loader\LoaderChain;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Laravel\Serializer\PiiAttributeLoader;
use Techork\PaymentService\Laravel\Serializer\PiiAwareObjectNormalizer;
use Techork\PaymentService\Laravel\Shredding\PiiStore;

final class TestStubInMemoryStore implements PiiStore
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

    public function forget(string $hash): void
    {
        unset($this->byHash[$hash]);
    }
}

final readonly class TestEventScalarPii
{
    public function __construct(
        #[Pii(ShreddingStubs::EMAIL)] public string $email,
        public string $city,
    ) {}
}

final readonly class TestEventNullablePii
{
    public function __construct(
        #[Pii(ShreddingStubs::EMAIL)] public ?string $email,
    ) {}
}

final readonly class TestEventStringableVo implements JsonSerializable, Stringable
{
    public function __construct(public string $value) {}
    public function __toString(): string { return $this->value; }
    public function jsonSerialize(): string { return $this->value; }
}

final readonly class TestEventWithStringableVo
{
    public function __construct(
        public TestEventStringableVo $email,
        public string $city,
    ) {}
}

final readonly class TestEventObjectPii
{
    public function __construct(
        #[Pii(ShreddingStubs::EMAIL)] public TestEventStringableVo $email,
        public string $city,
    ) {}
}

final readonly class TestEventNestedPii
{
    public function __construct(
        public TestEventScalarPii $billing,
        public string $orderId,
    ) {}
}

final readonly class TestEventMixed
{
    public function __construct(
        #[Pii(ShreddingStubs::NAME)] public string $name,
        public int $amount,
        public TestEventScalarPii $billing,
        public string $note,
    ) {}
}

final readonly class TestEventArrayPii
{
    public function __construct(
        /** @var array<string, mixed> */
        #[Pii(['masked' => true])] public array $metadata,
    ) {}
}

final readonly class TestEventIntPii
{
    public function __construct(
        #[Pii(0)] public int $taxId,
    ) {}
}

enum TestRedactedEnum: string
{
    case Email = 'redacted@redacted.invalid';
    case Phone = '+12025550100';
}

final readonly class TestEventEnumStub
{
    public function __construct(
        #[Pii(TestRedactedEnum::Email)] public TestRedactedEnum $contact,
    ) {}
}

final readonly class TestStubObject
{
    public function __construct(public string $label, public int $code) {}
}

final readonly class TestEventObjectStub
{
    public function __construct(
        // arbitrary object literal — works since PHP 8.1 (new-in-initializers in attributes)
        #[Pii(new TestStubObject('redacted', 0))] public TestStubObject $metadata,
    ) {}
}

final readonly class TestEventObjectPropertyStringStub
{
    public function __construct(
        #[Pii('redacted@redacted.invalid')] public TestEventStringableVo $email,
    ) {}
}

function makeSerializer(PiiStore $store, bool $piiFirst = false): Serializer
{
    $cmf = new ClassMetadataFactory(
        new LoaderChain([
            new AttributeLoader,
            new PiiAttributeLoader,
        ]),
    );

    $piiNormalizer = new PiiAwareObjectNormalizer(
        new ObjectNormalizer(classMetadataFactory: $cmf),
        $store,
        $cmf,
    );
    $base = [
        new BackedEnumNormalizer,
        new DateTimeNormalizer,
        new JsonSerializableNormalizer,
        new ArrayDenormalizer,
    ];

    $normalizers = $piiFirst
        ? [$piiNormalizer, ...$base]
        : [...$base, $piiNormalizer];

    return new Serializer($normalizers);
}

// ─────────────────────────────────────────────────────────
//  Basic PII flows
// ─────────────────────────────────────────────────────────

it('stores PII scalar field as hash reference on normalize', function () {
    $store = new TestStubInMemoryStore;
    $payload = makeSerializer($store)->normalize(new TestEventScalarPii('john@example.com', 'NYC'));

    expect($payload['email'])->toBe(hash('sha256', 'john@example.com'))
        ->and($payload['city'])->toBe('NYC');

    expect($store->byHash)->toHaveKey(hash('sha256', 'john@example.com'));
});

it('unwraps envelope to plaintext on denormalize when key present', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventScalarPii('john@example.com', 'NYC'));

    $rebuilt = $serializer->denormalize($payload, TestEventScalarPii::class);

    expect($rebuilt)->toBeInstanceOf(TestEventScalarPii::class)
        ->and($rebuilt->email)->toBe('john@example.com')
        ->and($rebuilt->city)->toBe('NYC');
});

it('substitutes stub on denormalize when key was shredded', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventScalarPii('john@example.com', 'NYC'));

    $store->forget($payload['email']);
    $rebuilt = $serializer->denormalize($payload, TestEventScalarPii::class);

    expect($rebuilt->email)->toBe(ShreddingStubs::EMAIL)
        ->and($rebuilt->city)->toBe('NYC');
});

it('round-trips event through normalize/denormalize', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $original = new TestEventScalarPii('a@b.com', 'LA');

    $payload = $serializer->normalize($original);
    $rebuilt = $serializer->denormalize($payload, TestEventScalarPii::class);

    expect($rebuilt->email)->toBe($original->email)
        ->and($rebuilt->city)->toBe($original->city);
});

it('keeps non-PII fields untouched in payload', function () {
    $store = new TestStubInMemoryStore;
    $payload = makeSerializer($store)->normalize(new TestEventScalarPii('a@b.com', 'NYC'));

    expect($payload['city'])->toBe('NYC');
});

// ─────────────────────────────────────────────────────────
//  Edge cases
// ─────────────────────────────────────────────────────────

it('skips wrapping when PII value is null', function () {
    $store = new TestStubInMemoryStore;
    $payload = makeSerializer($store)->normalize(new TestEventNullablePii(null));

    expect($payload['email'])->toBeNull();
});

it('reuses same hash for identical plaintext (deterministic)', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    $a = $serializer->normalize(new TestEventScalarPii('same@example.com', 'A'));
    $b = $serializer->normalize(new TestEventScalarPii('same@example.com', 'B'));

    expect($a['email'])->toBe($b['email']);
});

// ─────────────────────────────────────────────────────────
//  Normalizer order matters
// ─────────────────────────────────────────────────────────

it('JsonSerializable VO is normalized via JsonSerializableNormalizer when PiiAware is last in chain', function () {
    // PiiAware последний → JsonSerializableNormalizer перехватывает Stringable VO первым → string в payload.
    $store = new TestStubInMemoryStore;
    $payload = makeSerializer($store)->normalize(
        new TestEventWithStringableVo(new TestEventStringableVo('foo'), 'NYC'),
    );

    expect($payload['email'])->toBe('foo');
});

it('JsonSerializable VO is mishandled when PiiAware is first in chain', function () {
    // PiiAware первый → перехватывает все объекты → пытается серилизовать VO через extractAttributes,
    // но у TestEventStringableVo public свойство — он засериализуется как {value: 'foo'} вместо 'foo'.
    $store = new TestStubInMemoryStore;
    $payload = makeSerializer($store, piiFirst: true)->normalize(
        new TestEventWithStringableVo(new TestEventStringableVo('foo'), 'NYC'),
    );

    expect($payload['email'])->toBeArray()
        ->and($payload['email'])->toHaveKey('value')
        ->and($payload['email']['value'])->toBe('foo');
});

// ─────────────────────────────────────────────────────────
//  Object-typed PII (Stringable VO marked with #[Pii])
// ─────────────────────────────────────────────────────────

it('rejects Stringable VO property with string stub on normalize (data corruption guard)', function () {
    // TestEventObjectPii: VO property + string stub mismatch — validation throws on first normalize.
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    expect(fn () => $serializer->normalize(
        new TestEventObjectPii(new TestEventStringableVo('john@example.com'), 'NYC'),
    ))->toThrow(LogicException::class);
});

// ─────────────────────────────────────────────────────────
//  Nested PII inside another event
// ─────────────────────────────────────────────────────────

it('normalizes nested event with PII fields recursively', function () {
    $store = new TestStubInMemoryStore;
    $payload = makeSerializer($store)->normalize(
        new TestEventNestedPii(
            billing: new TestEventScalarPii('user@example.com', 'NYC'),
            orderId: 'ord-1',
        ),
    );

    expect($payload['billing'])->toBeArray()
        ->and($payload['billing']['email'])->toBe(hash('sha256', 'user@example.com'))
        ->and($payload['billing']['city'])->toBe('NYC')
        ->and($payload['orderId'])->toBe('ord-1');
});

it('denormalizes nested event with PII recursively (key present)', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $original = new TestEventNestedPii(
        billing: new TestEventScalarPii('user@example.com', 'NYC'),
        orderId: 'ord-1',
    );
    $payload = $serializer->normalize($original);

    $rebuilt = $serializer->denormalize($payload, TestEventNestedPii::class);

    expect($rebuilt)->toBeInstanceOf(TestEventNestedPii::class)
        ->and($rebuilt->orderId)->toBe('ord-1')
        ->and($rebuilt->billing)->toBeInstanceOf(TestEventScalarPii::class)
        ->and($rebuilt->billing->email)->toBe('user@example.com')
        ->and($rebuilt->billing->city)->toBe('NYC');
});

it('denormalizes nested event with PII substituting stub when shredded', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventNestedPii(
        billing: new TestEventScalarPii('user@example.com', 'NYC'),
        orderId: 'ord-1',
    ));

    $store->forget($payload['billing']['email']);
    $rebuilt = $serializer->denormalize($payload, TestEventNestedPii::class);

    expect($rebuilt->billing->email)->toBe(ShreddingStubs::EMAIL)
        ->and($rebuilt->billing->city)->toBe('NYC')
        ->and($rebuilt->orderId)->toBe('ord-1');
});

// ─────────────────────────────────────────────────────────
//  Mixed event: scalar PII + non-PII + nested-with-PII
// ─────────────────────────────────────────────────────────

it('round-trips mixed event (scalar PII + non-PII + nested PII)', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $original = new TestEventMixed(
        name: 'John Doe',
        amount: 4200,
        billing: new TestEventScalarPii('john@example.com', 'NYC'),
        note: 'gift',
    );

    $payload = $serializer->normalize($original);
    $rebuilt = $serializer->denormalize($payload, TestEventMixed::class);

    expect($payload['name'])->toBe(hash('sha256', 'John Doe'))
        ->and($payload['amount'])->toBe(4200)
        ->and($payload['note'])->toBe('gift')
        ->and($payload['billing']['email'])->toBeString();

    expect($rebuilt)->toBeInstanceOf(TestEventMixed::class)
        ->and($rebuilt->name)->toBe('John Doe')
        ->and($rebuilt->amount)->toBe(4200)
        ->and($rebuilt->note)->toBe('gift')
        ->and($rebuilt->billing->email)->toBe('john@example.com')
        ->and($rebuilt->billing->city)->toBe('NYC');
});

it('mixed event substitutes stubs only for shredded fields', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventMixed(
        name: 'John Doe',
        amount: 4200,
        billing: new TestEventScalarPii('john@example.com', 'NYC'),
        note: 'gift',
    ));

    // shred only the nested billing email; top-level name stays intact
    $store->forget($payload['billing']['email']);

    $rebuilt = $serializer->denormalize($payload, TestEventMixed::class);

    expect($rebuilt->name)->toBe('John Doe')
        ->and($rebuilt->billing->email)->toBe(ShreddingStubs::EMAIL);
});

// ─────────────────────────────────────────────────────────
//  Non-string PII types (mixed stub: array, int)
// ─────────────────────────────────────────────────────────

it('round-trips array PII through serialize-based canonical form', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $original = new TestEventArrayPii(metadata: ['ssn' => '123-45-6789', 'dob' => '1990-01-01']);

    $payload = $serializer->normalize($original);
    expect($payload['metadata'])->toBeString();

    $rebuilt = $serializer->denormalize($payload, TestEventArrayPii::class);
    expect($rebuilt->metadata)->toBe(['ssn' => '123-45-6789', 'dob' => '1990-01-01']);
});

it('substitutes array stub when array PII is shredded', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventArrayPii(metadata: ['ssn' => '123-45-6789']));

    $store->forget($payload['metadata']);
    $rebuilt = $serializer->denormalize($payload, TestEventArrayPii::class);

    expect($rebuilt->metadata)->toBe(['masked' => true]);
});

it('round-trips int PII with int stub', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    $payload = $serializer->normalize(new TestEventIntPii(taxId: 999999999));
    expect($payload['taxId'])->toBeString();

    $rebuilt = $serializer->denormalize($payload, TestEventIntPii::class);
    expect($rebuilt->taxId)->toBe(999999999);
});

it('substitutes int stub when int PII is shredded', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventIntPii(taxId: 999999999));

    $store->forget($payload['taxId']);
    $rebuilt = $serializer->denormalize($payload, TestEventIntPii::class);

    expect($rebuilt->taxId)->toBe(0);
});

// ─────────────────────────────────────────────────────────
//  Enum stub (object in attribute — only enums are allowed)
// ─────────────────────────────────────────────────────────

it('round-trips enum PII property with enum stub', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $original = new TestEventEnumStub(contact: TestRedactedEnum::Phone);

    $payload = $serializer->normalize($original);
    expect($payload['contact'])->toBeString();

    $rebuilt = $serializer->denormalize($payload, TestEventEnumStub::class);
    expect($rebuilt->contact)->toBe(TestRedactedEnum::Phone);
});

it('substitutes enum stub when enum PII is shredded', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventEnumStub(contact: TestRedactedEnum::Phone));

    $store->forget($payload['contact']);
    $rebuilt = $serializer->denormalize($payload, TestEventEnumStub::class);

    expect($rebuilt->contact)->toBe(TestRedactedEnum::Email);
});

// ─────────────────────────────────────────────────────────
//  Arbitrary object stub via PHP 8.1 new-in-initializers
// ─────────────────────────────────────────────────────────

it('round-trips object PII property with object stub', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $original = new TestEventObjectStub(metadata: new TestStubObject('real', 42));

    $payload = $serializer->normalize($original);
    expect($payload['metadata'])->toBeString();

    $rebuilt = $serializer->denormalize($payload, TestEventObjectStub::class);
    expect($rebuilt->metadata)->toBeInstanceOf(TestStubObject::class)
        ->and($rebuilt->metadata->label)->toBe('real')
        ->and($rebuilt->metadata->code)->toBe(42);
});

it('substitutes object stub instance when shredded', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);
    $payload = $serializer->normalize(new TestEventObjectStub(metadata: new TestStubObject('real', 42)));

    $store->forget($payload['metadata']);
    $rebuilt = $serializer->denormalize($payload, TestEventObjectStub::class);

    expect($rebuilt->metadata)->toBeInstanceOf(TestStubObject::class)
        ->and($rebuilt->metadata->label)->toBe('redacted')
        ->and($rebuilt->metadata->code)->toBe(0);
});

// ─────────────────────────────────────────────────────────
//  Type mismatch — VO property + string stub
// ─────────────────────────────────────────────────────────

it('normalize fails fast when stub type does not match property declared type (data-corruption guard)', function () {
    // VO property + string stub: payload would normalize fine, but later denormalize would
    // be irrecoverable. We reject at write time so corrupted records never reach storage.
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    expect(fn () => $serializer->normalize(
        new TestEventObjectPropertyStringStub(new TestEventStringableVo('john@example.com')),
    ))->toThrow(LogicException::class);
});

it('normalize fails fast when int property has string stub', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    $cls = new class(1) {
        public function __construct(
            #[Pii('not-an-int')] public int $taxId,
        ) {}
    };

    expect(fn () => $serializer->normalize($cls))->toThrow(LogicException::class);
});

it('normalize fails fast when nullable property gets non-null stub of wrong type', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    $cls = new class('x') {
        public function __construct(
            #[Pii(42)] public ?string $email,
        ) {}
    };

    expect(fn () => $serializer->normalize($cls))->toThrow(LogicException::class);
});

it('normalize fails fast when non-nullable property has null stub', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    $cls = new class('x') {
        public function __construct(
            #[Pii(null)] public string $email,
        ) {}
    };

    expect(fn () => $serializer->normalize($cls))->toThrow(LogicException::class);
});

it('normalize accepts null stub on nullable property', function () {
    $store = new TestStubInMemoryStore;
    $serializer = makeSerializer($store);

    $cls = new class(null) {
        public function __construct(
            #[Pii(null)] public ?string $email,
        ) {}
    };

    // null PII value passes through verbatim (no envelope), and stub-validation accepted it at load time.
    $payload = $serializer->normalize($cls);
    expect($payload['email'])->toBeNull();
});
