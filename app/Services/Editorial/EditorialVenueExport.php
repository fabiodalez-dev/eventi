<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * «Esporta» dell'elenco dei locali per l'app (decisione 8 della redazione):
 * il foglio Excel lo produce il server, l'app lo scarica e lo condivide.
 *
 * Il pannello esporta con `pxlrbt/filament-excel`, che è un'azione Livewire
 * e non si chiama fuori dalla tabella: qui si scrivono le stesse colonne
 * dell'elenco (nome, comune e tipo, stato, eventi, verificato) con gli stessi
 * filtri, scheda e ricerca di chi esporta. Il testo resta testo anche quando
 * comincia con «=» (nessuna formula da un nome di locale).
 */
final class EditorialVenueExport
{
    public function __construct(private readonly EditorialVenues $venues) {}

    /** @param  array<string, string>  $filters */
    public function download(string $tab, array $filters, string $search, string $sort): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'incitta-venues-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create venues export.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            try {
                $writer->getCurrentSheet()->setName((string) __('admin.resources.venue.plural'));
                $writer->addRow(self::row(EditorialVenues::exportHeadings())->setStyle((new Style)->setFontBold()));
                foreach ($this->venues->exportRows($tab, $filters, $search, $sort) as $row) {
                    $writer->addRow(self::row($row));
                }
            } finally {
                $writer->close();
            }

            return response()->download($path, 'locali-'.now()->format('Ymd-His').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    /** @param  list<mixed>  $values */
    private static function row(array $values): Row
    {
        return new Row(array_map(static fn (mixed $value): Cell => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values));
    }
}
