<?php

declare(strict_types=1);

namespace App\Services\Editorial\Promotion;

use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Http\Resources\V1\Management\SponsorshipPayload;
use App\Models\Event;
use App\Services\Sponsorship\Editorial\UnitProgress;
use App\Support\Api\ApiDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * I pezzi comuni delle risposte della promozione nella redazione dell'app
 * (R8): l'evento di una riga, l'avanzamento di un'unità come lo scrive il
 * pannello (colonna «Avanzamento» e scheda «Avanzamento»), le scelte dei
 * moduli. I testi arrivano già scritti dal server, con le stesse parole del
 * pannello; gli istanti sono ISO 8601 nel fuso della città.
 */
final class PromotionPayload
{
    /**
     * Un evento in una riga: locandina, titolo, locale, la prossima data, e
     * se è pubblicato (una campagna su un evento non pubblicato non compare).
     *
     * @return array<string, mixed>|null
     */
    public static function event(?Event $event, string $timezone, mixed $when = null): ?array
    {
        $row = SponsorshipPayload::event($event, $timezone, $when);

        if ($row === null || $event === null) {
            return null;
        }

        return [...$row, 'published' => $event->status === EventStatus::Published];
    }

    /** @return array{value: string, label: string} */
    public static function placement(SponsorshipPlacement $placement): array
    {
        return SponsorshipPayload::placement($placement);
    }

    /**
     * L'avanzamento nella colonna degli elenchi: stato, numeri, barra.
     *
     * @return array<string, mixed>|null
     */
    public static function progress(?UnitProgress $progress): ?array
    {
        if ($progress === null) {
            return null;
        }

        $n = static fn (int|float $value): string => number_format((float) $value, 0, ',', '.');

        return [
            'state' => $progress->stateKey(),
            'state_label' => $progress->stateLabel(),
            'color' => $progress->color(),
            'goal' => $progress->goal,
            'delivered' => $progress->delivered,
            'percent' => $progress->percent() === null ? null : round($progress->percent(), 1),
            'expected_percent' => $progress->expectedPercent() === null ? null : round($progress->expectedPercent(), 1),
            'summary' => $progress->hasGoal()
                ? __('sponsorships.progress.of_goal', ['delivered' => $n($progress->delivered), 'goal' => $n((int) $progress->goal)])
                : trans_choice('sponsorships.progress.views_only', $progress->delivered, ['count' => $n($progress->delivered)]),
        ];
    }

    /**
     * La scheda «Avanzamento» di una campagna o di un'abilitazione
     * (`ProgressDetail::for`), con le frasi della vista del pannello.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public static function progressDetail(array $detail): array
    {
        $progress = $detail['progress'] ?? null;

        if (! $progress instanceof UnitProgress) {
            return ['progress' => null, 'empty' => __('sponsorships.progress.no_unit')];
        }

        $n = static fn (int|float $value): string => number_format((float) $value, 0, ',', '.');
        $tz = $progress->timezone;
        $quotaMax = (float) ($detail['quotaMax'] ?? 0.8);
        /** @var list<array{day: CarbonImmutable, delivered: int, expected: int}> $daily */
        $daily = $detail['daily'] ?? [];
        /** @var list<array<string, mixed>> $events */
        $events = $detail['events'] ?? [];

        return [
            'progress' => self::progress($progress),
            'true_end' => __('sponsorships.progress.true_end', ['date' => self::moment($progress->end, $tz)]),
            'sentence' => $progress->hasGoal()
                ? __('sponsorships.progress.sentence', ['delivered' => $n($progress->delivered), 'goal' => $n((int) $progress->goal), 'state' => mb_strtolower($progress->stateLabel())])
                : trans_choice('sponsorships.progress.period_sentence', $progress->delivered, ['count' => $n($progress->delivered)]),
            'legend' => $progress->hasGoal() ? trans_choice('sponsorships.progress.bar_legend', (int) round($progress->expected), ['expected' => $n($progress->expected)]) : null,
            'cards' => array_values(array_filter([
                ['key' => 'today', 'label' => __('sponsorships.progress.delivered_today'), 'value' => $n($progress->deliveredToday), 'hint' => __('sponsorships.progress.delivered_today_hint')],
                $progress->hasGoal() ? ['key' => 'quota', 'label' => __('sponsorships.progress.required_quota'), 'value' => (string) $progress->quotaLabel($quotaMax), 'hint' => __('sponsorships.progress.required_quota_hint')] : null,
                ['key' => 'days', 'label' => __('sponsorships.progress.days_left'), 'value' => $n($progress->daysLeft), 'hint' => __('sponsorships.progress.days_left_hint')],
            ])),
            'provisional' => $progress->hasGoal() && (bool) ($detail['provisional'] ?? false)
                ? __('sponsorships.progress.provisional', ['days' => (int) ($detail['minDays'] ?? 14)])
                : null,
            'daily' => $progress->hasGoal() ? array_map(fn (array $row): array => [
                'date' => $row['day']->toDateString(),
                'label' => self::day($row['day'], $tz),
                'delivered' => $row['delivered'],
                'expected' => $row['expected'],
                'text' => __('sponsorships.progress.daily_row', ['delivered' => $n($row['delivered']), 'expected' => $n($row['expected'])]),
            ], array_reverse($daily)) : [],
            'daily_empty' => $progress->hasGoal() && $daily === [] ? __('sponsorships.progress.daily_empty') : null,
            'events' => array_map(function (array $row) use ($n, $tz): array {
                $event = $row['event'] instanceof Event ? $row['event'] : null;
                $eventTz = (string) ($event?->city?->timezone ?: $tz);

                return [
                    'id' => (int) $row['row']->getKey(),
                    'event' => self::event($event, $eventTz, $row['next'] ?? null),
                    'event_gone' => $event === null ? __('sponsorships.analytics.event_gone') : null,
                    'numbers' => __('sponsorships.progress.event_numbers', ['views' => $n($row['impressions']), 'clicks' => $n($row['clicks'])]),
                    'share' => $row['share'] === null ? __('sponsorships.progress.event_no_share') : __('sponsorships.progress.event_share', ['share' => $n($row['share'] * 100)]),
                ];
            }, $events),
            'events_lead' => $events === [] ? null : __('sponsorships.progress.events_lead'),
        ];
    }

    /**
     * Le scelte di una tendina, `{value, label}`.
     *
     * @param  array<int|string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    public static function choices(array $options): array
    {
        $rows = [];
        foreach ($options as $value => $label) {
            $rows[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $rows;
    }

    /** Un istante ISO 8601 nel fuso indicato. */
    public static function instant(?DateTimeInterface $value, string $timezone): ?string
    {
        return ApiDate::instant($value, $timezone);
    }

    /** Un importo in centesimi scritto in euro per il modulo («150.00»). */
    public static function euros(?int $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, '.', '');
    }

    /** «sab 22 nov 2026, 21:00» nel fuso della città. */
    public static function moment(?DateTimeInterface $value, string $timezone): string
    {
        if ($value === null) {
            return '—';
        }

        return CarbonImmutable::instance($value)->setTimezone($timezone)->locale('it')->isoFormat('ddd D MMM YYYY, HH:mm');
    }

    /** «sab 22 nov». */
    public static function day(?DateTimeInterface $value, string $timezone): string
    {
        if ($value === null) {
            return '—';
        }

        return CarbonImmutable::instance($value)->setTimezone($timezone)->locale('it')->isoFormat('ddd D MMM');
    }
}
