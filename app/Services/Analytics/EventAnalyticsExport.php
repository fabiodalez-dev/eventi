<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\ContentMetric;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class EventAnalyticsExport
{
    public const DATASETS = ['events', 'venues', 'organizers', 'channels', 'daily', 'links', 'occurrences'];

    public static function label(string $key): string
    {
        $metric = ContentMetric::tryFrom($key);
        if ($metric !== null) {
            return $metric->label();
        }
        if (str_starts_with($key, 'profile_') && ($metric = ContentMetric::tryFrom(substr($key, 8))) !== null) {
            return __('analytics-dashboard.profile_prefix').' '.$metric->label();
        }

        return __('analytics-dashboard.columns.'.$key);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function displayRow(array $row): array
    {
        return array_diff_key($row, array_flip(['id', 'event_id', 'venue_id', 'organizer_id']));
    }

    /** @param array<string, mixed> $filters */
    public function download(array $filters, string $format, string $dataset): BinaryFileResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true) && in_array($dataset, self::DATASETS, true), 422);
        $report = app(EventAnalyticsDashboard::class)->report($filters);
        $path = tempnam(sys_get_temp_dir(), 'incitta-analytics-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create analytics export.');
        }
        try {
            if ($format === 'csv') {
                $handle = fopen($path, 'wb');
                if ($handle === false) {
                    throw new \RuntimeException('Unable to open analytics export.');
                }
                try {
                    fwrite($handle, "\xEF\xBB\xBF");
                    $rows = $report[$dataset]->map(self::displayRow(...));
                    $columns = array_keys($rows->first() ?? []);
                    fputcsv($handle, array_map(self::label(...), $columns), ';', '"', '');
                    foreach ($rows as $row) {
                        fputcsv($handle, array_map(self::csvCell(...), array_values($row)), ';', '"', '');
                    }
                } finally {
                    fclose($handle);
                }
            } else {
                $writer = new Writer;
                $writer->openToFile($path);
                try {
                    $writer->getCurrentSheet()->setName(__('analytics-dashboard.workbook_summary'));
                    $writer->getCurrentSheet()->setColumnWidth(42, 1);
                    $writer->getCurrentSheet()->setColumnWidth(70, 2);
                    $writer->addRow(self::spreadsheetRow([__('analytics-dashboard.title'), now()->toIso8601String()]));
                    foreach ($report['filter_labels'] as $key => $value) {
                        $writer->addRow(self::spreadsheetRow([__('analytics-dashboard.'.$key), $value ?? __('analytics-dashboard.all')]));
                    }
                    foreach ($report['totals'] as $key => $value) {
                        $writer->addRow(self::spreadsheetRow([self::label($key), $value]));
                    }
                    $writer->addRow(self::spreadsheetRow([__('analytics-dashboard.definitions')]));
                    foreach (self::DATASETS as $name) {
                        $sheet = $writer->addNewSheetAndMakeItCurrent()->setName(__('analytics-dashboard.datasets.'.$name));
                        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
                        $rows = $report[$name]->map(self::displayRow(...));
                        $columns = array_keys($rows->first() ?? []);
                        foreach ($columns as $index => $column) {
                            $sheet->setColumnWidth(in_array($column, ['event', 'name', 'venue', 'organizer', 'url'], true) ? 42 : 24, $index + 1);
                        }
                        if ($columns !== []) {
                            $sheet->setAutoFilter(new AutoFilter(0, 1, count($columns) - 1, $rows->count() + 1));
                        }
                        $writer->addRow(self::spreadsheetRow(array_map(self::label(...), $columns))->setStyle((new Style)->setFontBold()->setBackgroundColor('F1EFEB')->setShouldWrapText()));
                        foreach ($rows as $row) {
                            // Editorial text must remain text, including strings beginning with "=".
                            $writer->addRow(self::spreadsheetRow(array_values($row)));
                        }
                    }
                } finally {
                    $writer->close();
                }
            }

            return response()->download($path, 'incitta-analytics-'.$dataset.'-'.now()->format('Ymd-His').'.'.$format, [
                'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    /** @param list<mixed> $values */
    private static function spreadsheetRow(array $values): Row
    {
        return new Row(array_map(fn ($value) => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values));
    }

    public static function csvCell(mixed $value): mixed
    {
        // Spreadsheet formula injection protection for untrusted editorial text.
        return is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
