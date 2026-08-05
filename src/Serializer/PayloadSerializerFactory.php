<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Mapping\Loader\LoaderChain;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\PropertyNormalizer;
use Symfony\Component\Serializer\Serializer;
use Techork\PaymentService\Laravel\Shredding\PiiStore;

/**
 * The one place the event-stream normalizer chain is assembled.
 *
 * It used to be built inline in {@see \Techork\PaymentService\Laravel\GatewayServiceProvider},
 * and every test that needed the chain rebuilt it by hand from a copy. That copy drifted the
 * moment it mattered: {@see StateNormalizer} was added to fix events with a state in their
 * address being unreplayable, the five test files kept their own chains, and the two tests
 * that had recorded the defect went on passing — describing a serializer that no longer
 * existed. A fix nothing observes is the failure this package has been closing all along, so
 * the chain lives here and both sides ask for it.
 *
 * Order is significant, and the comments below say why for the parts where it is not obvious.
 */
final readonly class PayloadSerializerFactory
{
    public static function make(PiiStore $store): Serializer
    {
        $metadataFactory = new ClassMetadataFactory(
            new LoaderChain([new AttributeLoader, new PiiAttributeLoader]),
        );

        // `PropertyNormalizer` (not `ObjectNormalizer`) because our VOs hold their state in
        // private properties and surface it only via `jsonSerialize`/`__toString` —
        // `ObjectNormalizer` would read an empty public API and fail to round-trip them. The
        // reflection path bypasses constructors on rebuild, which is fine here: the event
        // stream only round-trips through itself.
        //
        // `JsonSerializableNormalizer` is intentionally absent: it would collapse the same VOs
        // to scalars on the way out, breaking the symmetry with the property-based
        // denormalize path.
        $piiAware = new PiiAwareObjectNormalizer(
            new PropertyNormalizer(
                classMetadataFactory: $metadataFactory,
                propertyTypeExtractor: new ReflectionExtractor,
            ),
            $store,
            $metadataFactory,
        );

        return new Serializer([
            // Value objects whose stored shape is not their property shape. Each has to
            // precede PropertyNormalizer, which would otherwise recurse into privates it
            // cannot rebuild through the constructor.
            new UuidNormalizer,
            new PhoneNumberNormalizer,
            new StateNormalizer,
            new BackedEnumNormalizer,
            new DateTimeNormalizer,
            new ArrayDenormalizer,
            // Interface-typed slots in aggregates need a concrete-class resolver before
            // `PiiAwareObjectNormalizer` can look up per-class PII metadata. Each
            // visitor-based normalizer dispatches on the concrete impl and then delegates
            // back to the PII pipeline on the resolved class.
            new PaymentInstrumentNormalizer($piiAware),
            new ChallengeNormalizer($piiAware),
            new ChallengeResultNormalizer($piiAware),
            $piiAware,
        ]);
    }
}
