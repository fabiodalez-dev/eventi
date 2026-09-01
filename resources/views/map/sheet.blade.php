{{--
    Il contenuto del foglio inferiore della mappa: le date di un locale che
    rispondono ai filtri accesi (§11.6).

    È un frammento, non una pagina: nessun layout, nessuna intestazione. La
    card è la stessa `<x-event-card>` di tutto il resto del sito — averne una
    seconda scritta in JavaScript significherebbe mantenerne due e vederle
    divergere al primo cambio di badge.

    **Un pin è un locale, non un evento** (§11.6): due concerti nello stesso
    circolo hanno le stesse coordinate, e disegnarli come due punti li
    lascerebbe sovrapposti a qualunque ingrandimento. Toccando il punto si apre
    quindi ciò che succede lì — una card per data, ognuna un collegamento alla
    propria scheda.
--}}
<div class="flex flex-col">
    <div class="flex flex-col gap-1 border-b-2 border-line pb-3">
        <h2 class="m-0 font-display text-[1.125rem] leading-none font-extrabold tracking-[-0.02em] uppercase">
            <a href="{{ route('venues.show', $venue) }}" class="hover:text-accent">{{ $venue->name }}</a>
        </h2>

        <p class="m-0 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-ink-subtle uppercase">
            {{ $venue->zone ?: $venue->municipality }}
            @if ($occurrences->isNotEmpty())
                <span aria-hidden="true">{{ __('common.separator') }}</span>
                {{ trans_choice('map.venue_events', $occurrences->count(), ['count' => $occurrences->count()]) }}
            @endif
        </p>
    </div>

    @foreach ($occurrences as $occurrence)
        <x-event-card
            :occurrence="$occurrence"
            :show-venue="false"
            :index="$loop->iteration"
            level="h3"
            class="border-b-2 border-line px-0 pl-3"
        />
    @endforeach

    <a
        href="{{ route('venues.show', $venue) }}"
        class="mt-3 flex items-center justify-center border-2 border-line px-4 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent"
    >
        {{ __('map.venue_page') }}
    </a>
</div>
