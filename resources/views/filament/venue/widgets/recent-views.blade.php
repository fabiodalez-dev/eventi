{{-- Le schede più aperte negli ultimi giorni, con la locandina. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('manage.dashboard.recent_views')" :description="__('manage.dashboard.recent_views_description', ['days' => \App\Filament\Venue\Widgets\RecentViewsWidget::DAYS])" icon="heroicon-o-eye" data-dashboard-recent-views>
        <ul class="flex flex-col divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($this->cards() as $card)
                <li class="py-4 first:pt-0 last:pb-0">
                    @include('filament.venue.widgets.event-card', $card)
                </li>
            @endforeach
        </ul>
        @if ($statisticsUrl = $this->statisticsUrl())
            <div class="mt-4 flex flex-wrap gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
                <x-filament::button tag="a" :href="$statisticsUrl" color="gray" outlined icon="heroicon-o-chart-bar">{{ __('manage.dashboard.open_statistics') }}</x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
