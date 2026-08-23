{{--
    Il contenuto del foglio inferiore della mappa: le date di un locale che
    rispondono ai filtri accesi (§11.6).

    È un frammento, non una pagina: nessun layout, nessuna intestazione. La
    card è la stessa `<x-event-card>` di tutto il resto del sito — averne una
    seconda scritta in JavaScript significherebbe mantenerne due e vederle
    divergere al primo cambio di badge.
--}}
<div class="flex flex-col gap-3">
    <div>
        <h2 class="text-card text-ink">
            <a href="{{ route('venues.show', $venue) }}" class="hover:underline">{{ $venue->name }}</a>
        </h2>

        <p class="text-sm text-ink-subtle">
            {{ $venue->municipality }}
            @if ($occurrences->isNotEmpty())
                <span aria-hidden="true">{{ __('common.separator') }}</span>
                {{ trans_choice('map.venue_events', $occurrences->count(), ['count' => $occurrences->count()]) }}
            @endif
        </p>
    </div>

    @foreach ($occurrences as $occurrence)
        <x-event-card :occurrence="$occurrence" :show-venue="false" level="h3" />
    @endforeach

    <a
        href="{{ route('venues.show', $venue) }}"
        class="rounded-pill bg-surface-sunken px-4 py-2 text-center text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
    >
        {{ __('map.venue_page') }}
    </a>
</div>
