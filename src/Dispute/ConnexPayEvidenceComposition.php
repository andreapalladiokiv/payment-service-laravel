<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Dispute;

use InvalidArgumentException;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceFormat;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceItem;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidencePackage;
use Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceType;

/**
 * What an operator uploads to the ConnexPay portal for one case: which evidence items, in what
 * order, as one file, in what format.
 *
 * ## What this is, and what it deliberately is not
 *
 * It is the **composition** and not the encoder. Nothing in this tree produces a PDF — no
 * `composer.json` declares `dompdf`, `mpdf` or `tcpdf`, and adding one is not a decision this task
 * may take: a package's `require` block is real, the packages are split and published by
 * `bin/split.sh`, and a dependency added there ships to every consumer of that gateway. So this
 * class answers the parts that are ours — the ordered list of items, the single format they are
 * rendered into, and the print requirements the portal states — and hands them to whoever renders
 * the file. **It never returns bytes and never fabricates a `%PDF` header**: a file nobody rendered
 * is worse than no file, because the operator cannot tell it from one that is merely wrong.
 *
 * ## Why it is here rather than in the ConnexPay package
 *
 * The evidence vocabulary is the domain's ({@see EvidencePackage}, {@see EvidenceItem},
 * {@see EvidenceType}) and a provider package may not name it (§0.4, and the arch suite enforces
 * it), while F9's file list puts the builder under `src/ConnexPay/src/Dispute/`. Same resolution as
 * the deep link next door: the piece that speaks the domain's vocabulary lives on the side that is
 * allowed to, and the deviation is reported rather than worked around.
 *
 * ## The order: the transaction's own facts, then the merchant's documents
 *
 * Two groups, and the split is not invented here — it is {@see EvidenceType::isSystemSupplied()},
 * which the domain already owns. The system-supplied facts come first: the descriptor, the AVS/CVV
 * result, the 3DS outcome and the liability shift, the buyer's IP, the authorization timestamp and
 * the card's history are *who, when and how* the payment was taken, and they are the frame the rest
 * of the file is read in. The project's documents come second, because they answer the specific
 * claim the reason code puts in issue — the delivery note, the correspondence, the terms.
 *
 * **Within a group the package's own order is kept**, and that is deliberate: the package's items
 * arrive in the order the facts were assembled, which is a stronger statement about them than
 * anything this class could impose, and a sort by enum name would be an arbitrary order dressed up
 * as a rule. The order is stable, so the same package always composes to the same file.
 *
 * ## The format: one file, and the two cases where nothing needs rendering
 *
 * The portal takes PDF or JPEG, and this composition yields exactly one of the two
 * ({@see EvidenceFormat} has no third file format to yield). A composition of more than one item
 * must be a PDF — it is the only container of pages — and so must a single text item, which has to
 * be set in type before anybody can read it.
 *
 * A composition of **exactly one item that already is a file** is the exception, and it is worth
 * stating: it is forwarded in its own format, because rendering a JPEG into a PDF would re-encode a
 * document for no reason and degrade a photograph of a signed receipt on the way. That is the whole
 * of the rule — everything else is a PDF.
 *
 * ## The print requirements, kept where the encoder can find them
 *
 * "Legible, black-and-white, portrait" are the portal's own rendering requirements. They are stated
 * once here, on {@see self::printRequirements()}, rather than in a docblock on a class that does not
 * exist yet — the encoder's author is the reader who needs them, and a requirement that lives only
 * in prose is a requirement nobody implements. Note what is *not* here: a minimum type size. No
 * document states a number, and inventing one would be this project's guess presented as ConnexPay's
 * rule.
 */
final readonly class ConnexPayEvidenceComposition
{
    /** @var list<EvidenceItem> */
    private array $pages;

    /**
     * @param list<EvidenceItem> $pages
     */
    private function __construct(
        private EvidencePackage $package,
        array $pages,
        private EvidenceFormat $format,
    ) {
        $this->pages = $pages;
    }

    /**
     * The composition of one package.
     *
     * @throws InvalidArgumentException when the package holds nothing — see below
     */
    public static function of(EvidencePackage $package): self
    {
        $pages = self::ordered($package->items());

        // A file with no pages is not a submission, and describing one would hand an operator an
        // empty document at the end of a response window. What is *missing* is the requirement
        // table's question ({@see \Techork\PaymentService\Domain\Dispute\ValueObject\EvidenceRequirements::missingFrom()}),
        // asked before anything is composed; this refuses the composition itself.
        $pages !== [] || throw new InvalidArgumentException(sprintf(
            'Nothing is composed for a response on %s reason code "%s": the package holds no '
            .'evidence items, so there is no file to describe. Check what the case is still missing '
            .'before composing.',
            $package->cardBrand->value,
            $package->reasonCode,
        ));

        return new self($package, $pages, self::formatOf($pages));
    }

    /** The package this composition was built from, for the caller that files it afterwards. */
    public function package(): EvidencePackage
    {
        return $this->package;
    }

    /**
     * The items, in the order they are rendered into the one file.
     *
     * @return list<EvidenceItem>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /** The facts this file answers with, in render order. */
    public function types(): array
    {
        return array_map(static fn (EvidenceItem $item): EvidenceType => $item->type, $this->pages);
    }

    /** The format of the one file an operator uploads. */
    public function format(): EvidenceFormat
    {
        return $this->format;
    }

    /**
     * That file's media type, as the portal's upload expects it.
     *
     * Total by construction: {@see self::format()} is never `text` — a composition's file format is
     * one of the two the portal accepts, and text becomes a PDF — so the `Text` arm is unreachable
     * and says so rather than answering a type no upload could use.
     */
    public function mediaType(): string
    {
        return match ($this->format) {
            EvidenceFormat::Pdf => 'application/pdf',
            EvidenceFormat::Jpeg => 'image/jpeg',
            EvidenceFormat::Text => throw new InvalidArgumentException(
                'A composition is a file, and text is not one of the formats the portal takes. '
                .'Reaching this means the format rule was changed without the list of formats the '
                .'portal accepts.',
            ),
        };
    }

    /**
     * What the rendered file must be, as the portal states it.
     *
     * @return array{orientation: string, monochrome: bool}
     */
    public static function printRequirements(): array
    {
        return [
            // Portrait, because the portal states it and because a landscape page of a
            // photographed document is read sideways by a reviewer who will not rotate it.
            'orientation' => 'portrait',
            // Black and white: the portal's own requirement, and the reason a colour scan should
            // be converted rather than forwarded as-is.
            'monochrome' => true,
        ];
    }

    /**
     * The items in render order — see the class docblock for why the group order and not an
     * alphabetical one.
     *
     * @param  list<EvidenceItem>  $items
     *
     * @return list<EvidenceItem>
     */
    private static function ordered(array $items): array
    {
        $system = [];
        $project = [];

        foreach ($items as $item) {
            if ($item->type->isSystemSupplied()) {
                $system[] = $item;
            } else {
                $project[] = $item;
            }
        }

        return [...$system, ...$project];
    }

    /**
     * @param  list<EvidenceItem>  $pages  never empty
     */
    private static function formatOf(array $pages): EvidenceFormat
    {
        $only = count($pages) === 1 ? $pages[0] : null;

        return $only !== null && $only->format->isFile() ? $only->format : EvidenceFormat::Pdf;
    }
}
