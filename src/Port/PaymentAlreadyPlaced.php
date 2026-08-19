<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Port;

use RuntimeException;
use Techork\PaymentService\Common\Concern\CarriesErrorCode;
use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;

/**
 * This payment has been placed at the acquirer already, and the call was about to be made
 * a second time.
 *
 * Thrown rather than answered, because a stored reference says a transaction exists — not
 * that it was approved, captured or declined. Reporting success from the presence of an id
 * is a mistake this package has made before, in Stripe's `isSuccessful()`, and it ended in
 * money captured against an authorization that was never granted. Refusing loudly leaves a
 * failed job for someone to look at; guessing leaves a wrong ledger.
 *
 * Recovering the original outcome properly needs a per-gateway "have you already got one of
 * these?" lookup — ConnexPay has `Search/Sales` by `OrderNumber`, Stripe can retrieve by
 * idempotency key, Nuvei by `clientUniqueId`. Until that exists this is the honest answer.
 */
final class PaymentAlreadyPlaced extends RuntimeException implements CodedError
{
    use CarriesErrorCode;

    public static function withReference(string $paymentIntentId, string $reference): self
    {
        return self::coded(
            ErrorCode::ResourceAlreadyExists,
            sprintf(
                'Payment intent %s is already placed at the gateway as %s; refusing to place it a second time.',
                $paymentIntentId,
                $reference,
            ),
        );
    }
}
