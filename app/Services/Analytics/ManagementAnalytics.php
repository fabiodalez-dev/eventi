<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\ContentMetric;
use App\Enums\StatsPeriod;
use App\Models\Event;
use App\Models\EventViewDaily;
use App\Models\Follow;
use App\Models\Organizer;
use App\Models\SavedEvent;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ManagementAnalytics
{
    public function subject(): Venue|Organizer
    {
        /* Il soggetto del pannello o quello legato dall'API: lo stesso
           controllo in entrambi i casi, la barriera dei pannelli. */
        $subject = ManagementSubject::current();
        abort_unless($subject !== null && $subject->user->canAccessTenant($subject->model), 403);

        return $subject->model;
    }

    /**
     * Gli eventi di cui il soggetto vede le statistiche: quelli del locale,
     * oppure quelli di cui l'organizzazione è l'organizzatore principale.
     * Gli eventi soltanto co-organizzati restano fuori, nel pannello come
     * nell'app: i loro numeri sono di chi li organizza.
     *
     * @return Builder<Event>
     */
    public function eventQuery(): Builder
    {
        $subject = $this->subject();

        return Event::query()->where(self::column($subject), $subject->getKey());
    }

    /** Se le statistiche di un evento sono del soggetto: la stessa regola di `eventQuery`. */
    public static function covers(Venue|Organizer $subject, Event $event): bool
    {
        return (int) $event->getAttribute(self::column($subject)) === (int) $subject->getKey();
    }

    private static function column(Venue|Organizer $subject): string
    {
        return $subject instanceof Venue ? 'venue_id' : 'organizer_id';
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function bounds(StatsPeriod $period): array
    {
        $until = CarbonImmutable::now($this->subject()->city->timezone)->endOfDay();

        return [$until->subDays($period->days() - 1)->startOfDay(), $until];
    }

    /** @return Builder<Event> */
    public function events(StatsPeriod $period): Builder
    {
        [$from, $until] = $this->bounds($period);
        $dates = [$from->toDateString(), $until->toDateString()];
        $query = $this->eventQuery();
        foreach (ContentMetric::cases() as $metric) {
            $query->withSum(['dailyViews as '.$metric->value.'_total' => fn (Builder $q) => $q->whereBetween('date', $dates)], $metric->value);
        }
        $query->selectSub(SavedEvent::query()->selectRaw('COUNT(*)')
            ->join('event_occurrences', 'event_occurrences.id', '=', 'saved_events.occurrence_id')
            ->whereColumn('event_occurrences.event_id', 'events.id')
            ->whereBetween('saved_events.created_at', [$from->utc(), $until->utc()]), 'saves_total');
        $sum = implode(' + ', array_map(fn (ContentMetric $metric) => 'COALESCE('.$metric->value.'_total, 0)', ContentMetric::cases()));

        return $query->havingRaw('('.$sum.' + saves_total) > 0')->orderByDesc('views_total')->orderBy('events.id');
    }

    /** @return array<string, mixed> */
    public function report(StatsPeriod $period): array
    {
        $subject = $this->subject();
        [$from, $until] = $this->bounds($period);
        $dates = [$from->toDateString(), $until->toDateString()];
        $instants = [$from->utc(), $until->utc()];
        $ids = $this->eventQuery()->select('events.id');
        $columns = array_map(fn (ContentMetric $metric) => $metric->value, ContentMetric::cases());
        $sum = implode(', ', array_map(fn (string $column) => 'SUM('.$column.') as '.$column, $columns));
        $daily = EventViewDaily::query()->whereIn('event_id', clone $ids)->whereBetween('date', $dates)
            ->selectRaw('date, '.$sum)->groupBy('date')->orderBy('date')->get();
        $totals = [];
        foreach ($columns as $column) {
            $totals[$column] = (int) $daily->sum($column);
        }
        $totals['saves'] = SavedEvent::query()->whereHas('occurrence', fn (Builder $q) => $q->whereIn('event_id', clone $ids))
            ->whereBetween('created_at', $instants)->count();
        $profileType = $subject instanceof Venue ? 'venue' : 'organizer';
        $profileDaily = DB::table('profile_views_daily')->where('profile_type', $profileType)->where('profile_id', $subject->getKey())
            ->whereBetween('date', $dates)->orderBy('date')->get()->keyBy('date');
        $profile = [];
        foreach ($columns as $column) {
            $profile[$column] = (int) $profileDaily->sum($column);
        }
        $followers = Follow::query()->where('followable_type', $profileType)->where('followable_id', $subject->getKey());
        $profile['followers'] = (clone $followers)->count();
        $profile['new_followers'] = $followers->whereBetween('created_at', $instants)->count();

        // Paid exposure is never added to page openings.
        $campaigns = Sponsorship::withTrashed()->whereIn('event_id', clone $ids);
        if ($subject instanceof Venue) {
            $campaigns->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where('venue_id', $subject->getKey())));
        }
        $campaignIds = $campaigns->select('sponsorships.id');
        $paid = SponsorshipDailyStat::query()->whereIn('sponsorship_id', clone $campaignIds)->whereBetween('day', $dates)
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')->first();
        $breakdown = SponsorshipClick::query()->whereIn('sponsorship_id', clone $campaignIds)->whereBetween('clicked_at', $instants)
            ->selectRaw('channel, placement, page, COUNT(*) as clicks')->groupBy('channel', 'placement', 'page')->orderByDesc('clicks')->get();
        $byDate = $daily->keyBy(fn ($row) => $row->date->toDateString());
        $series = [];
        for ($day = $from; $day->lte($until); $day = $day->addDay()) {
            $key = $day->toDateString();
            $row = $byDate->get($key);
            $series[] = ['date' => $day->format('d/m'), 'day' => $key, 'views' => (int) ($row->views ?? 0),
                'profile_views' => (int) ($profileDaily->get($key)->views ?? 0),
                'clicks' => array_sum(array_map(fn ($column) => (int) ($row?->getAttribute($column) ?? 0), array_diff($columns, ['views', 'shares'])))];
        }

        return ['totals' => $totals, 'profile' => $profile, 'series' => $series,
            'impressions' => (int) ($paid->impressions ?? 0), 'sponsored_clicks' => (int) ($paid->clicks ?? 0), 'breakdown' => $breakdown];
    }
}
