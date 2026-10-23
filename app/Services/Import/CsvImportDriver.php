<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\DTOs\ImportedEventDto;
use App\DTOs\ImportMapping;
use App\Exceptions\ImportException;
use App\Models\ImportSource;
use App\Services\Import\Concerns\DownloadsSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Il driver CSV: la stagione di un teatro in un foglio, il programma di una
 * rassegna esportato da Excel, un Google Sheet «pubblicato come CSV».
 *
 * La sorgente è un indirizzo come per l'ICS, oppure un file caricato dal
 * pannello (`upload:<nome>`), letto dal disco privato nella cartella del
 * locale. La prima riga sono le intestazioni; le colonne si riconoscono per
 * nome, in italiano o in inglese, e si possono rinominare in
 * `mapping.columns`. Il separatore (`;` o `,`) e il BOM si capiscono da soli.
 *
 * Le date accettano `AAAA-MM-GG`, `GG/MM/AAAA` e la forma con l'ora attaccata
 * o in una colonna a parte; sono nel fuso del locale. Senza identificativo
 * nel foglio, l'identificativo è l'impronta di titolo e inizio: cambiare il
 * titolo crea una data nuova e manda in cancellazione quella vecchia, ed è
 * il motivo per cui una colonna `id` è consigliata.
 */
final class CsvImportDriver implements ImportSourceDriver
{
    use DownloadsSource;

    public const UPLOAD_PREFIX = 'upload:';

    public const UPLOAD_DIRECTORY = 'imports';

    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'uid' => ['id', 'uid', 'codice', 'identificativo'],
        'title' => ['titolo', 'title', 'evento', 'event', 'nome', 'name'],
        'date' => ['data', 'date', 'giorno', 'day'],
        'time' => ['ora', 'orario', 'time', 'start_time', 'ora_inizio'],
        'start' => ['inizio', 'start', 'starts_at', 'start_at', 'datetime', 'data_ora', 'dal'],
        'end' => ['fine', 'end', 'ends_at', 'end_at', 'ora_fine', 'end_time', 'al'],
        'description' => ['descrizione', 'description', 'testo', 'note', 'dettagli'],
        'url' => ['link', 'url', 'sito', 'website', 'biglietti', 'tickets'],
        'location' => ['luogo', 'location', 'dove', 'place', 'sala', 'venue'],
        'all_day' => ['tutto_il_giorno', 'all_day', 'intera_giornata'],
        'cancelled' => ['annullato', 'cancelled', 'canceled'],
    ];

    public function __construct(private readonly ImportUrlGuard $guard) {}

    protected function acceptHeader(): string
    {
        return 'text/csv, text/plain;q=0.9, */*;q=0.5';
    }

    /** @return list<array<string, string>> */
    public function fetch(ImportSource $source): array
    {
        $body = $this->isUpload($source) ? $this->readUpload($source) : $this->download($source);

        return $this->parse($body);
    }

    public function map(mixed $raw, ImportSource $source): ?ImportedEventDto
    {
        if (! is_array($raw)) {
            return null;
        }
        $columns = $this->columns($source);
        $get = static function (string $field) use ($raw, $columns): ?string {
            foreach ($columns[$field] ?? [] as $name) {
                if (isset($raw[$name]) && trim((string) $raw[$name]) !== '') {
                    return trim((string) $raw[$name]);
                }
            }

            return null;
        };

        $title = $get('title');
        $timezone = ImportMapping::fromSource($source)->timezone ?? $source->venue?->city->timezone ?? $source->city->timezone ?? config('app.timezone');
        $start = $this->instant($get('start') ?? $get('date'), $get('start') === null ? $get('time') : null, $timezone);
        if ($title === null || $start === null) {
            return null;
        }
        $isAllDay = $this->flag($get('all_day')) || ($get('start') === null && $get('time') === null && ! $this->hasTime($get('date')));
        $end = $this->instant($get('end'), null, $timezone, $start);
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            $end = null;
        }
        $uid = $get('uid') ?? substr(hash('sha1', Str::lower($title).'|'.$start->toIso8601String()), 0, 24);

        return new ImportedEventDto(
            uid: $uid,
            recurrenceId: null,
            title: $title,
            startsAt: $start,
            endsAt: $end,
            isAllDay: $isAllDay,
            description: $get('description'),
            location: $get('location'),
            url: $get('url'),
            isCancelled: $this->flag($get('cancelled')),
        );
    }

    /**
     * Da testo a righe associative: separatore riconosciuto dalla prima riga,
     * intestazioni normalizzate (minuscole, spazi e trattini in `_`).
     *
     * @return list<array<string, string>>
     */
    public function parse(string $body): array
    {
        $body = ltrim($body, "\xEF\xBB\xBF");
        if (trim($body) === '') {
            throw ImportException::unreadable(__('import.csv.empty'));
        }
        $lines = preg_split('/\R/u', $body) ?: [];
        $header = $lines[0] ?? '';
        $delimiter = substr_count($header, ';') >= substr_count($header, ',') ? ';' : ',';
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw ImportException::unreadable('csv');
        }
        fwrite($handle, $body);
        rewind($handle);
        $names = fgetcsv($handle, 0, $delimiter, '"', '');
        if (! is_array($names)) {
            fclose($handle);
            throw ImportException::unreadable(__('import.csv.empty'));
        }
        $names = array_map(fn (mixed $name): string => $this->normaliseHeader((string) $name), $names);
        $rows = [];
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            }
            $row = [];
            foreach ($names as $index => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = (string) ($values[$index] ?? '');
            }
            if (array_filter($row, static fn (string $value): bool => trim($value) !== '') !== []) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function normaliseHeader(string $name): string
    {
        return (string) Str::of($name)->trim()->lower()->ascii()->replaceMatches('/[\s\-]+/', '_')->replaceMatches('/[^a-z0-9_]/', '');
    }

    /** @return array<string, list<string>> */
    private function columns(ImportSource $source): array
    {
        $columns = self::COLUMNS;
        $declared = is_array($source->mapping) ? ($source->mapping['columns'] ?? null) : null;
        if (is_array($declared)) {
            foreach ($declared as $field => $name) {
                if (isset($columns[$field]) && is_string($name) && $name !== '') {
                    array_unshift($columns[$field], $this->normaliseHeader($name));
                }
            }
        }

        return $columns;
    }

    private function hasTime(?string $value): bool
    {
        return $value !== null && preg_match('/\d{1,2}[:.]\d{2}/', $value) === 1;
    }

    private function flag(?string $value): bool
    {
        return $value !== null && in_array(Str::lower($value), ['1', 'si', 'sì', 'yes', 'true', 'x', 'vero'], true);
    }

    /**
     * Una data (con o senza ora) nel fuso del locale. `$time` è la colonna
     * dell'ora separata; `$sameDayAs` serve a leggere una fine scritta come
     * sola ora («23:30»): è dello stesso giorno dell'inizio, o del giorno dopo
     * se viene prima.
     */
    private function instant(?string $date, ?string $time, string $timezone, ?CarbonImmutable $sameDayAs = null): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }
        $text = trim($date);
        if ($time !== null) {
            $text .= ' '.trim($time);
        }
        if ($sameDayAs !== null && preg_match('/^\d{1,2}[:.]\d{2}$/', $text) === 1) {
            $candidate = $sameDayAs->setTimeFromTimeString(str_replace('.', ':', $text));

            return $candidate->lessThanOrEqualTo($sameDayAs) ? $candidate->addDay() : $candidate;
        }
        $text = preg_replace('/(\d{1,2})\.(\d{2})(?!\d)/', '$1:$2', $text) ?? $text;
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'd/m/Y H:i', 'd/m/Y H:i:s', 'd-m-Y H:i', 'd.m.Y H:i', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $text, $timezone);
            } catch (Throwable) {
                $parsed = null;
            }
            if ($parsed instanceof CarbonImmutable && $parsed->format($format) === $text) {
                return str_contains($format, 'H') ? $parsed : $parsed->startOfDay();
            }
        }
        try {
            return CarbonImmutable::parse($text, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function isUpload(ImportSource $source): bool
    {
        return str_starts_with((string) $source->url, self::UPLOAD_PREFIX);
    }

    /**
     * Il file caricato dal pannello: solo il nome, dentro la cartella del
     * locale, così un indirizzo scritto a mano non legge niente altro.
     */
    private function readUpload(ImportSource $source): string
    {
        $name = basename(substr((string) $source->url, strlen(self::UPLOAD_PREFIX)));
        $path = self::uploadPath($source, $name);
        if ($name === '' || ! Storage::disk('local')->exists($path)) {
            throw ImportException::unreachable((string) $source->url, __('import.csv.missing_file'));
        }
        $size = Storage::disk('local')->size($path);
        if ($size > config()->integer('import.max_bytes')) {
            throw ImportException::tooLarge(config()->integer('import.max_bytes'));
        }

        return (string) Storage::disk('local')->get($path);
    }

    public static function uploadPath(ImportSource $source, string $name): string
    {
        return self::UPLOAD_DIRECTORY.'/'.($source->venue_id ?? 'city-'.$source->city_id).'/'.basename($name);
    }
}
