<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * «Aperto adesso», calcolato sugli orari del locale nel fuso della città.
 *
 * Gli orari sono `{"mon": [{"open": "10:00", "close": "13:00"}, …], …}`.
 * Una chiusura che viene prima dell'apertura significa dopo mezzanotte: la
 * fascia del venerdì 21:00–02:00 tiene aperto il locale fino alle due di
 * sabato. Senza orari la risposta è `null`, non «chiuso»: non sappiamo.
 */
final class OpeningHours
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param  array<string, mixed>|null  $hours
     * @return array{open: bool|null, until: string|null}
     */
    public static function state(?array $hours, DateTimeInterface $at, string $timezone): array
    {
        if ($hours === null || $hours === []) {
            return ['open' => null, 'until' => null];
        }
        $now = CarbonImmutable::instance($at)->setTimezone($timezone);
        $minute = $now->hour * 60 + $now->minute;
        $today = self::DAYS[$now->dayOfWeekIso - 1];
        $yesterday = self::DAYS[($now->dayOfWeekIso + 5) % 7];

        foreach (self::ranges($hours[$yesterday] ?? null) as [$open, $close]) {
            if ($close <= $open && $minute < $close) {
                return ['open' => true, 'until' => self::clock($close)];
            }
        }
        foreach (self::ranges($hours[$today] ?? null) as [$open, $close]) {
            $overnight = $close <= $open;
            if ($minute >= $open && ($overnight || $minute < $close)) {
                return ['open' => true, 'until' => self::clock($close)];
            }
        }

        return ['open' => false, 'until' => null];
    }

    /** @return list<array{0: int, 1: int}> */
    private static function ranges(mixed $day): array
    {
        if (! is_array($day)) {
            return [];
        }
        $ranges = [];
        foreach ($day as $range) {
            $open = is_array($range) ? self::minutes($range['open'] ?? null) : null;
            $close = is_array($range) ? self::minutes($range['close'] ?? null) : null;
            if ($open !== null && $close !== null && $open !== $close) {
                $ranges[] = [$open, $close];
            }
        }

        return $ranges;
    }

    private static function minutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];

        return $hour <= 24 && $minute < 60 ? min(24 * 60, $hour * 60 + $minute) : null;
    }

    private static function clock(int $minutes): string
    {
        $minutes %= 24 * 60;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
