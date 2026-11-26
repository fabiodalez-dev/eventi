<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * «Esporta» dell'elenco eventi (R-EV-04, decisione 8): il foglio Excel con
 * le righe che si stanno guardando — scheda, filtri e ricerca compresi —
 * e le colonne della tabella del pannello (titolo, quando e dove, stato,
 * date in cartellone, pubblicazione).
 *
 * Il file si scrive in una cartella temporanea e si cancella appena
 * spedito: niente copie sul disco dell'hosting.
 */
final class EditorialEventsExport
{
    /** Un tetto per non tenere occupato il server con un foglio da decine di migliaia di righe. */
    public const MAX_ROWS = 5000;

    /** @param Builder<Event> $query */
    public function download(Builder $query): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'incitta-eventi-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create the events export.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            try {
                $writer->getCurrentSheet()->setName(__('admin.resources.event.plural'));
                $writer->addRow(self::row([
                    __('admin.fields.title'),
                    __('admin.fields.venue'),
                    __('admin.fields.status'),
                    __('admin.fields.occurrences_count'),
                    __('admin.fields.published_at'),
                ])->setStyle((new Style)->setFontBold()->setShouldWrapText()));

                foreach ($query->limit(self::MAX_ROWS)->get() as $event) {
                    /** @var Event $event */
                    $timezone = $event->city->timezone ?? config()->string('app.timezone');
                    $writer->addRow(self::row([
                        $event->title,
                        self::whenLine($event, $timezone).' · '.($event->venue->name ?? data_get($event->custom_location, 'name') ?? __('admin.event_list.no_place')),
                        $event->status->label(),
                        (int) ($event->getAttribute('occurrences_count') ?? 0),
                        $event->published_at === null ? '' : $event->published_at->copy()->timezone($timezone)->format('d/m/Y H:i'),
                    ]));
                }
            } finally {
                $writer->close();
            }

            return response()->download($path, 'incitta-eventi-'.now()->format('Ymd-His').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    /** «Prossima data», oppure «Ultima data» per chi ha solo date passate, nel fuso della città dell'evento. */
    private static function whenLine(Event $event, string $timezone): string
    {
        $next = $event->getAttribute('next_starts_at');
        $last = $event->getAttribute('last_starts_at');
        $format = fn (mixed $value): string => CarbonImmutable::parse((string) $value, 'UTC')->timezone($timezone)->locale('it')->isoFormat('ddd D MMM YYYY, HH:mm');

        return match (true) {
            filled($next) => $format($next),
            filled($last) => __('pannelli-eventi.list.was', ['date' => $format($last)]),
            default => __('admin.event_list.no_dates'),
        };
    }

    /** @param list<mixed> $values */
    private static function row(array $values): Row
    {
        // Il testo della redazione resta testo, anche quando comincia con «=».
        return new Row(array_map(fn (mixed $value): Cell => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values));
    }
}
