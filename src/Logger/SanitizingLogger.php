<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use Techork\PaymentService\Gateway\Logger\GatewayLoggerInterface;

/**
 * Decorator that walks the log context tree and replaces values whose key
 * matches one of the configured sanitizers, then forwards the redacted record
 * to the inner gateway logger. Each sanitizer is a single-argument callable
 * (e.g. an invokable class) returning the masked value; keys are matched by
 * exact name at any depth, since gateway request/response payloads use the
 * same field name across nesting levels (`card.holder`, `holder`, etc.).
 *
 * `null` values end up untouched only because every sanitizer's `match()` rejects
 * a non-string value — `match()` is still called with `null`, so a sanitizer has to
 * reject it itself. That passthrough matches the convention of
 * `App\Infrastructure\Logger\ObfuscatingLogger`.
 */
final readonly class SanitizingLogger implements GatewayLoggerInterface
{
    private array $sanitizers;

    public function __construct(
        private LoggerInterface $logger,
        private string $level = LogLevel::INFO,
        SanitizerInterface ...$sanitizers,
    ) {
        $this->sanitizers = $sanitizers;
    }

    public function log(Stringable|string $message, array $context = []): void
    {
        $this->logger->log($this->level, $message, $this->walk($context));
    }

    private function walk(array $items): array
    {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $items[$key] = $this->walk($value);

                continue;
            }

            // Defensive recursion into VOs whose producer didn't pre-flatten
            // (e.g. a Router that logged the BillingAddress object directly):
            // pull the public scalar surface via get_object_vars and walk it
            // as a sub-array so nested email/phone/holder still get masked.
            if (is_object($value) && ! $value instanceof Stringable) {
                $properties = get_object_vars($value);
                if ($properties !== []) {
                    $items[$key] = $this->walk($properties);

                    continue;
                }
            }

            $sanitizer = array_find($this->sanitizers, static fn(SanitizerInterface $sanitizer): bool => $sanitizer->match($key, $value));
            $items[$key] = isset($sanitizer) ? $sanitizer->mask($key, $value) : $value;
        }

        return $items;
    }
}
