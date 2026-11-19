{{-- Tutte le pagine del pannello, in gruppi di pulsanti che vanno a capo. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('manage.dashboard.shortcuts')" :description="__('manage.dashboard.shortcuts_description')" icon="heroicon-o-squares-2x2" data-dashboard-shortcuts>
        <div class="flex flex-col gap-5">
            @foreach ($this->groups() as $group => $items)
                <nav class="flex flex-col gap-2" aria-label="{{ __('manage.dashboard.'.$group) }}">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('manage.dashboard.'.$group) }}</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($items as $item)
                            <x-filament::button tag="a" :href="$item['url']" :icon="$item['icon']" :color="$item['primary'] ? 'primary' : 'gray'" :outlined="! $item['primary']" :data-shortcut="$item['key']">{{ $item['label'] }}</x-filament::button>
                        @endforeach
                    </div>
                </nav>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
