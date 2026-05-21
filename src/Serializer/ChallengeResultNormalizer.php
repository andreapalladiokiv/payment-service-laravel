<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Serializer;

use InvalidArgumentException;
use Override;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Techork\PaymentService\Common\Contract\ChallengeResult;
use Techork\PaymentService\Common\Contract\ChallengeResultVisitor;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;

/**
 * `ChallengeResult` interface counterpart to {@see ChallengeNormalizer}.
 *
 * @implements ChallengeResultVisitor<array<string, mixed>>
 */
final class ChallengeResultNormalizer implements ChallengeResultVisitor, DenormalizerInterface, NormalizerInterface
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
        return $data instanceof ChallengeResult;
    }

    #[Override]
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): ChallengeResult
    {
        $concreteType = match ($data['type'] ?? null) {
            self::TYPE_THREEDS => ThreeDSResult::class,
            self::TYPE_REDIRECT => RedirectResult::class,
            default => throw new InvalidArgumentException(\sprintf(
                'Cannot denormalize ChallengeResult: missing or unknown "type" key (%s).',
                var_export($data['type'] ?? null, true),
            )),
        };

        return $this->delegate->denormalize($data, $concreteType, $format, $context);
    }

    #[Override]
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, ChallengeResult::class, true);
    }

    #[Override]
    public function getSupportedTypes(?string $format): array
    {
        return [ChallengeResult::class => true];
    }

    #[Override]
    public function visitThreeDS(ThreeDSResult $result): array
    {
        return $this->serialize($result, self::TYPE_THREEDS);
    }

    #[Override]
    public function visitRedirect(RedirectResult $result): array
    {
        return $this->serialize($result, self::TYPE_REDIRECT);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ChallengeResult $result, string $type): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->delegate->normalize($result, $this->format, $this->context);
        $data['type'] = $type;

        return $data;
    }
}
