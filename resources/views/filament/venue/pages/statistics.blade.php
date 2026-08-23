@php
    $selected = $this->selectedPeriod();
    $totals = $this->totals();
    $events = $this->events();

    $tiles = [
        ['label' => __('manage.statistics.views'), 'value' => $totals['views']],
        ['label' => __('manage.statistics.saves'), 'value' => $totals['saves']],
        ['label' => __('manage.statistics.directions'), 'value' => $totals['direction_clicks']],
        ['label' => __('manage.statistics.tickets'), 'value' => $totals['ticket_clicks']],
    ];
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap gap-2">
        @foreach ($this->periodOptions() as $value => $label)
            <x-filament::button
                :color="$selected->value === $value ? 'primary' : 'gray'"
                :outlined="$selected->value !== $value"
                wire:click="setPeriod('{{ $value }}')"
            >
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($tiles as $tile)
            <x-filament::section compact>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                <div class="mt-1 text-3xl font-semibold tabular-nums">
                    {{ \Illuminate\Support\Number::format($tile['value'], locale: 'it') }}
                </div>
            </x-filament::section>
        @endforeach
    </div>

    @if ($events->isNotEmpty())
        <x-filament::section :heading="__('manage.statistics.per_event')">
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($events as $event)
                    <li class="flex flex-wrap items-baseline gap-x-4 gap-y-1 py-2">
                        <span class="min-w-0 flex-1 truncate font-medium">{{ $event->title }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('manage.statistics.views') }}
                            <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ (int) $event->views_total }}</span>
                        </span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('manage.statistics.directions') }}
                            <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ (int) $event->direction_clicks_total }}</span>
                        </span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('manage.statistics.tickets') }}
                            <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ (int) $event->ticket_clicks_total }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
