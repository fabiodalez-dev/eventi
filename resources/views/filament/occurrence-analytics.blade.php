<div class="flex flex-col gap-6" data-occurrence-analytics>
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('analytics.occurrence_note') }}</p>
    <x-pannelli::stat-cards :label="__('pannelli-statistiche.summary.label')" :items="[
        ['key' => 'views', 'label' => __('analytics.metrics.views'), 'value' => (int) $report['totals']['views'], 'hint' => __('pannelli-statistiche.hints.occurrence_views')],
        ['key' => 'clicks', 'label' => __('analytics.occurrence_clicks'), 'value' => (int) collect(\App\Enums\ContentMetric::cases())->reject(fn ($m) => in_array($m->value, ['views', 'shares']))->sum(fn ($m) => $report['totals'][$m->value]), 'hint' => __('pannelli-statistiche.hints.occurrence_clicks')],
        ['key' => 'saves', 'label' => __('analytics.occurrence_saves'), 'value' => (int) $report['totals']['saves'], 'hint' => __('pannelli-statistiche.hints.occurrence_saves')],
    ]" />
    @php
        $max = max(1, ...array_column($report['series'], 'views'), ...array_column($report['series'], 'clicks'));
        $last = max(1, count($report['series']) - 1);
        $points = fn ($key) => implode(' ', array_map(fn ($day, $index) => ($index * 1000 / $last).','.(200 - 190 * $day[$key] / $max), $report['series'], array_keys($report['series'])));
        $day = fn (string $date): string => \App\Filament\Support\PanelDate::axis(\Carbon\CarbonImmutable::createFromFormat('!d/m', $date, \App\Filament\Support\PanelDate::defaultTimezone()));
    @endphp
    <h3 class="font-semibold text-gray-950 dark:text-white">{{ __('pannelli-statistiche.occurrence.chart') }}</h3>
    <div class="analytics-legend"><span><i class="analytics-key"></i>{{ __('analytics.metrics.views') }}</span><span><i class="analytics-key analytics-key-profile"></i>{{ __('analytics.occurrence_clicks') }}</span></div>
    <div class="analytics-plot">
        <div class="analytics-axis" aria-hidden="true"><span>{{ $max }}</span><span>0</span></div>
        <svg viewBox="0 0 1000 210" preserveAspectRatio="none" role="img" aria-label="{{ __('analytics.daily') }}">
            @foreach ([10, 105, 200] as $y)<line x1="0" y1="{{ $y }}" x2="1000" y2="{{ $y }}" class="analytics-gridline" />@endforeach
            <polyline points="{{ $points('views') }}" class="analytics-line" />
            <polyline points="{{ $points('clicks') }}" class="analytics-line analytics-line-profile" />
        </svg>
        <div class="analytics-dates"><span>{{ $day($report['series'][0]['date']) }}</span><span>{{ $day($report['series'][$last]['date']) }}</span></div>
    </div>
    <details class="ad-definitions">
        <summary>{{ __('pannelli-statistiche.occurrence.table') }}</summary>
        <div class="ad-table-scroll mt-3">
            <table class="ad-table">
                <thead><tr><th scope="col">{{ __('analytics.day') }}</th><th scope="col" class="ad-number">{{ __('analytics.metrics.views') }}</th><th scope="col" class="ad-number">{{ __('analytics.occurrence_clicks') }}</th></tr></thead>
                <tbody>
                    @foreach ($report['series'] as $row)<tr><th scope="row" class="text-start font-normal">{{ $day($row['date']) }}</th><td class="ad-number">{{ $row['views'] }}</td><td class="ad-number">{{ $row['clicks'] }}</td></tr>@endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
