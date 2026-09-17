<x-filament-panels::page>
    @php
        $report = $this->report();
        $events = $this->eventPage();
        $metrics = \App\Enums\ContentMetric::cases();
    @endphp
    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('analytics.period') }}">
        @foreach ($this->periodOptions() as $value => $label)
            <x-filament::button :color="$this->selectedPeriod()->value === $value ? 'primary' : 'gray'"
                :outlined="$this->selectedPeriod()->value !== $value" wire:click="setPeriod('{{ $value }}')"
                wire:loading.attr="disabled" :aria-pressed="$this->selectedPeriod()->value === $value ? 'true' : 'false'">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    @php
        $maximum = max(1, ...array_column($report['series'], 'views'), ...array_column($report['series'], 'profile_views'));
        $ceiling = max(10, (int) ceil($maximum / 10) * 10);
        $points = function ($key) use ($report, $ceiling) {
            return implode(' ', array_map(fn ($day, $i) => round(1000 * $i / max(1, count($report['series']) - 1), 2).','.round(200 - 190 * $day[$key] / $ceiling, 2), $report['series'], array_keys($report['series'])));
        };
        $actions = collect($metrics)->reject(fn ($metric) => in_array($metric, [\App\Enums\ContentMetric::Views, \App\Enums\ContentMetric::Shares]))->sortByDesc(fn ($metric) => $report['totals'][$metric->value]);
        $maxClicks = max(1, ...$actions->map(fn ($metric) => $report['totals'][$metric->value])->all());
    @endphp
    <x-filament::section :heading="__('analytics.daily')" :description="__('analytics.visits_hint')">
        <div class="analytics-legend">
            <span><i class="analytics-key"></i>{{ __('analytics.event_views') }} <strong>{{ number_format($report['totals']['views'], 0, ',', '.') }}</strong></span>
            <span><i class="analytics-key analytics-key-profile"></i>{{ __('analytics.profile_views') }} <strong>{{ number_format($report['profile']['views'], 0, ',', '.') }}</strong></span>
        </div>
        <div class="analytics-plot" data-analytics-chart>
            <div class="analytics-axis" aria-hidden="true"><span>{{ number_format($ceiling, 0, ',', '.') }}</span><span>{{ number_format($ceiling / 2, 0, ',', '.') }}</span><span>0</span></div>
            <svg viewBox="0 0 1000 210" preserveAspectRatio="none" role="img" aria-label="{{ __('analytics.visits_hint') }}">
                @foreach ([10, 105, 200] as $y)<line x1="0" y1="{{ $y }}" x2="1000" y2="{{ $y }}" class="analytics-gridline" />@endforeach
                <polyline points="{{ $points('views') }}" class="analytics-line" />
                <polyline points="{{ $points('profile_views') }}" class="analytics-line analytics-line-profile" />
                @foreach ($report['series'] as $index => $day)
                    <circle cx="{{ 1000 * $index / max(1, count($report['series']) - 1) }}" cy="{{ 200 - 190 * $day['views'] / $ceiling }}" r="4" class="analytics-point"><title>{{ $day['date'] }}: {{ __('analytics.event_views') }} {{ $day['views'] }}, {{ __('analytics.profile_views') }} {{ $day['profile_views'] }}</title></circle>
                @endforeach
            </svg>
            <div class="analytics-dates" aria-hidden="true"><span>{{ $report['series'][0]['date'] }}</span><span>{{ $report['series'][(int) floor(count($report['series']) / 2)]['date'] }}</span><span>{{ $report['series'][count($report['series']) - 1]['date'] }}</span></div>
        </div>
    </x-filament::section>
    <x-filament::section :heading="__('analytics.interactions')" :description="__('analytics.interactions_hint')">
        <div class="analytics-bars" data-analytics-bars>
            @foreach ($actions as $metric)
                <div class="analytics-bar-row">
                    <div><span>{{ $metric->label() }}</span><strong>{{ number_format($report['totals'][$metric->value], 0, ',', '.') }}</strong></div>
                    <div class="analytics-bar-track" aria-hidden="true"><span style="width: {{ 100 * $report['totals'][$metric->value] / $maxClicks }}%"></span></div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
    <x-filament::section :heading="__('analytics.profile')">
        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-4">
            @foreach ($metrics as $metric)
                @continue($metric !== \App\Enums\ContentMetric::Views && $report['profile'][$metric->value] === 0)
                <div><dt class="text-sm text-gray-600">{{ $metric->label() }}</dt><dd class="text-xl font-semibold tabular-nums">{{ number_format($report['profile'][$metric->value], 0, ',', '.') }}</dd></div>
            @endforeach
            @foreach (['followers', 'new_followers'] as $key)
                <div><dt class="text-sm text-gray-600">{{ __('analytics.'.$key) }}</dt><dd class="text-xl font-semibold tabular-nums">{{ number_format($report['profile'][$key], 0, ',', '.') }}</dd></div>
            @endforeach
        </dl>
    </x-filament::section>
    <x-filament::section :heading="__('analytics.details')" collapsible collapsed>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-4">
            @foreach ($metrics as $metric)
                <div><dt class="text-sm text-gray-600">{{ $metric->label() }}</dt><dd class="text-xl font-semibold tabular-nums">{{ number_format($report['totals'][$metric->value], 0, ',', '.') }}</dd></div>
            @endforeach
            <div><dt class="text-sm text-gray-600">{{ __('analytics.saves') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ number_format($report['totals']['saves'], 0, ',', '.') }}</dd></div>
        </dl>
    </x-filament::section>
    @if ($events->total() > 0)
        <x-filament::section :heading="__('manage.statistics.per_event')" collapsible>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('manage.statistics.per_event') }}">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-200"><th class="p-3 text-left" scope="col">{{ __('analytics.event') }}</th>
                        @foreach ($metrics as $metric)<th scope="col" class="p-3 text-right">{{ $metric->label() }}</th>@endforeach
                        <th scope="col" class="p-3 text-right">{{ __('analytics.saves') }}</th>
                    </tr></thead>
                    <tbody>@foreach ($events as $event)
                        <tr class="border-b border-gray-200"><th scope="row" class="min-w-48 p-3 text-left font-medium">{{ $event->title }}</th>
                            @foreach ($metrics as $metric)<td class="p-3 text-right tabular-nums">{{ (int) $event->getAttribute($metric->value.'_total') }}</td>@endforeach
                            <td class="p-3 text-right tabular-nums">{{ (int) $event->saves_total }}</td>
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
            {{ $events->links() }}
        </x-filament::section>
    @else
        <p class="text-sm text-gray-600">{{ __('analytics.empty') }}</p>
    @endif
    <x-filament::section :heading="__('analytics.daily')" collapsible collapsed>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('analytics.daily') }}">
            <table class="w-full text-sm">
                <thead><tr>@foreach (['day', 'profile_views', 'event_views', 'clicks'] as $key)<th scope="col" class="p-2 text-left">{{ __('analytics.'.$key) }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($report['series'] as $day)<tr class="border-t border-gray-200"><th scope="row" class="p-2 text-left font-normal">{{ $day['date'] }}</th><td class="p-2 tabular-nums">{{ $day['profile_views'] }}</td><td class="p-2 tabular-nums">{{ $day['views'] }}</td><td class="p-2 tabular-nums">{{ $day['clicks'] }}</td></tr>@endforeach</tbody>
            </table>
        </div>
    </x-filament::section>
    <x-filament::section :heading="__('analytics.paid')">
        <dl class="flex flex-wrap gap-8">
            <div><dt>{{ __('analytics.impressions') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ $report['impressions'] }}</dd></div>
            <div><dt>{{ __('analytics.paid_clicks') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ $report['sponsored_clicks'] }}</dd></div>
            <div><dt>{{ __('analytics.ctr') }}</dt><dd class="text-xl font-semibold tabular-nums">{{ $report['impressions'] > 0 ? number_format(100 * $report['sponsored_clicks'] / $report['impressions'], 2, ',', '.').'%' : '—' }}</dd></div>
        </dl>
        @if ($report['breakdown']->isNotEmpty())
            @php
                $channels = $report['breakdown']->groupBy('channel')->map(fn ($rows) => (int) $rows->sum('clicks'))->sortDesc();
                $channelTotal = max(1, $channels->sum());
            @endphp
            <div class="analytics-bars mt-6" aria-label="{{ __('analytics.channel') }}">
                @foreach ($channels as $channel => $clicks)
                    <div class="analytics-bar-row">
                        <div><span>{{ \Illuminate\Support\Facades\Lang::has('analytics.channels.'.$channel) ? __('analytics.channels.'.$channel) : __('analytics.unknown') }}</span><strong>{{ number_format($clicks, 0, ',', '.') }} · {{ number_format(100 * $clicks / $channelTotal, 1, ',', '.') }}%</strong></div>
                        <div class="analytics-bar-track" aria-hidden="true"><span style="width: {{ 100 * $clicks / $channelTotal }}%"></span></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('analytics.paid') }}">
                <table class="w-full text-sm"><thead><tr>@foreach (['channel', 'placement', 'page', 'paid_clicks'] as $key)<th scope="col" class="p-2 text-left">{{ __('analytics.'.$key) }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($report['breakdown'] as $row)<tr class="border-t border-gray-200">@foreach (['channels' => $row->channel, 'placements' => $row->placement, 'pages' => $row->page] as $group => $value)<td class="p-2">{{ \Illuminate\Support\Facades\Lang::has('analytics.'.$group.'.'.$value) ? __('analytics.'.$group.'.'.$value) : __('analytics.unknown') }}</td>@endforeach<td class="p-2 tabular-nums">{{ $row->clicks }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
    <details class="max-w-3xl text-sm text-gray-600">
        <summary class="cursor-pointer py-3 font-medium">{{ __('analytics.about') }}</summary>
        <p class="mt-3">{{ __('analytics.coverage') }}</p>
        <p class="mt-3">{{ __('analytics.definitions') }}</p>
    </details>
    @include('filament.partials.event-share-report', ['rows' => $this->shareRows()])
</x-filament-panels::page>
