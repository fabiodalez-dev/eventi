{{--
    Una pillola di filtro. È **un link**, non un pulsante: accendere un filtro
    cambia l'indirizzo della pagina, e deve poterlo fare anche chi ha
    JavaScript disattivato o chi apre il filtro in una scheda nuova (§11.3).

    `aria-pressed` non si usa sui link: lo stato acceso si dichiara con
    `aria-current`, che è ciò che uno screen reader legge come "pagina corrente".

    Nel riferimento (D46) la pillola non è una pillola: è un rettangolo con
    bordo da 2px che al passaggio si riempie di giallo-verde. Il nome del
    componente resta perché è così che lo chiamano tutte le pagine, ma la
    forma è quadrata come tutto il resto.
--}}
@props([
    'href',
    'active' => false,
    'count' => null,
])

<a
    href="{{ $href }}"
    @if ($active) aria-current="true" @endif
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 border-2 px-2.5 py-1.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.12em] whitespace-nowrap uppercase transition-colors',
        'border-accent bg-accent text-on-accent' => $active,
        /* Il fondo lo dichiara il componente e non chi lo usa: quando lo
           dichiarava la pagina, `bg-canvas` finiva per vincere anche sullo
           stato acceso, e il chip acceso diventava testo nero su fondo nero.
           Un colore che dipende dallo stato appartiene al componente. */
        'border-line bg-canvas text-ink-muted hover:border-accent hover:text-accent' => ! $active,
    ]) }}
>
    {{ $slot }}

    @if ($count !== null)
        <span class="{{ $active ? 'text-on-accent/70' : 'text-ink-subtle' }}">{{ $count }}</span>
    @endif

    @if ($active)
        <span aria-hidden="true">&times;</span>
    @endif
</a>
