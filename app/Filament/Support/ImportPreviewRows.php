<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DTOs\ImportedEventDto;

/**
 * L'anteprima di §14.2 ridotta a righe già pronte da stampare.
 *
 * Serve a due pannelli con esigenze diverse — la redazione la guarda in una
 * finestra, il gestore del locale la guarda **nella pagina**, perché lì
 * l'anteprima non è un dettaglio ma il passaggio obbligato prima di accendere
 * la sorgente (D32). Una sola tabella per entrambi, e nessun `ImportedEventDto`
 * che finisce nello stato di un componente Livewire: i DTO portano istanti
 * `CarbonImmutable`, e ciò che viaggia fra browser e server deve restare
 * testo.
 *
 * Le date si formattano **nell'ora della città** e non in quella del server
 * (§8.1): è l'unico modo in cui chi guarda riconosce che il fuso del
 * calendario è stato interpretato bene, che è poi la ragione per cui
 * l'anteprima esiste.
 */
final class ImportPreviewRows
{
    /**
     * @param  list<ImportedEventDto>  $dates
     * @return list<array{when: string, title: string, where: string|null, notes: list<string>, cancelled: bool}>
     */
    public static function make(array $dates, string $timezone): array
    {
        $rows = [];

        foreach ($dates as $date) {
            $starts = $date->startsAt->setTimezone($timezone);
            $notes = [];

            if ($date->isAllDay) {
                $notes[] = __('import.preview.all_day');
            }

            if ($date->isRecurring()) {
                $notes[] = __('import.preview.recurring');
            }

            $rows[] = [
                'when' => $date->isAllDay ? $starts->format('d/m/Y') : $starts->format('d/m/Y H:i'),
                'title' => $date->title,
                'where' => $date->location,
                'notes' => $notes,
                'cancelled' => $date->isCancelled,
            ];
        }

        return $rows;
    }
}
