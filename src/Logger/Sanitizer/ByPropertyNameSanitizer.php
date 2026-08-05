<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Logger\Sanitizer;

use Illuminate\Support\Str;
use Override;
use Techork\PaymentService\Laravel\Logger\SanitizerInterface;

final readonly class ByPropertyNameSanitizer implements SanitizerInterface
{
    /** @var list<string> */
    private array $names;

    public function __construct(string ...$names)
    {
        $this->names = array_values($names);
    }

    #[Override]
    public function match(string $name, mixed $value): bool
    {
        return is_string($value) && in_array($name, $this->names, true);
    }

    #[Override]
    public function mask(string $name, mixed $value): string
    {
        return Str::mask((string) $value, '*', 0);
    }
}
