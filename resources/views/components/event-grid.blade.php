{{--
    La griglia di card usata da homepage, liste, scheda evento e scheda locale.

    Il contesto temporale non lo decide questo componente: lo riceve da chi
    disegna la sezione, che sa da quale finestra di `EventOccurrenceQuery`
    arrivano le occorrenze (§8, D23).

    **Perché il divisore sta sui bordi delle card e non sotto la griglia**
    (D46). Il riferimento ottiene le righe da due pixel dando alla griglia il
    colore del divisore e alle card quello della pagina: lo spazio fra l'una e
    l'altra è il fondo che traspare. Funziona finché l'ultima riga è piena —
    ma `auto-fill` tiene in piedi le colonne anche quando le card finiscono, e
    lì quel fondo non è più una riga sottile: è un rettangolo grigio grande
    quanto tre card mancanti. Con una sezione di una sola data, che è il caso
    normale in un giorno feriale, mezza pagina diventa grigia.

    Quindi il divisore lo disegnano le card, sul bordo destro e su quello
    inferiore. Nessun raddoppio, perché ogni card ne disegna due soli; le celle
    vuote restano fondo pagina; e l'elenco resta il tabellone continuo che
    deve essere, senza angoli arrotondati e senza ombre.

    **Perché il numero di voci non cambia più il formato.** Prima, fino a tre
    occorrenze, la sezione passava a un formato orizzontale. Nel riferimento
    una griglia con due card è una griglia con due card: le colonne si
    riempiono da sinistra e il resto della riga resta fondo. Un elenco che
    cambia forma a seconda di quanto è lungo costringe chi guarda a rileggerlo
    ogni volta.
--}}
@props([
    'occurrences',
    'context' => 'upcoming',
    'showVenue' => true,
    'level' => 'h3',
    'showPoster' => false,
    /* La numerazione progressiva delle card. Si spegne dove le card non sono
       un elenco ordinato — i risultati di una ricerca, per esempio, dove il
       numero suggerirebbe una classifica che non c'è. */
    'numbered' => true,
    /* Da quale numero parte questa griglia: le liste paginate continuano il
       conteggio invece di ricominciare da «01» a ogni pagina. */
    'offset' => 0,
    /* Related-event groups use 1 / 2 / 4 columns, never an orphaned 3 + 1. */
    'balanced' => false,
])

<div {{ $attributes->class([
    'grid',
    'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4' => $balanced,
    '[grid-template-columns:repeat(auto-fill,minmax(min(298px,100%),1fr))]' => ! $balanced,
]) }}>
    @foreach ($occurrences as $occurrence)
        <x-event-card
            :occurrence="$occurrence"
            :context="$context"
            :show-venue="$showVenue"
            :level="$level"
            :show-poster="$showPoster"
            :index="$numbered ? $offset + $loop->iteration : null"
            class="border-r-2 border-b-2 border-line"
        />
    @endforeach
</div>
