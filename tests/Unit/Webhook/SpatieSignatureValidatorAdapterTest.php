<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Spatie\WebhookClient\SignatureValidator\DefaultSignatureValidator;
use Spatie\WebhookClient\WebhookConfig;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;
use Techork\PaymentService\Gateway\Webhook\Contract\SignatureVerifier;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Laravel\Webhook\Bridge\SpatieSignatureValidatorAdapter;
use Techork\PaymentService\Laravel\Webhook\Job\ProcessWebhookJob;
use Techork\PaymentService\Laravel\Webhook\Model\WebhookCall;
use Techork\PaymentService\Laravel\Webhook\Profile\IdempotencyProfile;

/**
 * The front door: spatie asks one question — is this request valid — and this adapter turns
 * it into "which of our tenants signed it", then leaves the answer on the request for the two
 * things that run after it ({@see WebhookCall::storeWebhook()} and {@see IdempotencyProfile})
 * so neither has to re-parse the body.
 *
 * At zero coverage until now, and two behaviours are load-bearing enough to pin:
 *
 *  - a delivery it cannot attribute is answered with FALSE, not an exception. Spatie turns
 *    false into a rejected webhook; an exception out of here would be a 500, which most
 *    providers read as "retry forever" — an unsigned or foreign request would then be
 *    redelivered against us indefinitely.
 *  - nothing is stashed on a request it rejected. The metadata attribute is the only channel
 *    between this class and storage, so a leftover from a failed match would be attributed to
 *    the wrong tenant by whatever ran next.
 *
 * Driven through the REAL {@see WebhookRouter} over real registries, following
 * PaynetWebhookSubscriberTest — a double for the router would have made every assertion here
 * a statement about the double. The stand-in verifier is a faithful miniature of the real
 * ones: an HMAC of the raw body under the tenant's own secret. That puts the PSR-7 conversion
 * under test rather than beside it — a body or header lost on the way produces no match at
 * all, which is precisely the production failure this file is here to catch.
 */
function webhookAdapterFactory(): PsrHttpFactory
{
    $psr17 = new Psr17Factory;

    return new PsrHttpFactory($psr17, $psr17, $psr17, $psr17);
}

function webhookAdapterSecret(): string
{
    return 'whsec_'.bin2hex(random_bytes(16));
}

function webhookAdapterSign(string $body, string $secret): string
{
    return hash_hmac('sha256', $body, $secret);
}

function webhookAdapterCredential(string $kind, string $secret, ?GatewayId $id = null): GatewayCredential
{
    return new readonly class($kind, $secret, $id ?? GatewayId::generate()) implements GatewayCredential
    {
        public function __construct(
            private string $kind,
            private string $secret,
            private GatewayId $id,
        ) {}

        public function getId(): GatewayId
        {
            return $this->id;
        }

        public function getGatewayName(): string
        {
            return $this->kind;
        }

        public function getCredentials(): array
        {
            return ['webhook_secret' => $this->secret];
        }
    };
}

/**
 * A real router over the tenants given, with an HMAC verifier and a parser registered for
 * each installed kind.
 *
 * @param  list<GatewayCredential>  $credentials  candidates in the order the router sees them
 * @param  ArrayObject<int, ServerRequestInterface>  $seen  every PSR-7 request a verifier read
 * @param  list<string>|null  $installedKinds  kinds a package registered a verifier for;
 *                                             defaults to every candidate's own kind
 */
function webhookAdapterRouter(array $credentials, ArrayObject $seen, ?array $installedKinds = null): WebhookRouter
{
    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;

    $kinds = $installedKinds ?? array_map(
        static fn (GatewayCredential $credential): string => $credential->getGatewayName(),
        $credentials,
    );

    foreach (array_unique($kinds) as $kind) {
        $verifiers->register(
            $kind,
            new class($seen) implements SignatureVerifier
            {
                /** @param ArrayObject<int, ServerRequestInterface> $seen */
                public function __construct(private readonly ArrayObject $seen) {}

                public function verify(ServerRequestInterface $request, GatewayCredential $gateway): bool
                {
                    $this->seen[] = $request;

                    return hash_equals(
                        hash_hmac('sha256', (string) $request->getBody(), $gateway->getCredentials()['webhook_secret']),
                        $request->getHeaderLine('Signature'),
                    );
                }
            },
            new class implements EventParser
            {
                public function parse(array $payload): ParsedEvent
                {
                    return new ParsedEvent(
                        type: is_string($payload['type'] ?? null) ? $payload['type'] : '',
                        externalId: (string) ($payload['id'] ?? ''),
                        native: new ArrayObject($payload),
                    );
                }
            },
        );
    }

    return new WebhookRouter(
        new readonly class($credentials) implements GatewayCredentialRepository
        {
            /** @param list<GatewayCredential> $credentials */
            public function __construct(private array $credentials) {}

            public function findOrFail(GatewayId $gatewayId): GatewayCredential
            {
                throw new RuntimeException('Identifying a tenant iterates candidates rather than looking one up');
            }

            public function all(): iterable
            {
                return $this->credentials;
            }
        },
        $verifiers,
        $handlers,
    );
}

/**
 * The single shared spatie config entry. The adapter ignores it entirely — the per-tenant
 * credentials carry the secrets — which is itself worth exercising: a signature bound to
 * `$config->signingSecret` could not be multi-tenant.
 */
function webhookAdapterConfig(): WebhookConfig
{
    return new WebhookConfig([
        'name' => 'payments',
        'signing_secret' => 'unused-per-tenant-credentials-carry-it',
        'signature_header_name' => 'Signature',
        'signature_validator' => DefaultSignatureValidator::class,
        'webhook_profile' => IdempotencyProfile::class,
        'webhook_model' => WebhookCall::class,
        'process_webhook_job' => ProcessWebhookJob::class,
        'store_headers' => ['Signature'],
    ]);
}

function webhookAdapterRequest(string $body, string $signature): Request
{
    return Request::create(
        'https://app.test/webhooks/payments',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_SIGNATURE' => $signature],
        $body,
    );
}

beforeEach(function () {
    $this->seen = new ArrayObject;
    $this->body = '{"id":"evt_1","type":"charge.succeeded"}';
    $this->secret = webhookAdapterSecret();
});

it('stashes the tenant the router identified, in the shape storage and dedup read', function () {
    $credential = webhookAdapterCredential('stripe', $this->secret);
    $adapter = new SpatieSignatureValidatorAdapter(
        webhookAdapterRouter([$credential], $this->seen),
        webhookAdapterFactory(),
    );
    $request = webhookAdapterRequest($this->body, webhookAdapterSign($this->body, $this->secret));

    expect($adapter->isValid($request, webhookAdapterConfig()))->toBeTrue();

    // Exactly these three keys, and the gateway id as a string: the attribute is consumed by
    // a mass-assigning `create()` on the model, so a wrong key is silently dropped and a
    // renamed one silently loses the tenant. The external id came out of the parsed body and
    // the match out of an HMAC over the raw one, so a true here is also the statement that
    // the PSR-7 conversion preserved both.
    expect($request->attributes->get(WebhookCall::REQUEST_META_ATTRIBUTE))->toBe([
        'gateway_id' => $credential->getId()->toString(),
        'kind' => 'stripe',
        'external_id' => 'evt_1',
    ]);
});

it('answers false for a delivery no tenant signed, without throwing', function () {
    // Signed with a secret nobody holds — a forgery, or a delivery meant for another
    // installation. False is a rejected webhook; an exception would be a 500, and a 500 is
    // what makes a provider redeliver it forever.
    $adapter = new SpatieSignatureValidatorAdapter(
        webhookAdapterRouter([webhookAdapterCredential('stripe', $this->secret)], $this->seen),
        webhookAdapterFactory(),
    );
    $request = webhookAdapterRequest($this->body, webhookAdapterSign($this->body, webhookAdapterSecret()));

    expect($adapter->isValid($request, webhookAdapterConfig()))->toBeFalse()
        // Nothing left behind: the attribute is the only channel to storage, and a stale
        // entry from a rejected request would be attributed to the wrong tenant.
        ->and($request->attributes->has(WebhookCall::REQUEST_META_ATTRIBUTE))->toBeFalse()
        // It really tried: the candidate was offered the request and refused it.
        ->and($this->seen)->toHaveCount(1);
});

it('answers false for a body-less, header-less request rather than failing on it', function () {
    // What an unsolicited GET at the webhook URL looks like — a scanner, a provider's own
    // health check. The conversion, the verifier and the router all have to cope, because
    // this arrives before any validation of ours.
    $adapter = new SpatieSignatureValidatorAdapter(
        webhookAdapterRouter([webhookAdapterCredential('stripe', $this->secret)], $this->seen),
        webhookAdapterFactory(),
    );

    expect($adapter->isValid(Request::create('https://app.test/webhooks/payments'), webhookAdapterConfig()))
        ->toBeFalse();
});

it('rejects a delivery whose body was altered after signing', function () {
    // The tampering case, and the reason the raw body must survive the bridge intact: the
    // signature is over bytes, so a conversion that re-encoded or dropped them would either
    // reject everything or — if a verifier fell back to the parsed body — accept a payload the
    // provider never sent.
    $adapter = new SpatieSignatureValidatorAdapter(
        webhookAdapterRouter([webhookAdapterCredential('stripe', $this->secret)], $this->seen),
        webhookAdapterFactory(),
    );

    $signature = webhookAdapterSign($this->body, $this->secret);
    $altered = '{"id":"evt_1","type":"charge.refunded"}';

    expect($adapter->isValid(webhookAdapterRequest($altered, $signature), webhookAdapterConfig()))->toBeFalse();
});

it('hands the verifier a PSR-7 request that still carries the method, uri and bodies', function () {
    // The match above already proves the raw body and the signature header survived. This pins
    // the rest of the request the verifiers are documented to work from — several providers
    // sign over the path or read a second header — so the bridge cannot be silently narrowed
    // to "body plus one header".
    $adapter = new SpatieSignatureValidatorAdapter(
        webhookAdapterRouter([webhookAdapterCredential('stripe', $this->secret)], $this->seen),
        webhookAdapterFactory(),
    );

    $adapter->isValid(
        webhookAdapterRequest($this->body, webhookAdapterSign($this->body, $this->secret)),
        webhookAdapterConfig(),
    );

    $psr = $this->seen[0];

    expect($psr)->toBeInstanceOf(ServerRequestInterface::class)
        ->and($psr->getMethod())->toBe('POST')
        ->and((string) $psr->getUri())->toBe('https://app.test/webhooks/payments')
        ->and((string) $psr->getBody())->toBe($this->body)
        // The parsed body is what the router hands the parser to extract the idempotency key;
        // losing it would produce a match with an empty external id, which the dedup index
        // would then read as the same event every time.
        ->and($psr->getParsedBody())->toBe(['id' => 'evt_1', 'type' => 'charge.succeeded']);
});

it('stashes the kind and tenant of whichever candidate signed it', function () {
    // Multi-tenant, multi-kind: the first candidate is offered the delivery and refuses, and
    // the answer must be the one that verified rather than the first one tried. Getting this
    // wrong would store every delivery under one merchant and route it with another's parser.
    $stripe = webhookAdapterCredential('stripe', webhookAdapterSecret());
    $nuvei = webhookAdapterCredential('nuvei', $this->secret);

    $adapter = new SpatieSignatureValidatorAdapter(
        webhookAdapterRouter([$stripe, $nuvei], $this->seen),
        webhookAdapterFactory(),
    );
    $request = webhookAdapterRequest($this->body, webhookAdapterSign($this->body, $this->secret));

    expect($adapter->isValid($request, webhookAdapterConfig()))->toBeTrue()
        ->and($request->attributes->get(WebhookCall::REQUEST_META_ATTRIBUTE))->toBe([
            'gateway_id' => $nuvei->getId()->toString(),
            'kind' => 'nuvei',
            'external_id' => 'evt_1',
        ]);
});

it('answers false when the only tenant that could have signed it has no package installed', function () {
    // A credential row for a gateway whose package is not installed has no verifier, so the
    // router skips that candidate. The delivery is then unattributable, and false — a
    // rejection — is the only safe answer: a true with no tenant stashed would store a row the
    // job could never route.
    $adapter = new SpatieSignatureValidatorAdapter(
        webhookAdapterRouter(
            [webhookAdapterCredential('uninstalled-gateway', $this->secret)],
            $this->seen,
            installedKinds: ['stripe'],
        ),
        webhookAdapterFactory(),
    );
    $request = webhookAdapterRequest($this->body, webhookAdapterSign($this->body, $this->secret));

    expect($adapter->isValid($request, webhookAdapterConfig()))->toBeFalse()
        ->and($request->attributes->has(WebhookCall::REQUEST_META_ATTRIBUTE))->toBeFalse()
        // Never even offered: without a verifier there is nothing to offer it to.
        ->and($this->seen)->toHaveCount(0);
});
