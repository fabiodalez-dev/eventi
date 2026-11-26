<?php

declare(strict_types=1);

namespace App\Services\Editorial\Promotion;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * «Esporta» dell'elenco delle campagne per l'app (R-SP-02, decisione 8): il
 * foglio Excel lo produce il server, l'app lo scarica e lo condivide.
 *
 * Il pannello esporta con `pxlrbt/filament-excel`, un'azione Livewire che
 * non si chiama fuori dalla tabella: qui si scrivono le colonne dell'elenco
 * con gli stessi filtri e la stessa ricerca di chi esporta, come fa il
 * pannello («esporta quello che si sta guardando»). Il testo resta testo
 * anche quando comincia con «=» (nessuna formula dal nome di un committente).
 */
final class EditorialSponsorshipExport
{
    public function __construct(private readonly EditorialSponsorships $campaigns) {}

    /** @param  array<string, string>  $filters */
    public function download(string $search, array $filters, string $sort): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'incitta-sponsorships-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create sponsorships export.');
        }

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            try {
                $writer->getCurrentSheet()->setName((string) __('sponsorships.admin.title'));
                $writer->addRow(self::row(EditorialSponsorships::exportHeadings())->setStyle((new Style)->setFontBold()));
                foreach ($this->campaigns->exportRows($search, $filters, $sort) as $row) {
                    $writer->addRow(self::row($row));
                }
            } finally {
                $writer->close();
            }

            return response()->download($path, 'sponsorizzazioni-'.now()->format('Ymd-His').'.xlsx', [
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
