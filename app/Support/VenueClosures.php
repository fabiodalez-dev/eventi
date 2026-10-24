<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;

/**
 * Le chiusure straordinarie di un locale: ferie, lavori, un giorno di festa.
 *
 * Stanno in `venues.closures` come `[{"from": "2026-12-24", "to": "2027-01-06",
 * "note": "Ferie di Natale"}, …]`, giorni di calendario nel fuso della città,
 * estremi compresi. Valgono sopra gli orari settimanali: in quei giorni il
 * locale è chiuso anche se l'orario direbbe aperto. Le chiusure finite si
 * tolgono al salvataggio, così l'elenco resta corto.
 */
final class VenueClosures
{
    public const MAX = 20;

    /**
     * Le righe del modulo, pulite: date valide, fine non prima dell'inizio,
     * solo chiusure non ancora finite, in ordine.
     *
     * @param  array<int|string, mixed>|null  $rows
     * @return list<array{from: string, to: string, note: string|null}>|null
     */
    public static function normalize(?array $rows, string $timezone, ?DateTimeInterface $at = null): ?array
    {
        $rows = array_values(array_filter($rows ?? [], 'is_array'));
        Validator::make(['closures' => $rows], [
            'closures' => ['array', 'max:'.self::MAX],
            'closures.*.from' => ['required', 'date_format:Y-m-d'],
            'closures.*.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:closures.*.from'],
            'closures.*.note' => ['nullable', 'string', 'max:120'],
        ], [], ['closures.*.from' => __('manage.closures.from'), 'closures.*.to' => __('manage.closures.to'), 'closures.*.note' => __('manage.closures.note')])->validate();
        $today = self::today($timezone, $at);
        $closures = [];
        foreach ($rows as $row) {
            if ($row['to'] < $today) {
                continue;
            }
            $note = is_string($row['note'] ?? null) && trim($row['note']) !== '' ? trim($row['note']) : null;
            $closures[$row['from'].$row['to']] = ['from' => (string) $row['from'], 'to' => (string) $row['to'], 'note' => $note];
        }
        ksort($closures);

        return $closures === [] ? null : array_values($closures);
    }

    /**
     * La chiusura che copre il giorno dato (oggi, se non si dice), o null.
     *
     * @param  array<int|string, mixed>|null  $closures
     * @return array{from: string, to: string, note: string|null}|null
     */
    public static function covering(?array $closures, string $timezone, ?DateTimeInterface $at = null, ?string $day = null): ?array
    {
        $day ??= self::today($timezone, $at);
        foreach (self::valid($closures) as $closure) {
            if ($closure['from'] <= $day && $day <= $closure['to']) {
                return $closure;
            }
        }

        return null;
    }

    /**
     * Le chiusure in corso o in arrivo.
     *
     * @param  array<int|string, mixed>|null  $closures
     * @return list<array{from: string, to: string, note: string|null}>
     */
    public static function upcoming(?array $closures, string $timezone, ?DateTimeInterface $at = null): array
    {
        $today = self::today($timezone, $at);

        return array_values(array_filter(self::valid($closures), static fn (array $closure): bool => $closure['to'] >= $today));
    }

    /**
     * @param  array<int|string, mixed>|null  $closures
     * @return list<array{from: string, to: string, note: string|null}>
     */
    private static function valid(?array $closures): array
    {
        $valid = [];
        foreach ($closures ?? [] as $closure) {
            if (is_array($closure) && is_string($closure['from'] ?? null) && is_string($closure['to'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $closure['from']) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $closure['to']) === 1) {
                $valid[] = ['from' => $closure['from'], 'to' => $closure['to'], 'note' => is_string($closure['note'] ?? null) ? $closure['note'] : null];
            }
        }
        usort($valid, static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        return $valid;
    }

    private static function today(string $timezone, ?DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone($timezone)->format('Y-m-d');
    }
}
