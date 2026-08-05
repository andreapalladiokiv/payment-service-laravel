<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Profile;

use Override;
use Techork\PaymentService\Laravel\Webhook\Bridge\SpatieSignatureValidatorAdapter;
use Techork\PaymentService\Laravel\Webhook\Model\WebhookCall;
use Illuminate\Http\Request;
use Spatie\WebhookClient\WebhookProfile\WebhookProfile;

/**
 * Idempotency gate. Rejects duplicate deliveries by checking whether this
 * (kind, external_id) pair is already stored — the unique index on
 * {@see WebhookCall} enforces the same invariant at DB level.
 *
 * Both fields come from the signature-validator bridge via request attributes
 * (see {@see SpatieSignatureValidatorAdapter}).
 */
final readonly class IdempotencyProfile implements WebhookProfile
{
    #[Override]
    public function shouldProcess(Request $request): bool
    {
        $meta = $request->attributes->get(WebhookCall::REQUEST_META_ATTRIBUTE, []);
        $kind = $meta['kind'] ?? null;
        $externalId = $meta['external_id'] ?? null;

        if ($kind === null || $externalId === null) {
            return false;
        }

        return ! WebhookCall::query()
            ->where('name', $kind)
            ->where('external_id', $externalId)
            ->exists();
    }
}
