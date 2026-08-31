<?php

declare(strict_types=1);

namespace App\Rules;

use App\DTOs\ExternalLink;
use App\DTOs\ExternalLinkList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La regola dei link esterni di un evento: **una sola**, riusabile ovunque
 * qualcuno possa scriverli — il modulo di `/admin`, il wizard e la scheda di
 * `/gestione`, e domani un import o un endpoint.
 *
 * Non ripete la definizione di "indirizzo accettabile": quella sta in
 * `App\DTOs\ExternalLink`, che è anche ciò che il cast usa per non far entrare
 * righe inutilizzabili nel modello. La regola aggiunge l'unica cosa che al
 * cast non serve e a chi compila serve moltissimo: **dire quale riga non va e
 * perché**. Un elenco che sparisce in silenzio dopo il salvataggio è peggio di
 * un errore.
 *
 * Il campo è facoltativo: `null`, stringa vuota o elenco vuoto passano. Chi
 * non mette link non sta sbagliando niente.
 */
final class ExternalLinks implements ValidationRule
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
            $fail(__('validation.custom.external_links.invalid'));

            return;
        }

        if (count($value) > ExternalLinkList::MAX_LINKS) {
            $fail(__('validation.custom.external_links.too_many', ['max' => ExternalLinkList::MAX_LINKS]));

            return;
        }

        // Le righe di un ripetitore Filament sono una mappa
        // `{identificatore: riga}`: la posizione che interessa a chi legge
        // l'errore è quella visibile nel modulo, cioè l'ordine, non la chiave.
        $position = 0;

        foreach ($value as $row) {
            $position++;

            if (! is_array($row)) {
                $fail(__('validation.custom.external_links.row_invalid', ['position' => $position]));

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
        $url = is_scalar($row['url'] ?? null) ? trim((string) $row['url']) : '';

        if ($label === '') {
            $fail(__('validation.custom.external_links.label_required', ['position' => $position]));
        } elseif (mb_strlen($label) > ExternalLink::MAX_LABEL_LENGTH) {
            $fail(__('validation.custom.external_links.label_too_long', [
                'position' => $position,
                'max' => ExternalLink::MAX_LABEL_LENGTH,
            ]));
        }

        if ($url === '') {
            $fail(__('validation.custom.external_links.url_required', ['position' => $position]));

            return;
        }

        if (! ExternalLink::hasAllowedScheme($url)) {
            $fail(__('validation.custom.external_links.url_scheme', [
                'position' => $position,
                'schemes' => implode(', ', ExternalLink::ALLOWED_SCHEMES),
            ]));

            return;
        }

        if (! ExternalLink::hasUsableHost($url)) {
            $fail(__('validation.custom.external_links.url_host', ['position' => $position]));

            return;
        }

        if (ExternalLink::hasCredentials($url)) {
            $fail(__('validation.custom.external_links.url_credentials', ['position' => $position]));
        }
    }
}
