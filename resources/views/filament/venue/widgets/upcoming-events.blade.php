{{-- I prossimi eventi, uno per evento, con la locandina e le pagine collegate. --}}
@php $cards = $this->cards(); @endphp
<x-filament-widgets::widget>
    <x-filament::section :heading="__('manage.dashboard.upcoming_heading')" :description="__('manage.dashboard.upcoming_description')" icon="heroicon-o-calendar" data-dashboard-upcoming>
        @if ($cards === [])
            {{-- Il pulsante «Nuovo evento» è già nel riquadro sopra: qui basta dire cosa fare. --}}
            <x-filament::empty-state :heading="__('manage.dashboard.upcoming_empty')" :description="__('manage.dashboard.upcoming_empty_body')" icon="heroicon-o-calendar-days" :contained="false" />
        @else
            <ul class="flex flex-col divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($cards as $card)
                    <li class="py-4 first:pt-0 last:pb-0">
                        @include('filament.venue.widgets.event-card', $card)
                    </li>
                @endforeach
            </ul>
            @if ($listUrl = $this->listUrl())
                <div class="mt-4 flex flex-wrap gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
                    <x-filament::button tag="a" :href="$listUrl" color="gray" outlined icon="heroicon-o-arrow-right" icon-position="after">{{ __('manage.dashboard.all_events') }}</x-filament::button>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
