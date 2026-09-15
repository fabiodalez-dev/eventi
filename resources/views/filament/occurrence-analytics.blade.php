<div class="space-y-6" data-occurrence-analytics>
    <p class="text-sm text-gray-600">{{ __('analytics.occurrence_note') }}</p>
    <dl class="grid grid-cols-2 gap-5 sm:grid-cols-3">
        @foreach (\App\Enums\ContentMetric::cases() as $metric)
            <div><dt class="text-sm text-gray-600">{{ $metric->label() }}</dt><dd class="text-xl font-semibold tabular-nums">{{ number_format($report['totals'][$metric->value], 0, ',', '.') }}</dd></div>
        @endforeach
        <div><dt class="text-sm text-gray-600">{{ __('analytics.occurrence_saves') }}</dt><dd class="text-xl font-semibold">{{ $report['totals']['saves'] }}</dd></div>
    </dl>
    @php
        $max = max(1, ...array_column($report['series'], 'views'), ...array_column($report['series'], 'clicks'));
        $points = fn ($key) => implode(' ', array_map(fn ($day, $index) => ($index * 1000 / 29).','.(200 - 190 * $day[$key] / $max), $report['series'], array_keys($report['series'])));
    @endphp
    <h3 class="font-semibold">{{ __('analytics.daily') }}</h3>
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
    <details><summary class="cursor-pointer py-3">{{ __('analytics.daily') }} · {{ __('analytics.details') }}</summary>
        <table class="w-full text-sm"><thead><tr><th class="p-2 text-left">{{ __('analytics.day') }}</th><th>{{ __('analytics.metrics.views') }}</th><th>{{ __('analytics.occurrence_clicks') }}</th></tr></thead><tbody>
        @foreach ($report['series'] as $day)<tr class="border-t border-gray-200"><th class="p-2 text-left font-normal">{{ $day['date'] }}</th><td class="text-center">{{ $day['views'] }}</td><td class="text-center">{{ $day['clicks'] }}</td></tr>@endforeach
        </tbody></table>
    </details>
</div>
