<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `cities.settings` è una mappa di interruttori `{"nome": true}` (D24): il
 * modulo della città la mostra come righe nome/acceso, nel pannello e
 * nell'app della redazione.
 *
 * Stava in `App\Filament\Admin\Support\StructuredFields`; l'ho spostata qui
 * perché la chiama anche l'API della redazione, che non può dipendere da
 * Filament. La conversione resta al confine del modulo (i
 * `mutateFormData…` delle pagine, il servizio dell'API), non dentro il
 * ripetitore: vedi la nota in `StructuredFields`.
 */
final class CitySettings
{
    /**
     * @param  mixed  $map
     * @return list<array{key: string, enabled: bool}>
     */
    public static function toRows($map): array
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
    public static function toMap($rows): ?array
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
}
