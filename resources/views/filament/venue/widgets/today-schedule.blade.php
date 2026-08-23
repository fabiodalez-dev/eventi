@php
    $schedule = $this->schedule();
    $formatter = $this->formatter();
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('manage.dashboard.today_schedule')">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($schedule as $occurrence)
                <li class="flex items-baseline gap-3 py-2">
                    <span class="font-semibold tabular-nums">{{ $formatter->time($occurrence->starts_at) }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ $occurrence->event->title }}</span>
                    <x-filament::badge :color="$occurrence->status === \App\Enums\OccurrenceStatus::Scheduled ? 'success' : 'warning'">
                        {{ $occurrence->status->label() }}
                    </x-filament::badge>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
