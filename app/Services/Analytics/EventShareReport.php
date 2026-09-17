<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\StatsPeriod;
use App\Models\City;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EventShareReport
{
    /** @return LengthAwarePaginator<int, stdClass> */
    public function rows(StatsPeriod $period): LengthAwarePaginator
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            abort_unless(auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true, 403);
            $events = Event::query();
        } else {
            $events = app(ManagementAnalytics::class)->eventQuery();
        }
        $dates = DB::table('event_share_daily as d')->join('event_share_links as l', 'l.id', '=', 'd.share_link_id')
            ->join('events as e', 'e.id', '=', 'l.event_id')
            ->whereIn('e.id', (clone $events)->select('events.id'))
            ->where(function (Builder $query) use ($period): void {
                foreach (City::query()->get(['id', 'timezone']) as $city) {
                    $until = CarbonImmutable::now($city->timezone);
                    $query->orWhere(fn (Builder $q) => $q->where('e.city_id', $city->id)
                        ->whereBetween('d.date', [$until->subDays($period->days() - 1)->toDateString(), $until->toDateString()]));
                }
            })->selectRaw('d.share_link_id, SUM(d.shares) as shares, SUM(d.clicks) as clicks')->groupBy('d.share_link_id');

        return DB::table('event_share_links as links')
            ->join('events', 'events.id', '=', 'links.event_id')
            ->join('cities', 'cities.id', '=', 'events.city_id')
            ->leftJoin('event_occurrences as occurrence', 'occurrence.id', '=', 'links.occurrence_id')
            ->leftJoinSub($dates, 'stats', fn (JoinClause $join) => $join->on('stats.share_link_id', '=', 'links.id'))
            ->whereIn('events.id', $events->select('events.id'))
            ->select(['links.code', 'links.channel', 'events.title', 'occurrence.url_number', 'occurrence.starts_at', 'cities.timezone'])
            ->selectRaw('COALESCE(stats.shares, 0) as shares, COALESCE(stats.clicks, 0) as clicks')
            ->orderByDesc('clicks')->orderByDesc('shares')->orderBy('links.id')->paginate(20, pageName: 'sharePage');
    }
}
