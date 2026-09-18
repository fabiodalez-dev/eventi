<x-filament-panels::page>
    @php
        $data = $this->dashboard;
        $rows = $this->tableRows();
        $totals = $data['totals'];
        $series = $data['series']['points'];
        $metricLabel = fn ($key) => \App\Services\Analytics\EventAnalyticsExport::label($key);
        $interactionRows = collect(\App\Enums\ContentMetric::cases())->reject(fn ($metric) => in_array($metric->value, ['views', 'shares']))->map(fn ($metric) => ['name' => $metric->label(), 'value' => $totals[$metric->value]])->sortByDesc('value')->values();
        $bookingRows = collect(['bookings_confirmed', 'bookings_waitlisted', 'bookings_cancelled', 'tickets', 'checkins'])->map(fn ($key) => ['name' => $metricLabel($key), 'value' => $totals[$key]]);
        $communityRows = collect(['saves', 'comments', 'hidden_comments', 'reactions'])->map(fn ($key) => ['name' => $metricLabel($key), 'value' => $totals[$key]]);
    @endphp
    <section class="ad-active-filters" data-analytics-active-filters aria-label="{{ __('analytics-dashboard.active_filters') }}">
        <div>
            <strong>{{ __('analytics-dashboard.active_filters') }}</strong>
            <dl>
                @foreach ($data['filter_labels'] as $key => $value)
                    @if (filled($value) && ! ($this instanceof \App\Filament\Shared\AnalyticsDetailPage && in_array($key, ['event', 'venue', 'organizer'])))
                        <div><dt>{{ __('analytics-dashboard.'.$key) }}</dt><dd>{{ in_array($key, ['from', 'until']) ? \Carbon\CarbonImmutable::parse($value)->format('d/m/Y') : $value }}</dd></div>
                    @endif
                @endforeach
            </dl>
        </div>
        <x-filament::button color="gray" icon="heroicon-o-x-mark" wire:click="resetFilters" wire:loading.attr="disabled" data-analytics-reset-filters>{{ __($this instanceof \App\Filament\Shared\AnalyticsDetailPage ? 'analytics-dashboard.reset_detail' : 'analytics-dashboard.reset') }}</x-filament::button>
    </section>
    @if ($this instanceof \App\Filament\Shared\AnalyticsDetailPage)
        @include('filament.partials.analytics-subject')
    @endif
    <section class="ad-intro" aria-label="{{ __('analytics-dashboard.scope') }}">
        <p>{{ __('analytics-dashboard.intro') }}</p>
        <div class="ad-periods" role="group" aria-label="{{ __('analytics.period') }}">
            @foreach ([...\App\Enums\StatsPeriod::options(), 'year' => __('analytics-dashboard.year'), 'all' => __('analytics-dashboard.all_history')] as $value => $label)
                <x-filament::button :color="$this->period === $value ? 'primary' : 'gray'" :outlined="$this->period !== $value"
                    wire:click="setPeriod('{{ $value }}')" wire:loading.attr="disabled" :aria-pressed="$this->period === $value ? 'true' : 'false'">{{ $label }}</x-filament::button>
            @endforeach
        </div>
    </section>
    <x-filament::section :heading="__('analytics-dashboard.filters')">
        {{ $this->form }}
        <div class="ad-filter-foot">
            <p>{{ __('analytics-dashboard.channel_scope') }}</p>
        </div>
    </x-filament::section>
    <div wire:loading.delay class="ad-loading" role="status">{{ __('analytics-dashboard.loading') }}</div>
    <dl class="ad-summary" data-analytics-summary>
        @foreach (['views', 'interactions', 'short_shares', 'short_clicks', 'saves', 'bookings_confirmed'] as $key)
            <div><dt>{{ $metricLabel($key) }}</dt><dd>{{ number_format($totals[$key], 0, ',', '.') }}</dd></div>
        @endforeach
    </dl>
    <p class="ad-context">{{ __('analytics-dashboard.context', ['events' => $data['events']->count(), 'venues' => $data['venues']->count(), 'organizers' => $data['organizers']->count()]) }}</p>
    @if ($this->filters['event'] && ! ($this instanceof \App\Filament\Shared\AnalyticsDetailPage))
        @php $selected = $data['events']->first(); @endphp
        @if ($selected)
            <x-filament::section :heading="$selected['event']" :description="$selected['venue'].' · '.$selected['organizer']">
                <dl class="ad-event-facts">
                    @foreach (['city', 'category', 'status', 'occurrences'] as $key)<div><dt>{{ $metricLabel($key) }}</dt><dd>{{ $selected[$key] }}</dd></div>@endforeach
                </dl>
            </x-filament::section>
        @endif
    @endif
    <div class="ad-chart-grid">
        <x-filament::section :heading="__('analytics-dashboard.visits_chart')" :description="__('analytics-dashboard.chart_'.$data['series']['granularity'])">
            <x-analytics-trend :points="$series" :metrics="['views' => $metricLabel('views')]" :label="__('analytics-dashboard.visits_chart')" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.shares_chart')" :description="__('analytics-dashboard.shares_hint')">
            <x-analytics-trend :points="$series" :metrics="['short_shares' => $metricLabel('short_shares'), 'short_clicks' => $metricLabel('short_clicks')]" :label="__('analytics-dashboard.shares_chart')" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.channels_chart')" :description="__('analytics-dashboard.channels_hint')">
            <x-analytics-bars :rows="$data['channels']" name="channel" metric="clicks" :label="__('analytics-dashboard.channels_chart')" :secondary="['key' => 'shares', 'label' => __('event-shares.shares')]" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.actions_chart')" :description="__('analytics-dashboard.actions_hint')">
            <x-analytics-bars :rows="$interactionRows" name="name" metric="value" :label="__('analytics-dashboard.actions_chart')" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.events_chart')" :description="__('analytics-dashboard.rank_hint')">
            <x-analytics-bars :link="fn ($row) => $this->detailUrl('event', $row['id'])" :rows="$data['events']->sortByDesc('views')->take(8)->values()" name="event" metric="views" :label="__('analytics-dashboard.events_chart')" :secondary="['key' => 'short_clicks', 'label' => __('event-shares.clicks')]" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.venues_chart')" :description="__('analytics-dashboard.venues_hint')">
            <x-analytics-bars :link="fn ($row) => $this->detailUrl('venue', $row['id'])" :rows="$data['venues']->sortByDesc('event_views')->take(8)->values()" name="name" metric="event_views" :label="__('analytics-dashboard.venues_chart')" :secondary="['key' => 'short_clicks', 'label' => __('event-shares.clicks')]" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.organizers_chart')" :description="__('analytics-dashboard.rank_hint')">
            <x-analytics-bars :link="fn ($row) => $this->detailUrl('organizer', $row['id'])" :rows="$data['organizers']->sortByDesc('event_views')->take(8)->values()" name="name" metric="event_views" :label="__('analytics-dashboard.organizers_chart')" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.community_chart')" :description="__('analytics-dashboard.community_hint')">
            <x-analytics-bars :rows="$communityRows" name="name" metric="value" :label="__('analytics-dashboard.community_chart')" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.bookings_chart')" :description="__('analytics-dashboard.bookings_hint')">
            <x-analytics-bars :rows="$bookingRows" name="name" metric="value" :label="__('analytics-dashboard.bookings_chart')" />
        </x-filament::section>
        <x-filament::section :heading="__('analytics-dashboard.paid_chart')" :description="__('analytics-dashboard.paid_hint')">
            <dl class="ad-paid-totals"><div><dt>{{ $metricLabel('paid_impressions') }}</dt><dd>{{ number_format($totals['paid_impressions'], 0, ',', '.') }}</dd></div><div><dt>{{ $metricLabel('paid_clicks') }}</dt><dd>{{ number_format($totals['paid_clicks'], 0, ',', '.') }}</dd></div></dl>
            <x-analytics-bars :rows="$data['events']->where('paid_impressions', '>', 0)->sortByDesc('paid_impressions')->take(5)->values()" name="event" metric="paid_impressions" :label="__('analytics-dashboard.paid_chart')" :secondary="['key' => 'paid_clicks', 'label' => $metricLabel('paid_clicks')]" />
        </x-filament::section>
    </div>
    <x-filament::section :heading="__('analytics-dashboard.tables')" :description="__('analytics-dashboard.tables_hint')">
        <div class="ad-table-toolbar">
            <div class="ad-datasets" role="group" aria-label="{{ __('analytics-dashboard.tables') }}">
                @foreach (\App\Services\Analytics\EventAnalyticsExport::DATASETS as $dataset)
                    <button type="button" data-analytics-dataset="{{ $dataset }}" wire:click="setDataset('{{ $dataset }}')" @class(['ad-dataset', 'is-active' => $this->dataset === $dataset]) aria-pressed="{{ $this->dataset === $dataset ? 'true' : 'false' }}">{{ __('analytics-dashboard.datasets.'.$dataset) }} <span>{{ $data[$dataset]->count() }}</span></button>
                @endforeach
            </div>
            <div class="ad-export">
                <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="export('csv')" wire:loading.attr="disabled">{{ __('analytics-dashboard.csv') }}</x-filament::button>
                <x-filament::button icon="heroicon-o-arrow-down-tray" wire:click="export('xlsx')" wire:loading.attr="disabled">{{ __('analytics-dashboard.excel') }}</x-filament::button>
            </div>
        </div>
        <div class="ad-table-scroll" tabindex="0" role="region" aria-label="{{ __('analytics-dashboard.datasets.'.$this->dataset) }}">
            <table class="ad-table" data-event-share-report>
                <caption class="sr-only">{{ __('analytics-dashboard.datasets.'.$this->dataset) }}</caption>
                <thead><tr>@foreach (array_keys(\App\Services\Analytics\EventAnalyticsExport::displayRow($data[$this->dataset]->first() ?? [])) as $column)<th scope="col">{{ $metricLabel($column) }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            @foreach (\App\Services\Analytics\EventAnalyticsExport::displayRow($row) as $column => $value)
                                <td @class(['ad-number' => is_int($value) || is_float($value)])>
                                    @if ($column === 'event' && filled($row['event_id'] ?? $row['id'] ?? null))
                                        <a class="ad-drilldown" href="{{ $this->detailUrl('event', $row['event_id'] ?? $row['id']) }}">{{ $value }}</a>
                                    @elseif ($column === 'name' && in_array($this->dataset, ['venues', 'organizers']))
                                        <a class="ad-drilldown" href="{{ $this->detailUrl($this->dataset === 'venues' ? 'venue' : 'organizer', $row['id']) }}">{{ $value }}</a>
                                    @elseif (in_array($column, ['venue', 'organizer']) && filled($row[$column.'_id'] ?? null))
                                        <a class="ad-drilldown" href="{{ $this->detailUrl($column, $row[$column.'_id']) }}">{{ $value }}</a>
                                    @else
                                        {{ $value === null ? __('analytics-dashboard.unavailable') : (is_int($value) ? number_format($value, 0, ',', '.') : $value) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td>{{ __('analytics-dashboard.empty_table') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-filament::pagination :paginator="$rows->onEachSide(1)" :extreme-links="true" />
        <p class="ad-muted">{{ __('analytics-dashboard.export_hint') }}</p>
    </x-filament::section>
    <details class="ad-definitions"><summary>{{ __('analytics-dashboard.about_data') }}</summary><p>{{ __('analytics-dashboard.definitions') }}</p><p>{{ __('analytics-dashboard.not_collected') }}</p><p>{{ __('event-shares.notes') }}</p></details>
</x-filament-panels::page>
