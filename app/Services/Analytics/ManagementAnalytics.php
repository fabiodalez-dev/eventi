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
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ManagementAnalytics
{
    public function subject(): Venue|Organizer
    {
        $subject = Filament::getTenant();
        $user = auth()->user();
        $panel = Filament::getCurrentPanel()?->getId();
        abort_unless(($panel === 'venue' && $subject instanceof Venue)
            || ($panel === 'organizer' && $subject instanceof Organizer), 403);
        abort_unless($user instanceof User && $user->canAccessTenant($subject), 403);

        return $subject;
    }

    /** @return Builder<Event> */
    public function eventQuery(): Builder
    {
        $subject = $this->subject();

        return Event::query()->where($subject instanceof Venue ? 'venue_id' : 'organizer_id', $subject->getKey());
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
            $series[] = ['date' => $day->format('d/m'), 'views' => (int) ($row->views ?? 0),
                'profile_views' => (int) ($profileDaily->get($key)->views ?? 0),
                'clicks' => array_sum(array_map(fn ($column) => (int) ($row?->getAttribute($column) ?? 0), array_diff($columns, ['views', 'shares'])))];
        }

        return ['totals' => $totals, 'profile' => $profile, 'series' => $series,
            'impressions' => (int) ($paid->impressions ?? 0), 'sponsored_clicks' => (int) ($paid->clicks ?? 0), 'breakdown' => $breakdown];
    }
}
