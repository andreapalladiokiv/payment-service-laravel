<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Job;

use RuntimeException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob as SpatieProcessWebhookJob;
use Techork\PaymentService\Laravel\Webhook\Enum\WebhookCallStatus;
use Techork\PaymentService\Laravel\Webhook\Model\WebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Throwable;

/**
 * Single queued job for every incoming webhook, regardless of kind. Hands the
 * stored {@see WebhookCall} to {@see WebhookRouter::dispatch()} which re-parses
 * the payload, resolves the handler for `(kind, event-type)`, and invokes it.
 *
 * `Delay` is signalled by throwing — Laravel's worker then applies the
 * supervisor's `backoff` config automatically when it releases the job back
 * onto the queue, so the controlled retry path and the unexpected-error
 * path use the same per-attempt schedule with no extra wiring.
 *
 * @property WebhookCall $webhookCall
 */
class ProcessWebhookJob extends SpatieProcessWebhookJob
{
    public function handle(WebhookRouter $router): void
    {
        if ($this->webhookCall->status !== WebhookCallStatus::Pending) {
            return;
        }

        $gatewayId = $this->webhookCall->gateway_id;

        if ($gatewayId === null) {
            // Terminal, not a Delay: the column is nullable and a call stored without a
            // gateway can never be routed to one, so retrying would only spin until the
            // queue gave up and then record the wrong reason.
            $this->webhookCall->markSkipped('Stored without a gateway id');

            return;
        }

        $stored = new StoredWebhookCall(
            kind: $this->webhookCall->name,
            gatewayId: $gatewayId,
            payload: $this->webhookCall->payload ?? [],
        );

        match ($router->dispatch($stored)) {
            HandlerOutcome::Processed => $this->webhookCall->markProcessed(),
            HandlerOutcome::Skipped => $this->webhookCall->markSkipped('Handler reported skip'),
            HandlerOutcome::Delay => throw new RuntimeException('Webhook handler requested retry'),
        };
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            $this->webhookCall->markFailed($exception);
        }
    }
}
