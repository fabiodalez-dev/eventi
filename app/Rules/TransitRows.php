<?php

declare(strict_types=1);

namespace App\Rules;

use App\DTOs\TransitGuide;
use App\DTOs\TransitLine;
use App\Enums\TransitMode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La regola delle righe di «Come arrivare» (`venues.transit`): **una sola**,
 * riusabile in `/admin` e in `/gestione`.
 *
 * Il mezzo deve appartenere a `TransitMode`, perché è un vocabolario chiuso e
 * tradotto; l'indicazione deve esserci, perché una riga con il solo mezzo
 * nella scheda pubblica sarebbe un'etichetta accanto al vuoto.
 */
final class TransitRows implements ValidationRule
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
            $fail(__('validation.custom.transit.invalid'));

            return;
        }

        if (count($value) > TransitGuide::MAX_LINES) {
            $fail(__('validation.custom.transit.too_many', ['max' => TransitGuide::MAX_LINES]));

            return;
        }

        $position = 0;

        foreach ($value as $row) {
            $position++;

            if (! is_array($row)) {
                $fail(__('validation.custom.transit.row_invalid', ['position' => $position]));

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
        $mode = is_string($row['mode'] ?? null) ? TransitMode::tryFrom($row['mode']) : null;
        $text = is_scalar($row['text'] ?? null) ? trim((string) $row['text']) : '';

        if ($mode === null) {
            $fail(__('validation.custom.transit.mode_required', ['position' => $position]));
        }

        if ($text === '') {
            $fail(__('validation.custom.transit.text_required', ['position' => $position]));
        } elseif (mb_strlen($text) > TransitLine::MAX_TEXT_LENGTH) {
            $fail(__('validation.custom.transit.text_too_long', [
                'position' => $position,
                'max' => TransitLine::MAX_TEXT_LENGTH,
            ]));
        }
    }
}
