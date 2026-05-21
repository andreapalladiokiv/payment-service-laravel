<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use InvalidArgumentException;
use Override;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Common\Contract\ChallengeVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\Challenge\ThreeDSChallenge;

/**
 * Resolve `Challenge` to a concrete class on both sides of the serializer
 * round-trip so {@see PiiAwareObjectNormalizer} (or any reflection-based
 * inner normalizer) can attach per-class metadata. Symfony's normalizer
 * can't instantiate the bare interface, and without an explicit
 * discriminator a payload that says `{"type": "3ds", …}` ends up trying
 * to instantiate `Challenge` itself.
 *
 * Direction handling:
 *  - normalize: drive concrete-class selection through the matching
 *    {@see ChallengeVisitor} (same hierarchy the gateway packages use
 *    to dispatch on challenge type).
 *  - denormalize: read the `type` discriminator written on the way out
 *    and pick the concrete class.
 *
 * In both directions the actual conversion is delegated to the wrapped
 * normalizer, so the PII pipeline keeps working on the concrete class.
 *
 * @implements ChallengeVisitor<array<string, mixed>>
 */
final class ChallengeNormalizer implements ChallengeVisitor, DenormalizerInterface, NormalizerInterface
{
    private const string TYPE_THREEDS = '3ds';

    private const string TYPE_REDIRECT = 'redirect';

    private ?string $format = null;

    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(private readonly PiiAwareObjectNormalizer $delegate) {}

    #[Override]
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $this->format = $format;
        $this->context = $context;

        return $object->accept($this);
    }

    #[Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Challenge;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Challenge
    {
        $concreteType = match ($data['type'] ?? null) {
            self::TYPE_THREEDS => ThreeDSChallenge::class,
            self::TYPE_REDIRECT => RedirectChallenge::class,
            default => throw new InvalidArgumentException(\sprintf(
                'Cannot denormalize Challenge: missing or unknown "type" key (%s).',
                var_export($data['type'] ?? null, true),
            )),
        };

        return $this->delegate->denormalize($data, $concreteType, $format, $context);
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, Challenge::class, true);
    }

    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [Challenge::class => true];
    }

    #[Override]
    public function visitThreeDS(ThreeDSChallenge $challenge): array
    {
        return $this->serialize($challenge, self::TYPE_THREEDS);
    }

    #[Override]
    public function visitRedirect(RedirectChallenge $challenge): array
    {
        return $this->serialize($challenge, self::TYPE_REDIRECT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Challenge $challenge, string $type): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->delegate->normalize($challenge, $this->format, $this->context);
        $data['type'] = $type;

        return $data;
    }
}
