<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\RecurrenceRule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Il campo «Regola di ricorrenza» del pannello e dell'app della redazione:
 * una RRULE che si legge, al più una volta al giorno, e che in un anno non
 * produce più di `RecurrenceRule::MAX_DATES_PER_YEAR` date. La regola è
 * `RecurrenceRule::problem`, la stessa che ferma «Genera date».
 */
final class RecurrenceRuleText implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $problem = is_string($value) ? RecurrenceRule::problem($value) : (string) __('admin.validation.rrule_invalid');
        if ($problem !== null) {
            $fail($problem);
        }
    }
}
