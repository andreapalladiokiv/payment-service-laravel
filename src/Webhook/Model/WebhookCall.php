<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Model;

use Override;
use Techork\PaymentService\Laravel\Webhook\Enum\WebhookCallStatus;
use Techork\PaymentService\Laravel\Webhook\Profile\IdempotencyProfile;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\WebhookClient\Models\WebhookCall as SpatieWebhookCall;
use Spatie\WebhookClient\WebhookConfig;
use Techork\PaymentService\Laravel\Casts\UuidValueObjectCast;
use Techork\PaymentService\Laravel\Models\Gateway;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Throwable;

/**
 * Storage-level webhook call record. Internal to the Laravel machinery —
 * gateway-specific code (handlers, validators) never touches this model.
 *
 * `name` is repurposed to hold the resolved gateway *kind* (e.g. `stripe`,
 * `nuvei`) rather than the spatie config entry name. With one shared config
 * entry the config name is always the same; the kind is the interesting
 * dimension for dedup + routing.
 *
 * @property string $name
 * @property GatewayId|null $gateway_id
 * @property string|null $external_id
 * @property WebhookCallStatus $status
 * @property Carbon|null $processed_at
 */
class WebhookCall extends SpatieWebhookCall
{
    use HasUuids;

    /**
     * Request attribute key that the signature-validator bridge populates with
     * machinery-internal metadata (gateway_id, kind, external_id). Consumed by
     * {@see self::storeWebhook()} and {@see IdempotencyProfile}.
     */
    public const string REQUEST_META_ATTRIBUTE = 'webhook_meta';

    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
        'exception' => 'array',
        'status' => WebhookCallStatus::class,
        'processed_at' => 'datetime',
        'gateway_id' => UuidValueObjectCast::class.':'.GatewayId::class,
    ];

    /**
     * Protected on purpose. Laravel routes a scope called statically through
     * `__callStatic`, which PHP only reaches for an INACCESSIBLE method — a public
     * `pending()` makes `WebhookCall::pending()` an `Error: Non-static method cannot be
     * called statically` instead. Nothing called it that way yet, which is why it went
     * unnoticed.
     */
    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', WebhookCallStatus::Pending);
    }

    /**
     * @return BelongsTo<Gateway, self>
     */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function markProcessed(): void
    {
        $this->status = WebhookCallStatus::Processed;
        $this->processed_at = now();
        $this->save();
    }

    public function markSkipped(?string $reason = null): void
    {
        $this->status = WebhookCallStatus::Skipped;
        $this->processed_at = now();
        if ($reason !== null) {
            $this->exception = ['message' => $reason];
        }
        $this->save();
    }

    public function markFailed(Throwable $exception): void
    {
        $this->status = WebhookCallStatus::Failed;
        $this->processed_at = now();
        $this->exception = [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ];
        $this->save();
    }

    #[Override]
    public static function storeWebhook(WebhookConfig $config, Request $request): self
    {
        $meta = $request->attributes->get(self::REQUEST_META_ATTRIBUTE, []);

        return self::query()->create([
            'name' => $meta['kind'] ?? $config->name,
            'gateway_id' => $meta['gateway_id'] ?? null,
            'external_id' => $meta['external_id'] ?? null,
            'status' => WebhookCallStatus::Pending,
            'url' => $request->fullUrl(),
            'headers' => self::headersToStore($config, $request),
            'payload' => self::buildPayloadFromRequest($request),
        ]);
    }
}
