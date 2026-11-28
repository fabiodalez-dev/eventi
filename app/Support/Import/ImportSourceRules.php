<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\ImportSourceType;
use App\Rules\SafeImportUrl;
use App\Rules\UrlWithoutUserInfo;
use Illuminate\Validation\Rule;

/**
 * Le regole del modulo di una sorgente di import (§14.2, R-SY-01), le stesse
 * per il modulo della redazione nel pannello (`ImportSourceResource::form`) e
 * per l'API dell'app (`POST`/`PATCH /api/v1/editorial/import-sources`).
 *
 * - città obbligatoria, locale facoltativo;
 * - **categoria predefinita obbligatoria quando manca il locale**: è la sola
 *   combinazione in cui gli eventi nascerebbero senza classificazione;
 * - indirizzo fino a 1000 caratteri, verificato con `SafeImportUrl` (la stessa
 *   difesa del driver prima di scaricare) e senza `utente:password@`
 *   (`UrlWithoutUserInfo`: le credenziali hanno il loro campo cifrato);
 * - la mappatura è un dizionario di chiavi testuali (`timezone`, `auth`,
 *   `exclude_keywords`…), come il campo `KeyValue` del pannello.
 *
 * Le credenziali non stanno qui: nel pannello si scrivono e non si rileggono,
 * e dall'app non si scrivono affatto (decisione 6 della redazione).
 */
final class ImportSourceRules
{
    public const MAX_URL_LENGTH = 1000;

    /** Tetti della mappatura: il pannello non ne ha, l'API sì, per non salvare un dizionario senza fondo. */
    public const MAX_MAPPING_ENTRIES = 50;

    public const MAX_MAPPING_KEY_LENGTH = 100;

    public const MAX_MAPPING_VALUE_LENGTH = 1000;

    public const MAX_MAPPING_LIST_ITEMS = 100;

    /** Vero quando la categoria predefinita è obbligatoria: senza locale. */
    public static function needsDefaultCategory(mixed $venueId): bool
    {
        return blank($venueId);
    }

    /**
     * Le regole dei campi, per una richiesta che li manda tutti (creazione) o
     * solo quelli che cambiano (`$partial`, modifica dall'app). La categoria
     * obbligatoria si decide su `$venueId`, il locale che la sorgente avrà
     * dopo il salvataggio.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $partial, mixed $venueId): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'city_id' => [$required, 'integer', Rule::exists('cities', 'id')],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')],
            'type' => [$required, Rule::enum(ImportSourceType::class)],
            'default_category_id' => [
                self::needsDefaultCategory($venueId) ? 'required' : 'nullable',
                'integer',
                Rule::exists('categories', 'id'),
            ],
            'url' => ['nullable', 'string', 'url', 'max:'.self::MAX_URL_LENGTH, new UrlWithoutUserInfo, new SafeImportUrl],
            'mapping' => ['nullable', 'array', 'max:'.self::MAX_MAPPING_ENTRIES, function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && ! self::validMapping($value)) {
                    $fail(__('admin.import.mapping_invalid'));
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
            // Solo dal sito: un segreto non viaggia dal telefono (decisione 6).
            'credentials' => ['prohibited'],
        ];
    }

    /**
     * Una mappatura accettabile: chiavi testuali non vuote; valori testo,
     * numero, sì/no, nulli o elenchi di testi (`exclude_keywords`).
     *
     * @param  array<mixed>  $mapping
     */
    public static function validMapping(array $mapping): bool
    {
        if (array_is_list($mapping) && $mapping !== []) {
            return false;
        }

        foreach ($mapping as $key => $value) {
            if (! is_string($key) || trim($key) === '' || mb_strlen($key) > self::MAX_MAPPING_KEY_LENGTH) {
                return false;
            }
            if (is_array($value)) {
                if (! array_is_list($value) || count($value) > self::MAX_MAPPING_LIST_ITEMS) {
                    return false;
                }
                foreach ($value as $item) {
                    if (! is_string($item) || mb_strlen($item) > self::MAX_MAPPING_VALUE_LENGTH) {
                        return false;
                    }
                }

                continue;
            }
            if ($value !== null && ! is_scalar($value)) {
                return false;
            }
            if (is_string($value) && mb_strlen($value) > self::MAX_MAPPING_VALUE_LENGTH) {
                return false;
            }
        }

        return true;
    }
}
