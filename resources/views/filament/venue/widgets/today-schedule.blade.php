{{-- Le serate di oggi, una card per data con la locandina. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('manage.dashboard.today_schedule')" :description="__('manage.dashboard.today_schedule_description')" icon="heroicon-o-sun" data-dashboard-today>
        <ul class="flex flex-col divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($this->cards() as $card)
                <li class="py-4 first:pt-0 last:pb-0">
                    @include('filament.venue.widgets.event-card', $card)
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
