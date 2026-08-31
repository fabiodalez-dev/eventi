{{--
    La griglia di card usata da homepage, liste, scheda evento e scheda locale.

    Il contesto temporale non lo decide questo componente: lo riceve da chi
    disegna la sezione, che sa da quale finestra di `EventOccurrenceQuery`
    arrivano le occorrenze (§8, D23).

    **Le colonne dipendono da quante occorrenze ci sono.** Una griglia fissa a
    quattro colonne è giusta per un catalogo e sbagliata per una sezione con
    due voci: lascia due colonne di niente accanto a ciò che il lettore stava
    guardando. E le sezioni con poche voci non sono un caso raro — "In corso
    adesso" e "Inizia tra poco" ne hanno quasi sempre una o due, e sono proprio
    quelle che rispondono alla domanda per cui il sito esiste.

    Fino a tre voci il formato cambia del tutto: `event-feature` le dispone in
    orizzontale, una per riga. Non e' solo per riempire lo spazio — una
    locandina 3:4 allargata a mezzo schermo diventa un manifesto che schiaccia
    tutto il resto della pagina, e la card verticale funziona solo quando ce ne
    stanno quattro in fila.
--}}
@props([
    'occurrences',
    'context' => 'upcoming',
    /* La prima riga sta sopra la piega: le sue locandine non si rinviano */
    'eager' => false,
    'showVenue' => true,
    'level' => 'h3',
    /* Le liste paginate restano a quattro colonne anche quando l'ultima pagina
       ne contiene tre: lì il numero è un residuo della paginazione, non una
       misura di quanto conta la sezione. */
    'adaptive' => true,
])

@php
    $count = $occurrences instanceof \Countable || is_array($occurrences)
        ? count($occurrences)
        : $occurrences->count();

    /* Fino a tre voci il formato e' orizzontale, da quattro in su e' un
       catalogo. La soglia non e' arbitraria: quattro e' il numero di colonne
       della griglia, cioe' il punto in cui una riga si riempie da sola. */
    $asFeature = $adaptive && $count > 0 && $count <= 3;
@endphp

@if ($asFeature)
    <div {{ $attributes->class(['flex flex-col gap-4']) }}>
        @foreach ($occurrences as $occurrence)
            <x-event-feature
                :occurrence="$occurrence"
                :context="$context"
                :show-venue="$showVenue"
                :level="$level"
                :eager="$eager && $loop->index === 0"
            />
        @endforeach
    </div>
@else
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
@endif
