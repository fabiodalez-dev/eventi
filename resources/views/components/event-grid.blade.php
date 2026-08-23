{{--
    La griglia di card usata da homepage, liste, scheda evento e scheda locale.

    Il contesto temporale non lo decide questo componente: lo riceve da chi
    disegna la sezione, che sa da quale finestra di `EventOccurrenceQuery`
    arrivano le occorrenze (§8, D23).
--}}
@props([
    'occurrences',
    'context' => 'upcoming',
    /* La prima riga sta sopra la piega: le sue locandine non si rinviano */
    'eager' => false,
    'showVenue' => true,
    'level' => 'h3',
])

<div {{ $attributes->class(['grid gap-4 sm:grid-cols-2 lg:grid-cols-4']) }}>
    @foreach ($occurrences as $occurrence)
        <x-event-card
            :occurrence="$occurrence"
            :context="$context"
            :show-venue="$showVenue"
            :level="$level"
            :eager="$eager && $loop->index < 4"
        />
    @endforeach
</div>
