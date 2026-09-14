<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceFormat;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;
use Techork\PaymentService\Laravel\Dispute\ConnexPayEvidenceComposition;

/**
 * What an operator uploads for one case: which items, in what order, as one file, in what format.
 *
 * ## What this file asserts, and the one thing it must not
 *
 * It asserts the composition and nothing about bytes. There is no PDF encoder in this repository and
 * this task may not add one — a package's `require` block is real, the packages are split and
 * published, and a dependency added here ships to every consumer of `src/Laravel/`. So the class
 * under test never returns a `%PDF` header or any other rendered content, and a test that asserted
 * one would be the thing that forced a stub encoder into existence. **The encoder is an open
 * decision for a human**, and {@see ConnexPayEvidenceComposition::printRequirements()} is where the
 * portal's own rendering requirements are kept for whoever makes it.
 *
 * What is left to assert here is the part that is ours: the order, and the format rule. Both are
 * decisions rather than transcriptions, so both are pinned case by case below.
 *
 * Helpers are prefixed `connexPayPackage…`: Pest helpers are global for the whole suite.
 */

/** An item of prose — the shape that has to be set in type before anybody can read it. */
function connexPayPackageText(EvidenceType $type, string $content = 'the merchant states the service was rendered'): EvidenceItem
{
    return new EvidenceItem($type, $content, EvidenceFormat::Text);
}

/** An item that already is a file, base64-encoded as the value object requires of one. */
function connexPayPackageFile(EvidenceType $type, EvidenceFormat $format, string $bytes = '%PDF-1.4 the signed delivery note'): EvidenceItem
{
    return new EvidenceItem($type, base64_encode($bytes), $format);
}

/** The names of a composition's pages, in render order — what the order assertions are about. */
function connexPayPackageOrder(ConnexPayEvidenceComposition $composition): array
{
    return array_map(static fn (EvidenceType $type): string => $type->value, $composition->types());
}

// ──────────────────────────────────────────────
//  the order: the transaction's own facts, then the project's documents
// ──────────────────────────────────────────────

/**
 * The one rule about order, over a package whose items arrive shuffled: every system-supplied fact
 * precedes every document the project produced, and **within each group the package's own order is
 * kept**. The second half is the assertion that stops this being an alphabetical sort dressed up as
 * a rule — a sort would answer the same question differently the next time an enum case is added.
 */
it('puts the system-supplied facts first and keeps the package order inside each group', function () {
    $package = new EvidencePackage(CardBrand::Visa, '13.1', [
        // Arrives last in the package, renders first: a project document.
        connexPayPackageText(EvidenceType::CustomerCorrespondence),
        // Two system facts, in this order, which is the order they must render in.
        connexPayPackageText(EvidenceType::BuyerIpAddress, '203.0.113.7'),
        connexPayPackageText(EvidenceType::StatementDescriptor, 'ACME*SUBSCRIPTION'),
        connexPayPackageText(EvidenceType::CancellationPolicy),
    ]);

    expect(connexPayPackageOrder(ConnexPayEvidenceComposition::of($package)))->toBe([
        EvidenceType::BuyerIpAddress->value,
        EvidenceType::StatementDescriptor->value,
        EvidenceType::CustomerCorrespondence->value,
        EvidenceType::CancellationPolicy->value,
    ]);
});

/** The composition is of the package it was built from, which is what the caller files afterwards. */
it('carries the package it composed, unaltered', function () {
    $package = new EvidencePackage(CardBrand::Visa, '10.4', [connexPayPackageFile(EvidenceType::ProofOfDeliveryOrService, EvidenceFormat::Pdf)]);

    $composition = ConnexPayEvidenceComposition::of($package);

    expect($composition->package())->toBe($package)
        ->and($composition->pages())->toBe($package->items());
});

// ──────────────────────────────────────────────
//  the format: one file, and the two cases where nothing needs rendering
// ──────────────────────────────────────────────

/**
 * More than one item must be a PDF — it is the only container of pages there is, and the portal
 * takes two formats. A text item that shares the file with anything else has to be set in type.
 */
it('composes more than one item into a PDF', function () {
    $composition = ConnexPayEvidenceComposition::of(new EvidencePackage(CardBrand::Visa, '13.1', [
        connexPayPackageText(EvidenceType::StatementDescriptor, 'ACME*SUBSCRIPTION'),
        connexPayPackageFile(EvidenceType::ProofOfDeliveryOrService, EvidenceFormat::Jpeg, 'JPEGBYTES'),
    ]));

    expect($composition->format())->toBe(EvidenceFormat::Pdf)
        ->and($composition->mediaType())->toBe('application/pdf');
});

/**
 * The exception, and the reason it exists: one item that already **is** a file is forwarded in its
 * own format. Rendering a photograph of a signed receipt into a PDF re-encodes a document for no
 * reason and degrades it on the way — and the portal reads a JPEG directly.
 */
it('forwards a single file item in its own format', function (EvidenceFormat $format, string $mediaType) {
    $composition = ConnexPayEvidenceComposition::of(new EvidencePackage(CardBrand::Visa, '10.4', [
        connexPayPackageFile(EvidenceType::ProofOfDeliveryOrService, $format),
    ]));

    expect($composition->format())->toBe($format)
        ->and($composition->mediaType())->toBe($mediaType);
})->with([
    'a PDF' => [EvidenceFormat::Pdf, 'application/pdf'],
    'a JPEG' => [EvidenceFormat::Jpeg, 'image/jpeg'],
]);

/** A single text item is a PDF too: prose has to be set in type before anybody can upload it. */
it('composes a single text item into a PDF', function () {
    $composition = ConnexPayEvidenceComposition::of(new EvidencePackage(CardBrand::Visa, '13.1', [
        connexPayPackageText(EvidenceType::StatementDescriptor, 'ACME*SUBSCRIPTION'),
    ]));

    expect($composition->format())->toBe(EvidenceFormat::Pdf)
        ->and($composition->mediaType())->toBe('application/pdf');
});

// ──────────────────────────────────────────────
//  the empty package, refused rather than described
// ──────────────────────────────────────────────

/**
 * A composition with no pages is not a submission, and describing one would hand an operator an
 * empty document at the end of a response window. What is *missing* is the requirements table's
 * question, asked before anything is composed; this refuses the composition itself, and the message
 * says which pair it was composing for.
 */
it('refuses an empty package, naming the pair it was composing for', function () {
    expect(fn () => ConnexPayEvidenceComposition::of(new EvidencePackage(CardBrand::Visa, '10.4')))
        ->toThrow(InvalidArgumentException::class, 'visa reason code "10.4"');
});

// ──────────────────────────────────────────────
//  the print requirements, kept where the encoder's author will find them
// ──────────────────────────────────────────────

/**
 * The portal's own rendering requirements, as a value the encoder can read rather than as prose in
 * a docblock nobody implements. What is asserted here is exactly what is stated and no more: a
 * minimum type size is deliberately absent, because no document states a number and inventing one
 * would present this project's guess as ConnexPay's rule.
 */
it('states the portal rendering requirements and invents nothing beyond them', function () {
    expect(ConnexPayEvidenceComposition::printRequirements())
        ->toBe(['orientation' => 'portrait', 'monochrome' => true]);
});
