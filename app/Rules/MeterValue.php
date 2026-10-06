<?php

namespace App\Rules;

use App\Enums\Medium;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Zählerstand passend zum Medium: Strom ganze kWh, Wasser m³ mit bis zu 3 Nachkommastellen. */
class MeterValue implements ValidationRule
{
    public function __construct(private Medium $medium) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($this->medium->parse($value) === null) {
            $fail($this->medium->isWater()
                ? __('Bitte den Zählerstand in m³ angeben, z. B. 123,456.')
                : __('Bitte den Zählerstand als ganze Zahl ohne Nachkommastellen angeben.'));
        }
    }
}
