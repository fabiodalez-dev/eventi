<?php

declare(strict_types=1);

namespace App\Rules;

use App\DTOs\Fact;
use App\DTOs\FactList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La regola delle righe di una scheda tecnica (`events.facts`, `venues.info`):
 * **una sola**, riusabile in `/admin` e in `/gestione`.
 *
 * Come per `ExternalLinks`, la definizione di riga accettabile non si ripete
 * qui: sta in `App\DTOs\Fact`. Questa regola aggiunge la sola cosa che al cast
 * non serve e a chi compila serve moltissimo — dire **quale** riga non va. Una
 * riga che sparisce in silenzio dopo il salvataggio è peggio di un errore.
 *
 * Il campo è facoltativo: chi non compila la scheda tecnica non sta
 * sbagliando niente.
 */
final class FactRows implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (! is_array($value)) {
            $fail(__('validation.custom.facts.invalid'));

            return;
        }

        if (count($value) > FactList::MAX_FACTS) {
            $fail(__('validation.custom.facts.too_many', ['max' => FactList::MAX_FACTS]));

            return;
        }

        $position = 0;

        foreach ($value as $row) {
            $position++;

            if (! is_array($row)) {
                $fail(__('validation.custom.facts.row_invalid', ['position' => $position]));

                continue;
            }

            $this->validateRow($row, $position, $fail);
        }
    }

    /**
     * @param  array<mixed>  $row
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    private function validateRow(array $row, int $position, Closure $fail): void
    {
        $label = is_scalar($row['label'] ?? null) ? trim((string) $row['label']) : '';
        $text = is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '';

        if ($label === '') {
            $fail(__('validation.custom.facts.label_required', ['position' => $position]));
        } elseif (mb_strlen($label) > Fact::MAX_LABEL_LENGTH) {
            $fail(__('validation.custom.facts.label_too_long', [
                'position' => $position,
                'max' => Fact::MAX_LABEL_LENGTH,
            ]));
        }

        if ($text === '') {
            $fail(__('validation.custom.facts.value_required', ['position' => $position]));
        } elseif (mb_strlen($text) > Fact::MAX_VALUE_LENGTH) {
            $fail(__('validation.custom.facts.value_too_long', [
                'position' => $position,
                'max' => Fact::MAX_VALUE_LENGTH,
            ]));
        }
    }
}
