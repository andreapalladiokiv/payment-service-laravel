<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State as StateVO;

/**
 * Validates that a state code belongs to the set of states defined for the
 * sibling country field. Countries without a defined state list pass.
 */
final class State implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $countryKey) {}

    public function setData(array $data): State
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $country = data_get($this->data, $this->countryKey);
        if (! is_string($country) || strlen($country) !== 2) {
            return;
        }

        $validStates = array_map(strval(...), StateVO::all(new Country($country)));

        if ($validStates === []) {
            return;
        }

        if (! in_array($value, $validStates, true)) {
            $fail("The :attribute must be a valid state code for $country.");
        }
    }
}
