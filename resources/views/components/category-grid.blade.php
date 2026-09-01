{{--
    La griglia per categoria (§11.2, punto 9).

    Compaiono solo le categorie che hanno davvero date in programma: il
    controller le conta con una sola interrogazione aggregata. Una casella che
    porta a una lista vuota è un contenitore vuoto con un passaggio in mezzo
    (§8.6).

    Nel riferimento (D46) la casella si riempie di giallo-verde al passaggio e
    il testo si inverte: è l'unico posto della pagina dove il colore copre una
    superficie intera, ed è quello che rende la griglia il punto in cui si
    sceglie invece che una legenda da leggere. Il pallino colorato della
    categoria non c'è più — con un accento solo, dieci pallini di dieci colori
    diversi erano l'unica cosa che rompeva la tavolozza.
--}}
@props(['categories'])

<div {{ $attributes->class(['grid [grid-template-columns:repeat(auto-fill,minmax(min(178px,100%),1fr))]']) }}>
    @foreach ($categories as $entry)
        @php $category = $entry['category']; @endphp

        <a
            href="{{ route('events.category', $category) }}"
            class="flex flex-col items-start gap-4 border-r-2 border-b-2 border-line px-[18px] py-5 transition-colors duration-200 hover:bg-accent hover:text-on-accent"
        >
            <span class="font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase">
                {{ trans_choice('events.sections.category_count', $entry['count'], ['count' => $entry['count']]) }}
            </span>

            <span class="font-display text-[clamp(1.063rem,1.5vw,1.375rem)] leading-none font-extrabold tracking-[-0.02em] uppercase">
                {{ $category->name }}
            </span>
        </a>
    @endforeach
</div>
