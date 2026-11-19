<x-filament-panels::page>
    @php
        $report = $this->report();
        $events = $this->eventPage();
        $metrics = \App\Enums\ContentMetric::cases();
        $number = fn ($value): string => number_format((int) $value, 0, ',', '.');
    @endphp
    <div class="flex flex-col gap-6" data-content-analytics-page>
        <div class="flex flex-col gap-2">
            <span class="text-sm font-semibold text-gray-950 dark:text-white" id="content-analytics-period">{{ __('panel.analytics.period') }}</span>
            <div class="flex flex-wrap gap-2" role="group" aria-labelledby="content-analytics-period">
                @foreach ($this->periodOptions() as $value => $label)
                    <x-filament::button :color="$this->selectedPeriod()->value === $value ? 'primary' : 'gray'"
                        :outlined="$this->selectedPeriod()->value !== $value" wire:click="setPeriod('{{ $value }}')"
                        wire:loading.attr="disabled" :aria-pressed="$this->selectedPeriod()->value === $value ? 'true' : 'false'">{{ $label }}</x-filament::button>
                @endforeach
            </div>
        </div>

        @php
            $clicksTotal = collect($metrics)->reject(fn ($metric) => in_array($metric, [\App\Enums\ContentMetric::Views, \App\Enums\ContentMetric::Shares]))->sum(fn ($metric) => (int) $report['totals'][$metric->value]);
            $axisDay = fn (string $date): string => \App\Filament\Support\PanelDate::axis(\Carbon\CarbonImmutable::createFromFormat('!d/m', $date, \App\Filament\Support\PanelDate::defaultTimezone()));
        @endphp
        <x-filament::section :heading="__('pannelli-statistiche.summary.heading')" icon="heroicon-o-presentation-chart-line">
            <x-pannelli::stat-cards data-analytics-summary :label="__('pannelli-statistiche.summary.label')" :items="[
                ['key' => 'event_views', 'label' => __('analytics.event_views'), 'value' => (int) $report['totals']['views'], 'hint' => __('pannelli-statistiche.hints.event_views')],
                ['key' => 'profile_views', 'label' => __('analytics.profile_views'), 'value' => (int) $report['profile']['views'], 'hint' => __('pannelli-statistiche.hints.profile_views')],
                ['key' => 'clicks', 'label' => __('pannelli-statistiche.labels.clicks'), 'value' => $clicksTotal, 'hint' => __('pannelli-statistiche.hints.clicks')],
                ['key' => 'saves', 'label' => __('analytics.saves'), 'value' => (int) $report['totals']['saves'], 'hint' => __('pannelli-statistiche.hints.saves')],
                ['key' => 'followers', 'label' => __('pannelli-statistiche.labels.followers'), 'value' => (int) $report['profile']['followers'], 'hint' => __('pannelli-statistiche.hints.followers')],
            ]" />
        </x-filament::section>

        @include('filament.partials.analytics-consent')

        @php
            $maximum = max(1, ...array_column($report['series'], 'views'), ...array_column($report['series'], 'profile_views'));
            $ceiling = max(10, (int) ceil($maximum / 10) * 10);
            $points = function ($key) use ($report, $ceiling) {
                return implode(' ', array_map(fn ($day, $i) => round(1000 * $i / max(1, count($report['series']) - 1), 2).','.round(200 - 190 * $day[$key] / $ceiling, 2), $report['series'], array_keys($report['series'])));
            };
            $actions = collect($metrics)->reject(fn ($metric) => in_array($metric, [\App\Enums\ContentMetric::Views, \App\Enums\ContentMetric::Shares]))->sortByDesc(fn ($metric) => $report['totals'][$metric->value]);
            $maxClicks = max(1, ...$actions->map(fn ($metric) => $report['totals'][$metric->value])->all());
            $nothing = (int) $report['totals']['views'] === 0 && (int) $report['profile']['views'] === 0 && (int) $report['totals']['saves'] === 0;
        @endphp
        <x-filament::section :heading="__('analytics.daily')" :description="__('analytics.visits_hint')" icon="heroicon-o-presentation-chart-line">
            <div class="analytics-legend">
                <span><i class="analytics-key"></i>{{ __('analytics.event_views') }} <strong>{{ $number($report['totals']['views']) }}</strong></span>
                <span><i class="analytics-key analytics-key-profile"></i>{{ __('analytics.profile_views') }} <strong>{{ $number($report['profile']['views']) }}</strong></span>
            </div>
            @if ($nothing)
                <x-filament::empty-state :heading="__('panel.analytics.empty_title')" :description="__('panel.analytics.empty_body')" icon="heroicon-o-chart-bar" :contained="false" data-analytics-empty />
            @else
                <div class="analytics-plot" data-analytics-chart>
                    <div class="analytics-axis" aria-hidden="true"><span>{{ $number($ceiling) }}</span><span>{{ $number($ceiling / 2) }}</span><span>0</span></div>
                    <svg viewBox="0 0 1000 210" preserveAspectRatio="none" role="img" aria-label="{{ __('analytics.visits_hint') }}">
                        @foreach ([10, 105, 200] as $y)<line x1="0" y1="{{ $y }}" x2="1000" y2="{{ $y }}" class="analytics-gridline" />@endforeach
                        <polyline points="{{ $points('views') }}" class="analytics-line" />
                        <polyline points="{{ $points('profile_views') }}" class="analytics-line analytics-line-profile" />
                        @foreach ($report['series'] as $index => $day)
                            <circle cx="{{ 1000 * $index / max(1, count($report['series']) - 1) }}" cy="{{ 200 - 190 * $day['views'] / $ceiling }}" r="4" class="analytics-point"><title>{{ $axisDay($day['date']) }}: {{ __('analytics.event_views') }} {{ $day['views'] }}, {{ __('analytics.profile_views') }} {{ $day['profile_views'] }}</title></circle>
                        @endforeach
                    </svg>
                    <div class="analytics-dates" aria-hidden="true"><span>{{ $axisDay($report['series'][0]['date']) }}</span><span>{{ $axisDay($report['series'][(int) floor(count($report['series']) / 2)]['date']) }}</span><span>{{ $axisDay($report['series'][count($report['series']) - 1]['date']) }}</span></div>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('analytics.interactions')" :description="__('analytics.interactions_hint')" icon="heroicon-o-cursor-arrow-rays">
            <div class="analytics-bars" data-analytics-bars>
                @foreach ($actions as $metric)
                    <div class="analytics-bar-row">
                        <div><span>{{ $metric->label() }}</span><strong>{{ $number($report['totals'][$metric->value]) }}</strong></div>
                        <div class="analytics-bar-track" aria-hidden="true"><span style="width: {{ 100 * $report['totals'][$metric->value] / $maxClicks }}%"></span></div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('analytics.profile')" :description="__('panel.analytics.profile_hint')" icon="heroicon-o-building-storefront">
            <dl class="ad-stat-row" data-analytics-profile>
                @foreach ($metrics as $metric)
                    @continue($metric !== \App\Enums\ContentMetric::Views && $report['profile'][$metric->value] === 0)
                    <div><dt>{{ $metric->label() }}</dt><dd>{{ $number($report['profile'][$metric->value]) }}</dd></div>
                @endforeach
                @foreach (['followers', 'new_followers'] as $key)
                    <div><dt>{{ __('pannelli-statistiche.labels.'.$key) }}</dt><dd>{{ $number($report['profile'][$key]) }}</dd></div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('analytics.details')" :description="__('panel.analytics.details_hint')" icon="heroicon-o-list-bullet" collapsible collapsed>
            <dl class="ad-stat-row">
                @foreach ($metrics as $metric)
                    <div><dt>{{ $metric->label() }}</dt><dd>{{ $number($report['totals'][$metric->value]) }}</dd></div>
                @endforeach
                <div><dt>{{ __('analytics.saves') }}</dt><dd>{{ $number($report['totals']['saves']) }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section :heading="__('manage.statistics.per_event')" :description="__('panel.analytics.per_event_hint')" icon="heroicon-o-calendar-days" collapsible :collapsed="$events->total() === 0">
            @if ($events->total() > 0)
                <div class="ad-table-scroll" tabindex="0" role="region" aria-label="{{ __('manage.statistics.per_event') }}">
                    <table class="ad-table">
                        <thead><tr><th scope="col">{{ __('analytics.event') }}</th>
                            @foreach ($metrics as $metric)<th scope="col" class="ad-number">{{ $metric->label() }}</th>@endforeach
                            <th scope="col" class="ad-number">{{ __('analytics.saves') }}</th>
                        </tr></thead>
                        <tbody>@foreach ($events as $event)
                            <tr><th scope="row" class="min-w-48 text-start font-semibold">{{ $event->title }}</th>
                                @foreach ($metrics as $metric)<td class="ad-number">{{ (int) $event->getAttribute($metric->value.'_total') }}</td>@endforeach
                                <td class="ad-number">{{ (int) $event->saves_total }}</td>
                            </tr>
                        @endforeach</tbody>
                    </table>
                </div>
                {{ $events->links() }}
            @else
                <x-filament::empty-state :heading="__('analytics.empty')" icon="heroicon-o-calendar" :contained="false" />
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('panel.analytics.daily_table')" icon="heroicon-o-table-cells" collapsible collapsed>
            <div class="ad-table-scroll" tabindex="0" role="region" aria-label="{{ __('panel.analytics.daily_table') }}">
                <table class="ad-table">
                    <thead><tr>@foreach (['day', 'profile_views', 'event_views', 'clicks'] as $key)<th scope="col">{{ $key === 'clicks' ? __('pannelli-statistiche.labels.clicks') : __('analytics.'.$key) }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($report['series'] as $day)<tr><th scope="row" class="text-start font-normal">{{ $axisDay($day['date']) }}</th><td class="ad-number">{{ $day['profile_views'] }}</td><td class="ad-number">{{ $day['views'] }}</td><td class="ad-number">{{ $day['clicks'] }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('analytics.paid')" :description="__('panel.analytics.paid_hint')" icon="heroicon-o-megaphone" collapsible :collapsed="(int) $report['impressions'] === 0">
            <dl class="ad-stat-row">
                <div><dt>{{ __('pannelli-statistiche.labels.impressions') }}</dt><dd>{{ $number($report['impressions']) }}</dd></div>
                <div><dt>{{ __('pannelli-statistiche.labels.paid_clicks') }}</dt><dd>{{ $number($report['sponsored_clicks']) }}</dd></div>
                <div><dt>{{ __('pannelli-statistiche.labels.ctr') }}</dt><dd>{{ $report['impressions'] > 0 ? number_format(100 * $report['sponsored_clicks'] / $report['impressions'], 2, ',', '.').'%' : '—' }}</dd></div>
            </dl>
            @if ($report['breakdown']->isNotEmpty())
                @php
                    $channels = $report['breakdown']->groupBy('channel')->map(fn ($rows) => (int) $rows->sum('clicks'))->sortDesc();
                    $channelTotal = max(1, $channels->sum());
                @endphp
                <div class="analytics-bars mt-6" aria-label="{{ __('analytics.channel') }}">
                    @foreach ($channels as $channel => $clicks)
                        <div class="analytics-bar-row">
                            <div><span>{{ \Illuminate\Support\Facades\Lang::has('analytics.channels.'.$channel) ? __('analytics.channels.'.$channel) : __('analytics.unknown') }}</span><strong>{{ $number($clicks) }} · {{ number_format(100 * $clicks / $channelTotal, 1, ',', '.') }}%</strong></div>
                            <div class="analytics-bar-track" aria-hidden="true"><span style="width: {{ 100 * $clicks / $channelTotal }}%"></span></div>
                        </div>
                    @endforeach
                </div>
                <div class="ad-table-scroll mt-4" tabindex="0" role="region" aria-label="{{ __('analytics.paid') }}">
                    <table class="ad-table"><thead><tr>@foreach (['channel', 'placement', 'page', 'paid_clicks'] as $key)<th scope="col">{{ __('analytics.'.$key) }}</th>@endforeach</tr></thead>
                        <tbody>@foreach ($report['breakdown'] as $row)<tr>@foreach (['channels' => $row->channel, 'placements' => $row->placement, 'pages' => $row->page] as $group => $value)<td>{{ \Illuminate\Support\Facades\Lang::has('analytics.'.$group.'.'.$value) ? __('analytics.'.$group.'.'.$value) : __('analytics.unknown') }}</td>@endforeach<td class="ad-number">{{ $row->clicks }}</td></tr>@endforeach</tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @include('filament.partials.event-share-report', ['rows' => $this->shareRows()])

        <x-filament::section :heading="__('analytics.about')" icon="heroicon-o-book-open" collapsible collapsed>
            <div class="ad-definitions">
                <p>{{ __('panel.analytics.coverage') }}</p>
                <p>{{ __('analytics.definitions') }}</p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
