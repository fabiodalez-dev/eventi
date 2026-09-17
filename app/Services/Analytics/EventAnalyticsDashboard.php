<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\BookingStatus;
use App\Enums\ContentMetric;
use App\Enums\EventCommentStatus;
use App\Enums\VenueReviewStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use stdClass;

/** Only aggregated product data, never visitor identities or ticket secrets. */
final class EventAnalyticsDashboard
{
    /** @return EloquentBuilder<Event> */
    public function events(): EloquentBuilder
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            abort_unless(auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true, 403);

            return Event::query();
        }

        return app(ManagementAnalytics::class)->eventQuery();
    }

    /** @return EloquentBuilder<Venue> */
    public function venues(): EloquentBuilder
    {
        $events = $this->events();
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return Venue::query();
        }
        $tenant = Filament::getTenant();

        return $tenant instanceof Venue ? Venue::whereKey($tenant->id)
            : Venue::whereIn('id', $events->select('venue_id'));
    }

    /** @return EloquentBuilder<Organizer> */
    public function organizers(): EloquentBuilder
    {
        $events = $this->events();
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return Organizer::query();
        }
        $tenant = Filament::getTenant();

        return $tenant instanceof Organizer ? Organizer::whereKey($tenant->id)
            : Organizer::whereIn('id', $events->select('organizer_id'));
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $filters = Validator::make($filters, [
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:until'],
            'until' => ['required', 'date_format:Y-m-d'],
            'venue' => ['nullable', 'integer', 'min:1'],
            'organizer' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'in:'.implode(',', EventShares::CHANNELS)],
        ])->validate();
        $events = $this->events();
        $filterLabels = $filters;
        foreach (['venue' => $this->venues(), 'organizer' => $this->organizers(), 'event' => $this->events()] as $filter => $allowed) {
            if (filled($filters[$filter] ?? null)) {
                abort_unless($allowed->whereKey($filters[$filter])->exists(), 403);
                $filterLabels[$filter] = $allowed->value($filter === 'event' ? 'title' : 'name');
                $events->where($filter === 'event' ? 'events.id' : $filter.'_id', $filters[$filter]);
            }
        }
        if (filled($filters['channel'] ?? null)) {
            $filterLabels['channel'] = __('event-shares.channels.'.$filters['channel']);
        }
        $ids = (clone $events)->select('events.id');
        $metrics = array_column(ContentMetric::cases(), 'value');
        $sum = implode(', ', array_map(fn ($column) => 'SUM(d.'.$column.') as '.$column, $metrics));
        $content = $this->window(DB::table('event_views_daily as d')->join('events as e', 'e.id', '=', 'd.event_id')
            ->whereIn('e.id', clone $ids), 'd.date', $filters);
        $short = $this->window(DB::table('event_share_daily as d')->join('event_share_links as l', 'l.id', '=', 'd.share_link_id')
            ->join('events as e', 'e.id', '=', 'l.event_id')->whereIn('e.id', clone $ids)
            ->when(filled($filters['channel'] ?? null), fn (Builder $q) => $q->where('l.channel', $filters['channel'])), 'd.date', $filters);
        $contentByEvent = (clone $content)->selectRaw('e.id, '.$sum)->groupBy('e.id')->get()->keyBy('id');
        $shortByEvent = (clone $short)->selectRaw('e.id, SUM(d.shares) as short_shares, SUM(d.clicks) as short_clicks')
            ->groupBy('e.id')->get()->keyBy('id');
        $activity = $this->activity($ids, $filters);
        $campaigns = Sponsorship::withTrashed()->whereIn('event_id', clone $ids);
        $tenant = Filament::getTenant();
        if (Filament::getCurrentPanel()?->getId() === 'venue' && $tenant instanceof Venue) {
            $campaigns->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->where('venue_id', $tenant->id)));
        }
        $paid = $this->window(DB::table('sponsorship_daily_stats as d')->join('sponsorships as s', 's.id', '=', 'd.sponsorship_id')
            ->join('events as e', 'e.id', '=', 's.event_id')->whereIn('s.id', $campaigns->select('sponsorships.id')), 'd.day', $filters)
            ->selectRaw('e.id, SUM(d.impressions) as paid_impressions, SUM(d.clicks) as paid_clicks')->groupBy('e.id')->get()->keyBy('id');

        $eventRows = $events->with(['venue:id,name', 'organizer:id,name', 'category:id,name', 'city:id,name,timezone'])
            ->withCount('occurrences')->orderBy('title')->get()->map(function (Event $event) use ($metrics, $contentByEvent, $shortByEvent, $activity, $paid): array {
                $row = ['id' => $event->id, 'event' => $event->title, 'venue_id' => $event->venue_id,
                    'venue' => $event->venue->name ?? __('analytics-dashboard.no_venue'), 'organizer_id' => $event->getAttribute('organizer_id'),
                    'organizer' => $event->organizer->name ?? ($event->organizer_name ?: __('analytics-dashboard.no_organizer')),
                    'city' => $event->city->name, 'category' => $event->category?->name, 'status' => $event->status->label(),
                    'occurrences' => $event->occurrences_count];
                foreach ($metrics as $metric) {
                    $row[$metric] = (int) ($contentByEvent->get($event->id)->{$metric} ?? 0);
                }
                foreach (['short_shares', 'short_clicks'] as $metric) {
                    $row[$metric] = (int) ($shortByEvent->get($event->id)->{$metric} ?? 0);
                }
                foreach ($activity as $metric => $values) {
                    $row[$metric] = (int) ($values->get($event->id)->total ?? 0);
                }
                foreach (['paid_impressions', 'paid_clicks'] as $metric) {
                    $row[$metric] = (int) ($paid->get($event->id)->{$metric} ?? 0);
                }
                $row['interactions'] = array_sum(array_map(fn ($metric) => $row[$metric], array_diff($metrics, ['views', 'shares'])));

                return $row;
            })->sortByDesc('views')->values();
        $totals = [];
        foreach ([...$metrics, 'short_shares', 'short_clicks', 'interactions', ...array_keys($activity), 'paid_impressions', 'paid_clicks'] as $metric) {
            $totals[$metric] = (int) $eventRows->sum($metric);
        }
        $channels = collect(EventShares::CHANNELS)->map(function (string $channel) use ($short): array {
            $data = (clone $short)->where('l.channel', $channel)->selectRaw('SUM(d.shares) as shares, SUM(d.clicks) as clicks')->first();

            return ['channel' => __('event-shares.channels.'.$channel), 'shares' => (int) ($data->shares ?? 0), 'clicks' => (int) ($data->clicks ?? 0)];
        });
        $dailyContent = (clone $content)->selectRaw('d.date, '.$sum)->groupBy('d.date')->get()->keyBy('date');
        $dailyShort = (clone $short)->selectRaw('d.date, SUM(d.shares) as short_shares, SUM(d.clicks) as short_clicks')->groupBy('d.date')->get()->keyBy('date');
        $dates = $dailyContent->keys()->merge($dailyShort->keys())->unique()->sort()->values();
        $daily = $dates->map(function (string $date) use ($dailyContent, $dailyShort, $metrics): array {
            $row = ['date' => $date];
            foreach ($metrics as $metric) {
                $row[$metric] = (int) ($dailyContent->get($date)->{$metric} ?? 0);
            }
            foreach (['short_shares', 'short_clicks'] as $metric) {
                $row[$metric] = (int) ($dailyShort->get($date)->{$metric} ?? 0);
            }

            return $row;
        });
        $linkTotals = (clone $short)->selectRaw('l.id, SUM(d.shares) as short_shares, SUM(d.clicks) as short_clicks')->groupBy('l.id');
        $links = DB::table('event_share_links as l')->join('events as e', 'e.id', '=', 'l.event_id')
            ->leftJoin('event_occurrences as o', 'o.id', '=', 'l.occurrence_id')
            ->leftJoinSub($linkTotals, 'totals', fn ($join) => $join->on('totals.id', '=', 'l.id'))
            ->whereIn('e.id', clone $ids)->when(filled($filters['channel'] ?? null), fn (Builder $q) => $q->where('l.channel', $filters['channel']))
            ->orderByDesc('totals.short_clicks')->orderBy('l.id')->get(['l.code', 'e.title', 'o.url_number', 'l.channel', 'totals.short_shares', 'totals.short_clicks'])
            ->map(fn (stdClass $row): array => ['event' => $row->title, 'occurrence' => $row->url_number,
                'channel' => __('event-shares.channels.'.$row->channel), 'url' => route('event-shares.open', ['code' => $row->code]),
                'short_shares' => (int) $row->short_shares, 'short_clicks' => (int) $row->short_clicks]);
        $venues = $this->profiles('venue', $eventRows, $filters);
        $organizers = $this->profiles('organizer', $eventRows, $filters);
        $occurrences = $this->window(DB::table('occurrence_views_daily as d')->join('event_occurrences as o', 'o.id', '=', 'd.occurrence_id')
            ->join('events as e', 'e.id', '=', 'o.event_id')->whereIn('e.id', clone $ids), 'd.date', $filters)
            ->selectRaw('e.title as event, o.url_number as occurrence, o.starts_at, '.$sum)
            ->groupBy('e.id', 'e.title', 'o.id', 'o.url_number', 'o.starts_at')->orderByDesc('views')->get()->map(function ($row) use ($metrics): array {
                $values = (array) $row;
                foreach ($metrics as $metric) {
                    $values[$metric] = (int) $values[$metric];
                }

                return $values;
            });

        return ['totals' => $totals, 'events' => $eventRows, 'venues' => $venues, 'organizers' => $organizers,
            'channels' => $channels, 'daily' => $daily, 'series' => $this->series($daily, $filters), 'links' => $links,
            'occurrences' => $occurrences, 'filters' => $filters, 'filter_labels' => $filterLabels];
    }

    /** @param EloquentBuilder<Event> $ids
     * @param  array<string, mixed>  $filters
     * @return array<string, Collection<int, stdClass>>
     */
    private function activity(EloquentBuilder $ids, array $filters): array
    {
        $saved = DB::table('saved_events as a')->join('event_occurrences as o', 'o.id', '=', 'a.occurrence_id')->join('events as e', 'e.id', '=', 'o.event_id');
        $comments = DB::table('event_comments as a')->join('events as e', 'e.id', '=', 'a.event_id');
        $reactions = DB::table('event_comment_reactions as a')->join('event_comments as c', 'c.id', '=', 'a.event_comment_id')->join('events as e', 'e.id', '=', 'c.event_id');
        $bookings = DB::table('bookings as a')->join('event_occurrences as o', 'o.id', '=', 'a.occurrence_id')->join('events as e', 'e.id', '=', 'o.event_id');
        $tickets = DB::table('admission_tickets as a')->join('bookings as b', 'b.id', '=', 'a.booking_id')->join('event_occurrences as o', 'o.id', '=', 'b.occurrence_id')->join('events as e', 'e.id', '=', 'o.event_id');
        $queries = ['saves' => $saved, 'comments' => clone $comments,
            'hidden_comments' => (clone $comments)->where('a.status', EventCommentStatus::Hidden->value), 'reactions' => $reactions,
            'bookings_confirmed' => (clone $bookings)->where('a.status', BookingStatus::Confirmed->value),
            'bookings_waitlisted' => (clone $bookings)->where('a.status', BookingStatus::Waitlisted->value),
            'bookings_cancelled' => (clone $bookings)->where('a.status', BookingStatus::Cancelled->value),
            'tickets' => clone $tickets, 'checkins' => (clone $tickets)->whereNotNull('a.checked_in_at')];
        $result = [];
        foreach ($queries as $name => $query) {
            $result[$name] = $this->window($query->whereIn('e.id', clone $ids), $name === 'checkins' ? 'a.checked_in_at' : 'a.created_at', $filters, true)
                ->selectRaw('e.id, COUNT(*) as total')->groupBy('e.id')->get()->keyBy('id');
        }

        return $result;
    }

    /** @param Collection<int, covariant array<string, mixed>> $events
     * @param  array<string, mixed>  $filters
     * @return Collection<int, covariant array<string, mixed>>
     */
    private function profiles(string $type, Collection $events, array $filters): Collection
    {
        $profiles = $type === 'venue' ? $this->venues() : $this->organizers();
        if (filled($filters[$type] ?? null)) {
            $profiles->whereKey($filters[$type]);
        }
        if (filled($filters['event'] ?? null) || filled($filters[$type === 'venue' ? 'organizer' : 'venue'] ?? null)) {
            $profiles->whereIn('id', $events->pluck($type.'_id')->filter()->unique());
        }
        $profileIds = (clone $profiles)->select('id');
        $table = $type === 'venue' ? 'venues' : 'organizers';
        $metrics = array_column(ContentMetric::cases(), 'value');
        $query = DB::table('profile_views_daily as d')->join($table.' as e', 'e.id', '=', 'd.profile_id')
            ->where('d.profile_type', $type)->whereIn('e.id', clone $profileIds);
        $daily = $this->window($query, 'd.date', $filters)->selectRaw('e.id, '.implode(', ', array_map(fn ($m) => 'SUM(d.'.$m.') as '.$m, $metrics)))
            ->groupBy('e.id')->get()->keyBy('id');
        $followers = DB::table('follows as a')->join($table.' as e', 'e.id', '=', 'a.followable_id')->where('a.followable_type', $type)->whereIn('e.id', clone $profileIds);
        $currentFollowers = (clone $followers)->selectRaw('e.id, COUNT(*) as total')->groupBy('e.id')->get()->keyBy('id');
        $newFollowers = $this->window($followers, 'a.created_at', $filters, true)->selectRaw('e.id, COUNT(*) as total')->groupBy('e.id')->get()->keyBy('id');
        $reviews = $type === 'venue' ? $this->window(DB::table('venue_reviews as a')->join('venues as e', 'e.id', '=', 'a.venue_id')
            ->whereIn('e.id', clone $profileIds), 'a.created_at', $filters, true)
            ->selectRaw('e.id, COUNT(*) as reviews, SUM(a.status = ?) as approved_reviews, AVG(CASE WHEN a.status = ? THEN a.rating END) as rating', [VenueReviewStatus::Approved->value, VenueReviewStatus::Approved->value])
            ->groupBy('e.id')->get()->keyBy('id') : collect();
        // A venue manager must not receive an organizer's profile totals across other venues.
        $ownProfile = Filament::getCurrentPanel()?->getId() === 'admin' || ($type === 'venue' && Filament::getTenant() instanceof Venue) || ($type === 'organizer' && Filament::getTenant() instanceof Organizer);

        return $profiles->with('city')->orderBy('name')->get()->map(function ($profile) use ($type, $events, $metrics, $daily, $currentFollowers, $newFollowers, $reviews, $ownProfile): array {
            $matching = $events->where($type.'_id', $profile->id);
            $row = ['id' => $profile->id, 'name' => $profile->name, 'city' => $profile->city->name, 'events' => $matching->count(),
                'event_views' => (int) $matching->sum('views'), 'short_shares' => (int) $matching->sum('short_shares'),
                'short_clicks' => (int) $matching->sum('short_clicks'), 'event_interactions' => (int) $matching->sum('interactions'),
                'saves' => (int) $matching->sum('saves'), 'comments' => (int) $matching->sum('comments'),
                'bookings_confirmed' => (int) $matching->sum('bookings_confirmed'),
                'paid_impressions' => (int) $matching->sum('paid_impressions'), 'paid_clicks' => (int) $matching->sum('paid_clicks')];
            foreach ($metrics as $metric) {
                $row['profile_'.$metric] = $ownProfile ? (int) ($daily->get($profile->id)->{$metric} ?? 0) : null;
            }
            $row['followers'] = $ownProfile ? (int) ($currentFollowers->get($profile->id)->total ?? 0) : null;
            $row['new_followers'] = $ownProfile ? (int) ($newFollowers->get($profile->id)->total ?? 0) : null;
            if ($type === 'venue') {
                $review = $reviews->get($profile->id);
                $row['reviews'] = $ownProfile ? (int) ($review->reviews ?? 0) : null;
                $row['approved_reviews'] = $ownProfile ? (int) ($review->approved_reviews ?? 0) : null;
                $row['rating'] = $ownProfile && $review?->rating !== null ? round((float) $review->rating, 2) : null;
            }

            return $row;
        });
    }

    /** @param array<string, mixed> $filters */
    private function window(Builder $query, string $column, array $filters, bool $instant = false): Builder
    {
        if (! $instant) {
            return $query->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->where($column, '>=', $filters['from']))
                ->where($column, '<=', $filters['until']);
        }

        return $query->where(function (Builder $query) use ($column, $filters): void {
            foreach (City::query()->get(['id', 'timezone']) as $city) {
                $until = CarbonImmutable::parse($filters['until'], $city->timezone)->endOfDay()->utc();
                $from = filled($filters['from'] ?? null) ? CarbonImmutable::parse($filters['from'], $city->timezone)->startOfDay()->utc() : null;
                $query->orWhere(fn (Builder $q) => $q->where('e.city_id', $city->id)->where($column, '<=', $until)
                    ->when($from !== null, fn (Builder $q) => $q->where($column, '>=', $from)));
            }
        });
    }

    /** @param Collection<int, covariant array<string, mixed>> $daily
     * @param  array<string, mixed>  $filters
     * @return array{granularity: string, points: list<array<string, mixed>>}
     */
    private function series(Collection $daily, array $filters): array
    {
        $until = CarbonImmutable::parse($filters['until']);
        $from = CarbonImmutable::parse($filters['from'] ?? $daily->min('date') ?? $until->subDays(29)->toDateString());
        $monthly = $from->diffInDays($until) > 120;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $grouped = $daily->groupBy(fn ($row) => CarbonImmutable::parse($row['date'])->format($format));
        $points = [];
        for ($day = $monthly ? $from->startOfMonth() : $from; $day->lte($until); $day = $monthly ? $day->addMonth() : $day->addDay()) {
            $bucket = $grouped->get($day->format($format), collect());
            $points[] = ['date' => $day->format($format), 'views' => (int) $bucket->sum('views'),
                'short_shares' => (int) $bucket->sum('short_shares'), 'short_clicks' => (int) $bucket->sum('short_clicks')];
        }

        return ['granularity' => $monthly ? 'month' : 'day', 'points' => $points];
    }
}
