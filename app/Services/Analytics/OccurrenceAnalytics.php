<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\ContentMetric;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class OccurrenceAnalytics
{
    /** @return list<string> */
    public static function clickColumns(): array
    {
        return array_values(array_diff(array_column(ContentMetric::cases(), 'value'), ['views', 'shares']));
    }

    /** @param Builder<EventOccurrence> $query
     * @return Builder<EventOccurrence>
     */
    public static function withTotals(Builder $query): Builder
    {
        $daily = DB::table('occurrence_views_daily')->whereColumn('occurrence_id', 'event_occurrences.id');

        return $query->addSelect('event_occurrences.*')
            ->selectSub((clone $daily)->selectRaw('COALESCE(SUM(views), 0)'), 'analytics_views')
            ->selectSub((clone $daily)->selectRaw('COALESCE(SUM('.implode(' + ', self::clickColumns()).'), 0)'), 'analytics_clicks')
            ->withCount('savedEvents');
    }

    /** @return array<string, mixed> */
    public function report(EventOccurrence $occurrence): array
    {
        Gate::authorize('update', $occurrence);
        $daily = DB::table('occurrence_views_daily')->where('occurrence_id', $occurrence->id);
        $totals = [];
        foreach (ContentMetric::cases() as $metric) {
            $totals[$metric->value] = (int) (clone $daily)->sum($metric->value);
        }
        $totals['saves'] = $occurrence->savedEvents()->count();
        $today = CarbonImmutable::now($occurrence->event->city->timezone)->startOfDay();
        $rows = (clone $daily)->whereBetween('date', [$today->subDays(29)->toDateString(), $today->toDateString()])->get()->keyBy('date');
        $series = [];
        for ($day = $today->subDays(29); $day->lte($today); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $series[] = ['date' => $day->format('d/m'), 'views' => (int) ($row->views ?? 0),
                'clicks' => array_sum(array_map(fn ($key) => (int) ($row->$key ?? 0), self::clickColumns()))];
        }

        return ['totals' => $totals, 'series' => $series];
    }
}
