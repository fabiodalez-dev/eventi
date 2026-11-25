<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Management;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Http\Resources\V1\PosterResource;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Services\Sponsorship\UnitProgress;
use App\Support\Api\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Le righe della pagina «Sponsorizzazioni» e delle sue statistiche nella
 * forma dell'API di gestione (P6). I testi che il pannello scrive dal server
 * — lo stato di un ordine, la frase sotto la barra, il prezzo da concordare —
 * arrivano già scritti, così l'app dice la stessa cosa con le stesse parole.
 * Gli istanti sono ISO 8601 nel fuso della città del committente.
 */
final class SponsorshipPayload
{
    /** @return array{value: string, label: string} */
    public static function placement(SponsorshipPlacement $placement): array
    {
        return ['value' => $placement->value, 'label' => $placement->label()];
    }

    /** «150,00 €», o «Prezzo da concordare» senza importo. */
    public static function money(?int $cents, string $currency = 'EUR'): string
    {
        return $cents === null || $cents < 1
            ? __('promotions.request.price_to_agree')
            : (string) Number::currency($cents / 100, in: strtoupper($currency) ?: 'EUR', locale: 'it');
    }

    public static function months(int $months): string
    {
        return trans_choice('promotions.page.months', $months, ['count' => $months]);
    }

    /**
     * Un evento in una riga: locandina, titolo, la data da mostrare.
     *
     * @return array<string, mixed>|null
     */
    public static function event(?Event $event, string $timezone, mixed $when = null): ?array
    {
        if ($event === null) {
            return null;
        }

        $date = $when ?? $event->getAttribute('next_starts_at');

        return [
            'id' => (int) $event->getKey(),
            'title' => (string) $event->title,
            'slug' => (string) $event->slug,
            'poster' => PosterResource::toArray($event),
            'venue' => $event->relationLoaded('venue') ? $event->venue?->name : null,
            'date' => $date === null ? null : CarbonImmutable::parse((string) $date, 'UTC')->setTimezone($timezone)->toIso8601String(),
        ];
    }

    /**
     * L'avanzamento di un'unità di consegna: la barra, la tacca dell'atteso,
     * il distintivo, la frase e le due note sotto, come il riquadro del
     * pannello (`filament.shared.sponsorship-progress`).
     *
     * @return array<string, mixed>
     */
    public static function progress(UnitProgress $progress): array
    {
        $expected = $progress->expectedPercent();
        $provisional = $progress->hasGoal() && ! $progress->ended() && ! $progress->completed() && $progress->forecast->isProvisional();
        $daysLeft = $progress->hasGoal() && $progress->started() && ! $progress->ended() && ! $progress->completed() ? $progress->daysLeft() : null;
        $notes = array_values(array_filter([
            $expected !== null ? __('sponsorship-panel.progress.expected_marker') : null,
            $provisional ? __('sponsorship-panel.progress.provisional', ['days' => (int) config('sponsorship.forecast_min_days', 14)]) : null,
        ]));

        return [
            'key' => $progress->key,
            'goal' => $progress->goal,
            'delivered' => $progress->delivered,
            'clicks' => $progress->clicks,
            'percent' => round($progress->percent(), 1),
            'expected_percent' => $expected === null ? null : round($expected, 1),
            /* Il colore del pannello (`success`, `warning`, `danger`, `info`, `gray`, `primary`): la frase dice lo stesso a parole. */
            'color' => $progress->color(),
            'state' => $progress->state?->value,
            'badge' => $progress->badge(),
            'show_badge' => $progress->hasGoal(),
            'numbers' => $progress->numbers(),
            'phrase' => $progress->phrase(),
            'days_left' => $daysLeft,
            'days_left_label' => $daysLeft === null ? null : trans_choice('sponsorship-panel.progress.days_left', $daysLeft, ['count' => $daysLeft]),
            'notes' => $notes,
            'started' => $progress->started(),
            'ended' => $progress->ended(),
            'completed' => $progress->completed(),
            'halted' => $progress->halted,
            'starts_at' => $progress->start->toIso8601String(),
            'ends_at' => $progress->end->toIso8601String(),
            'true_ends_at' => $progress->trueEnd->toIso8601String(),
        ];
    }

    /**
     * Un ordine o una richiesta, con i pulsanti che il pannello mostrerebbe a
     * chi guarda.
     *
     * @return array<string, mixed>
     */
    public static function order(SponsorshipOrder $order, string $timezone, bool $canPurchase): array
    {
        $status = $order->status;

        return [
            'id' => (int) $order->getKey(),
            'placement' => self::placement($order->placement),
            'months' => (int) $order->months,
            'months_label' => self::months((int) $order->months),
            'mode' => $order->mode->value,
            'event' => self::event($order->event, $timezone),
            'what' => $order->event !== null ? (string) $order->event->title : __('promotions.page.scope_all'),
            'created_at' => ApiDate::attribute($order, 'created_at', $timezone),
            'amount_cents' => (int) $order->amount_cents,
            'currency' => (string) $order->currency,
            'amount_label' => self::money((int) $order->amount_cents, (string) $order->currency),
            'status' => ['value' => $status->value, 'label' => $status->label(), 'color' => $status->color()],
            'reason' => $status === SponsorshipOrderStatus::Rejected && filled($order->decision_note)
                ? __('promotions.page.reason', ['reason' => Str::before((string) $order->decision_note, "\n")]) : null,
            'hint' => $status === SponsorshipOrderStatus::Requested ? __('promotions.page.requested_hint') : null,
            'request_note' => $order->request_note,
            'can_retry' => $canPurchase && $status === SponsorshipOrderStatus::PendingPayment,
            'can_cancel' => $canPurchase && $status === SponsorshipOrderStatus::PendingPayment,
            'can_withdraw' => $canPurchase && $status === SponsorshipOrderStatus::Requested,
        ];
    }

    /**
     * La scheda di un'abilitazione: posizione, stato, periodo, pagamento,
     * avanzamento ed eventi in vetrina (tutti: «mostra gli altri» lo fa l'app).
     *
     * @param  Collection<int, Sponsorship>  $rows
     * @param  array<int, string>  $dates
     * @return array<string, mixed>
     */
    public static function grant(SponsorshipGrant $grant, UnitProgress $progress, Collection $rows, array $dates, string $timezone, User $viewer, bool $isVenue): array
    {
        $selected = $grant->mode === PromotionMode::Selected;

        return [
            'id' => (int) $grant->getKey(),
            'placement' => self::placement($grant->placement),
            'mode' => $grant->mode->value,
            'mode_label' => __($selected ? 'sponsorship-panel.units.scope_selected' : 'sponsorship-panel.units.scope_all'),
            'status_label' => $grant->statusLabel(),
            'starts_at' => ApiDate::instant($grant->starts_at, $timezone),
            'ends_at' => ApiDate::instant($grant->ends_at, $timezone),
            'complimentary' => (bool) $grant->complimentary,
            'paid_at' => ApiDate::instant($grant->paid_at, $timezone),
            'payment_label' => $grant->complimentary ? __('promotions.complimentary') : ($grant->paid_at !== null ? __('promotions.paid') : __('promotions.awaiting_payment')),
            'progress' => self::progress($progress),
            'events' => $rows->map(function (Sponsorship $row) use ($progress, $rows, $dates, $timezone, $viewer, $isVenue, $selected): array {
                $share = $progress->delivered > 0 && $rows->count() > 1 ? (int) round(100 * $row->impressions / $progress->delivered) : null;
                $active = $row->status === SponsorshipStatus::Active;
                $event = self::event($row->event, $timezone, $dates[(int) $row->event_id] ?? null);
                if ($event !== null && $isVenue) {
                    $event['venue'] = null;
                }

                return [
                    'id' => (int) $row->getKey(),
                    'event' => $event,
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                    'numbers' => __('sponsorship-panel.units.event_numbers', ['views' => UnitProgress::number((int) $row->impressions), 'clicks' => UnitProgress::number((int) $row->clicks)]),
                    'share' => $share,
                    'share_label' => $share === null ? null : __('sponsorship-panel.units.event_share', ['share' => $share]),
                    'active' => $active,
                    'stopped_label' => $active ? null : __('sponsorship-panel.units.event_stopped'),
                    'can_stop' => $selected && $active && ! $progress->ended() && $row->event !== null && Gate::forUser($viewer)->allows('update', $row->event),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Una campagna della redazione (senza abilitazione) sui propri eventi.
     *
     * @param  array<int, string>  $dates
     * @return array<string, mixed>
     */
    public static function editorialCampaign(Sponsorship $campaign, UnitProgress $progress, array $dates, string $timezone): array
    {
        $phase = $campaign->phase();

        return [
            'id' => (int) $campaign->getKey(),
            'event' => self::event($campaign->event, $timezone, $dates[(int) $campaign->event_id] ?? null),
            'placement' => self::placement($campaign->placement),
            'phase' => ['value' => $phase->value, 'label' => $phase->label(), 'color' => $phase->color()],
            'starts_at' => ApiDate::instant($campaign->starts_at, $timezone),
            'ends_at' => ApiDate::instant($campaign->ends_at, $timezone),
            'progress' => self::progress($progress),
        ];
    }

    /**
     * Una campagna in un elenco di scelta (il filtro delle statistiche).
     *
     * @return array<string, mixed>
     */
    public static function campaignOption(Sponsorship $campaign): array
    {
        $timezone = (string) ($campaign->event?->city?->timezone ?: 'Europe/Rome');

        return [
            'id' => (int) $campaign->getKey(),
            'event' => self::event($campaign->event, $timezone),
            'event_gone' => $campaign->event === null ? __('sponsorships.analytics.event_gone') : null,
            'placement' => self::placement($campaign->placement),
            'starts_at' => ApiDate::instant($campaign->starts_at, $timezone),
            'ends_at' => ApiDate::instant($campaign->ends_at, $timezone),
        ];
    }

    /**
     * Una riga del registro dei clic: evento, ora, locale, canale, posizione
     * e pagina, come nel pannello. Nessun dato della persona.
     *
     * @return array<string, mixed>
     */
    public static function click(SponsorshipClick $click, string $timezone): array
    {
        $event = $click->sponsorship?->event;
        $placement = SponsorshipPlacement::tryFrom((string) $click->placement)?->label()
            ?? (Lang::has('sponsorships.analytics.placements.'.$click->placement) ? __('sponsorships.analytics.placements.'.$click->placement) : (string) $click->placement);
        $page = in_array($click->page, ['home', 'feed', 'event', 'venues', 'profile', 'search'], true) ? $click->page : 'other';

        return [
            'id' => (int) $click->getKey(),
            'clicked_at' => ApiDate::instant($click->clicked_at, $timezone),
            'event' => $event === null ? null : ['id' => (int) $event->getKey(), 'title' => (string) $event->title, 'poster' => PosterResource::toArray($event)],
            'title' => $event !== null ? (string) $event->title : __('sponsorships.analytics.event_gone'),
            'venue' => $event?->venue?->name,
            'channel' => __('sponsorships.analytics.channels.'.($click->channel === 'android' ? 'android' : 'web')),
            'placement' => $placement,
            'page' => __('sponsorships.analytics.pages.'.$page),
        ];
    }

    /**
     * La frase sotto i totali del periodo, la stessa del pannello.
     */
    public static function explain(int $impressions, int $clicks, bool $rateReady): string
    {
        $number = fn (int $n): string => UnitProgress::number($n);

        if ($impressions === 0) {
            return __('pannelli-social.sponsorship.explain_empty');
        }
        if ($clicks === 0) {
            return __('pannelli-social.sponsorship.explain_none', ['views' => $number($impressions)]);
        }

        $text = trans_choice('pannelli-social.sponsorship.explain', $clicks, ['views' => $number($impressions), 'clicks' => $number($clicks)]);

        return $rateReady
            ? $text.' '.__('pannelli-social.sponsorship.explain_rate', ['every' => $number(max(1, (int) round($impressions / $clicks)))])
            : $text;
    }
}
