<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\EncryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Address;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Laravel\Serializer\PayloadSerializerFactory;
use Techork\PaymentService\Tests\Support\EventStreamPiiStore;

/**
 * The CVC must not survive the event stream.
 *
 * PCI DSS 3.3.1 forbids retaining Sensitive Authentication Data after authorization, and unlike
 * the PAN it allows no exception for encryption — a CVC that can be decrypted is a CVC that was
 * retained. `Cvc::__serialize()` already enforces this for the saga path, where the subject is
 * round-tripped through native `serialize()`; its docblock states the reasoning and the
 * consequence ("a CVC-less subject, which is what PCI requires — no SAD-backed retries").
 *
 * The event stream is the second path, and it did not enforce it. `SymfonyPayloadSerializer`
 * normalizes the event **object**, so the stored shape is the property shape that
 * {@see \Symfony\Component\Serializer\Normalizer\PropertyNormalizer} produces by walking the
 * graph — and `PropertyNormalizer` reads `Cvc::$data`, which holds the ciphertext. The object's
 * own `jsonSerialize()` never runs, because `PayloadSerializerFactory` leaves
 * `JsonSerializableNormalizer` out of the chain on purpose (its comment explains: it would
 * collapse the value objects to scalars and break symmetry with the property-based denormalize
 * path). So the guarantee the VO implements was real but unconsulted.
 *
 * These tests pin the stored shape as well as the round trip. The shape is the contract: rows
 * already written follow it, and a payload assertion is the only place a future change to the
 * private state of `Cvc` shows up before a replay does.
 */
function cvcNormalizerEncrypter(): EncryptInterface
{
    return new class implements EncryptInterface
    {
        public function encrypt(string $data): string
        {
            return 'enc:'.base64_encode($data);
        }
    };
}

function cvcNormalizerDecrypter(): DecryptInterface
{
    return new class implements DecryptInterface
    {
        public function decrypt(string $data): string
        {
            return base64_decode(substr($data, 4), true) ?: '';
        }
    };
}

/**
 * The ciphertext the encrypter above produces for the test CVC, so an assertion can name the
 * exact bytes that must not be in the payload rather than a shape that merely looks safe.
 */
function cvcNormalizerCiphertext(string $cvc = '737'): string
{
    return 'enc:'.base64_encode($cvc);
}

/**
 * A card carrying a real encrypted CVC and a real encrypted PAN, which is what the request
 * layer builds (`Number::fromNumber()` / `Cvc::fromCvc()` on the submitted card) and therefore
 * what the event stream is actually handed.
 */
function cvcNormalizerCard(string $pan = '4242424242424242', string $cvc = '737'): CreditCard
{
    return new CreditCard(
        Number::fromNumber($pan, cvcNormalizerEncrypter()),
        Expiration::fromMonthAndYear(11, 2031),
        new Holder('JOHN Q PUBLIC'),
        Cvc::fromCvc($cvc, cvcNormalizerEncrypter()),
        new Address(
            city: 'Anchorage',
            country: new Country('US'),
            postalCode: '99501',
            line: '1 Main St',
        ),
    );
}

it('keeps the encrypted CVC out of the stored payload', function () {
    // The defect, stated as one assertion. Everything else here is a consequence of it or a
    // guard against fixing it too broadly.
    $payload = PayloadSerializerFactory::make(new EventStreamPiiStore)->normalize(cvcNormalizerCard());

    expect(json_encode($payload))->not->toContain(cvcNormalizerCiphertext());
});

it('stores the cvc slot as an empty shape rather than a ciphertext', function () {
    // The key stays and carries nothing. Dropping the key entirely would be the other way to
    // pass the test above, and it is not what the stored contract should say: the slot exists
    // on the card, so it exists in the payload, empty.
    $payload = PayloadSerializerFactory::make(new EventStreamPiiStore)->normalize(cvcNormalizerCard());

    expect($payload['cvc'])->toBe([]);
});

it('replays a card whose CVC is gone', function () {
    $serializer = PayloadSerializerFactory::make(new EventStreamPiiStore);

    $rebuilt = $serializer->denormalize($serializer->normalize(cvcNormalizerCard()), PaymentInstrument::class);

    expect($rebuilt)->toBeInstanceOf(CreditCard::class)
        ->and($rebuilt->cvc->getCvc(cvcNormalizerDecrypter()))->toBeNull();
});

it('replays everything else on the card intact', function () {
    // Guards the blast radius. A card stripped of its CVC still has to be a usable card: the
    // last four, the brand, the expiry, the cardholder and the AVS address all have to survive,
    // or the fix has broken replay instead of securing it.
    //
    // Asserted field by field rather than with `toEqual($original)`, because the whole point is
    // that the rebuilt card is *not* equal to the original: the original holds a CVC and the
    // rebuilt one does not.
    $serializer = PayloadSerializerFactory::make(new EventStreamPiiStore);

    $rebuilt = $serializer->denormalize($serializer->normalize(cvcNormalizerCard()), PaymentInstrument::class);

    expect($rebuilt)->toBeInstanceOf(CreditCard::class)
        ->and($rebuilt->number->first6)->toBe('424242')
        ->and($rebuilt->number->last4)->toBe('4242')
        ->and($rebuilt->number->brand)->toBe(CardBrand::Visa)
        ->and($rebuilt->expiration->format('my'))->toBe('1131')
        ->and((string) $rebuilt->holder)->toBe('JOHN Q PUBLIC')
        ->and($rebuilt->address?->city)->toBe('Anchorage')
        ->and($rebuilt->address?->postalCode)->toBe('99501');
});

it('still reads a cvc slot written in the shape the reflection normalizer produced', function () {
    // Backward compatibility, and the reason it matters: rows already in `stored_events` carry
    // `{"data": "…"}`. Making them fail to denormalize would strand every event written before
    // this change — so the old shape has to keep resolving into a card.
    //
    // The CVC itself is discarded on the way in, which is deliberate rather than incidental:
    // the bytes are already in the database and this change cannot un-store them, but nothing
    // reads them back out again. That is the same outcome a saga round trip has always had.
    $payload = PayloadSerializerFactory::make(new EventStreamPiiStore)->normalize(cvcNormalizerCard());
    $payload['cvc'] = ['data' => cvcNormalizerCiphertext()];

    $rebuilt = PayloadSerializerFactory::make(new EventStreamPiiStore)
        ->denormalize($payload, PaymentInstrument::class);

    expect($rebuilt)->toBeInstanceOf(CreditCard::class)
        ->and($rebuilt->number->last4)->toBe('4242')
        ->and($rebuilt->cvc->getCvc(cvcNormalizerDecrypter()))->toBeNull();
});

it('keeps the encrypted PAN, which is not sensitive authentication data', function () {
    // A characterization test, and the record of a decision rather than an oversight.
    //
    // The PAN is not SAD, and PCI DSS 3.4 permits storing it as long as it is rendered
    // unreadable — which the ciphertext here is. It also has to survive: `Number` deliberately
    // has no `__serialize()` hook, unlike `Cvc`, because `ConnexPay\ReturnRetry` reads
    // `CardNumber` on the refund-retry path. Dropping it would break that, and would be a
    // change to make on purpose rather than as a side effect of this one.
    //
    // What is *not* settled by this test is key management: the PAN is encrypted under the
    // application-wide `APP_KEY`, shared by every tenant. That is the part of PCI 3.4 this
    // repository cannot demonstrate on its own.
    $payload = PayloadSerializerFactory::make(new EventStreamPiiStore)->normalize(cvcNormalizerCard());

    expect($payload['number']['number'])->toBe('enc:'.base64_encode('4242424242424242'));
});
