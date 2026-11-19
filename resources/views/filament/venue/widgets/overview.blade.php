{{-- «Il tuo cartellone»: tre numeri che vanno a capo e il pulsante per creare un evento. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('manage.dashboard.summary')" :description="__('manage.dashboard.summary_description')" icon="heroicon-o-calendar-days" data-dashboard-overview>
        <dl class="flex flex-wrap gap-3">
            @foreach ($this->numbers() as $number)
                <div class="min-w-[8.5rem] flex-1 rounded-lg border border-gray-200 p-3 dark:border-white/10" data-dashboard-number="{{ $number['key'] }}">
                    <dt class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $number['label'] }}</dt>
                    <dd class="mt-1">
                        @if ($number['url'])
                            <a href="{{ $number['url'] }}" class="text-2xl font-semibold tabular-nums text-gray-950 hover:underline focus-visible:underline dark:text-white">{{ $number['value'] }}</a>
                        @else
                            <span class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $number['value'] }}</span>
                        @endif
                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $number['hint'] }}</span>
                    </dd>
                </div>
            @endforeach
        </dl>
        @if ($createUrl = $this->createUrl())
            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button tag="a" :href="$createUrl" icon="heroicon-o-plus" data-dashboard-create>{{ __('manage.actions.new_event') }}</x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
