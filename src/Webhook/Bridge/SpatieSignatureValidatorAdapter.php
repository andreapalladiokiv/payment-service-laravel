<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Webhook\Bridge;

use Techork\PaymentService\Laravel\Webhook\Model\WebhookCall;
use Techork\PaymentService\Laravel\Webhook\Profile\IdempotencyProfile;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Drives the single spatie config entry. Hands the PSR-7 request and the
 * parsed payload to {@see WebhookRouter::identifyGateway()}; on match, stashes
 * the resolved tenant on the Laravel request so
 * {@see WebhookCall::storeWebhook()} and {@see IdempotencyProfile} can pick
 * it up without reparsing the body.
 */
final readonly class SpatieSignatureValidatorAdapter implements SignatureValidator
{
    public function __construct(
        private WebhookRouter $router,
        private PsrHttpFactory $psrFactory,
    ) {}

    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $psrRequest = $this->psrFactory->createRequest($request);

        $match = $this->router->identifyGateway($psrRequest);
        if ($match === null) {
            return false;
        }

        $request->attributes->set(WebhookCall::REQUEST_META_ATTRIBUTE, [
            'gateway_id' => $match->gatewayId->toString(),
            'kind' => $match->kind,
            'external_id' => $match->externalId,
        ]);

        return true;
    }
}
