<div class="flex flex-col gap-6" data-occurrence-analytics>
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('analytics.occurrence_note') }}</p>
    <dl class="ad-stat-row">
        @foreach (\App\Enums\ContentMetric::cases() as $metric)
            <div><dt>{{ $metric->label() }}</dt><dd>{{ number_format($report['totals'][$metric->value], 0, ',', '.') }}</dd></div>
        @endforeach
        <div><dt>{{ __('analytics.occurrence_saves') }}</dt><dd>{{ number_format($report['totals']['saves'], 0, ',', '.') }}</dd></div>
    </dl>
    @php
        $max = max(1, ...array_column($report['series'], 'views'), ...array_column($report['series'], 'clicks'));
        $points = fn ($key) => implode(' ', array_map(fn ($day, $index) => ($index * 1000 / 29).','.(200 - 190 * $day[$key] / $max), $report['series'], array_keys($report['series'])));
    @endphp
    <h3 class="font-semibold text-gray-950 dark:text-white">{{ __('analytics.daily') }}</h3>
    <div class="analytics-legend"><span><i class="analytics-key"></i>{{ __('analytics.metrics.views') }}</span><span><i class="analytics-key analytics-key-profile"></i>{{ __('analytics.occurrence_clicks') }}</span></div>
    <div class="analytics-plot">
        <div class="analytics-axis" aria-hidden="true"><span>{{ $max }}</span><span>0</span></div>
        <svg viewBox="0 0 1000 210" preserveAspectRatio="none" role="img" aria-label="{{ __('analytics.daily') }}">
            @foreach ([10, 105, 200] as $y)<line x1="0" y1="{{ $y }}" x2="1000" y2="{{ $y }}" class="analytics-gridline" />@endforeach
            <polyline points="{{ $points('views') }}" class="analytics-line" />
            <polyline points="{{ $points('clicks') }}" class="analytics-line analytics-line-profile" />
        </svg>
        <div class="analytics-dates"><span>{{ $report['series'][0]['date'] }}</span><span>{{ $report['series'][29]['date'] }}</span></div>
    </div>
    <details class="ad-definitions">
        <summary>{{ __('analytics.daily') }} · {{ __('analytics.details') }}</summary>
        <div class="ad-table-scroll mt-3">
            <table class="ad-table">
                <thead><tr><th scope="col">{{ __('analytics.day') }}</th><th scope="col" class="ad-number">{{ __('analytics.metrics.views') }}</th><th scope="col" class="ad-number">{{ __('analytics.occurrence_clicks') }}</th></tr></thead>
                <tbody>
                    @foreach ($report['series'] as $day)<tr><th scope="row" class="text-start font-normal">{{ $day['date'] }}</th><td class="ad-number">{{ $day['views'] }}</td><td class="ad-number">{{ $day['clicks'] }}</td></tr>@endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
