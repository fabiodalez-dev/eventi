{{--
    La compagna di `EventPicker` per «tutti gli eventi»: quanti eventi del
    committente si possono sponsorizzare oggi, i primi in ordine di data con la
    riga dei pannelli, e la frase che spiega che i nuovi si aggiungono da soli.

    Variabili: `sponsor` (nome o null), `count` (int), `events` (eventi con
    `next_starts_at` già calcolata).
--}}
@php
    $sponsor ??= null;
    $count = (int) ($count ?? 0);
    $events ??= [];
@endphp
<div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5" data-all-events data-count="{{ $count }}">
    @if ($sponsor === null)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.wizard.all_events.no_sponsor') }}</p>
    @else
        <p class="text-base font-semibold text-gray-950 dark:text-white">
            {{ trans_choice('sponsorships.wizard.all_events.count', $count, ['count' => $count, 'sponsor' => $sponsor]) }}
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.wizard.all_events.lead') }}</p>

        @if ($events !== [])
            <ul class="flex flex-col gap-3" role="list">
                @foreach ($events as $event)
                    <li><x-pannelli::event-row :event="$event" :venue="$event->venue?->name" size="sm" /></li>
                @endforeach
            </ul>
            @if ($count > count($events))
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans_choice('sponsorships.wizard.all_events.more', $count - count($events), ['count' => $count - count($events)]) }}</p>
            @endif
        @endif
    @endif
</div>
