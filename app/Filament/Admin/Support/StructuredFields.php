<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

/**
 * Conversione fra il formato **salvato** di due colonne JSON e il formato che
 * si compila comodamente in un modulo.
 *
 * Sta qui, e non dentro i componenti del modulo, per un motivo imparato sul
 * campo: agganciare la conversione a `afterStateHydrated()` di un ripetitore
 * significa entrare nel suo ciclo di vita, dove lo stato è già la mappa
 * interna `{identificatore: riga}` che il ripetitore usa per tenere le righe.
 * La conversione la scambiava per i dati salvati e produceva righe fantasma
 * con l'identificatore al posto del giorno. La traduzione appartiene al
 * confine — quando i dati entrano nel modulo e quando ne escono — cioè ai
 * `mutateFormData…` delle pagine.
 */
final class StructuredFields
{
    /**
     * `venues.opening_hours` sul database ha il formato fissato da D19:
     * `{"mon": [{"open": "10:00", "close": "18:00"}], …}` — chiavi in inglese
     * abbreviato, più fasce per giorno ammesse. Nel modulo è un elenco piatto
     * di righe, che è ciò che una persona compila davvero.
     *
     * @param  mixed  $map
     * @return list<array{day: string, open: ?string, close: ?string}>
     */
    public static function openingHoursToRows($map): array
    {
        $rows = [];

        foreach (is_array($map) ? $map : [] as $day => $ranges) {
            if (! is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $range) {
                if (! is_array($range)) {
                    continue;
                }

                $rows[] = [
                    'day' => (string) $day,
                    'open' => isset($range['open']) ? (string) $range['open'] : null,
                    'close' => isset($range['close']) ? (string) $range['close'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  mixed  $rows
     * @return array<string, list<array{open: string, close: string}>>|null
     */
    public static function openingHoursToMap($rows): ?array
    {
        $map = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || blank($row['day'] ?? null)) {
                continue;
            }

            $map[(string) $row['day']][] = [
                'open' => (string) ($row['open'] ?? ''),
                'close' => (string) ($row['close'] ?? ''),
            ];
        }

        return $map === [] ? null : $map;
    }

    /**
     * `cities.settings` è una mappa di interruttori `{"nome": true}` (D24):
     * il pannello la mostra come righe nome/acceso.
     *
     * @param  mixed  $map
     * @return list<array{key: string, enabled: bool}>
     */
    public static function flagsToRows($map): array
    {
        $rows = [];

        foreach (is_array($map) ? $map : [] as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $rows[] = ['key' => (string) $key, 'enabled' => (bool) $value];
        }

        return $rows;
    }

    /**
     * @param  mixed  $rows
     * @return array<string, bool>|null
     */
    public static function flagsToMap($rows): ?array
    {
        $map = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || blank($row['key'] ?? null)) {
                continue;
            }

            $map[(string) $row['key']] = (bool) ($row['enabled'] ?? false);
        }

        return $map === [] ? null : $map;
    }

    /**
     * `event_recurrences.exdates` è un elenco JSON di date; nel modulo è un
     * testo con una data per riga.
     *
     * @param  mixed  $dates
     */
    public static function datesToLines($dates): string
    {
        return implode("\n", array_map(strval(...), is_array($dates) ? $dates : []));
    }

    /**
     * @return list<string>|null
     */
    public static function linesToDates(?string $text): ?array
    {
        $lines = preg_split('/\R/', (string) $text) ?: [];
        $lines = array_values(array_filter(array_map(trim(...), $lines)));

        return $lines === [] ? null : $lines;
    }
}
