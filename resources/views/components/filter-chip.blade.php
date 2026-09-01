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
        /*
         * `whitespace-nowrap` è sbagliato qui, e si vedeva: «Questa settimana»
         * dentro una cella della colonna dei filtri — larga circa 120px — non
         * ci sta su una riga sola, e usciva dal bordo del pulsante. Il testo va
         * a capo, il pulsante cresce in altezza, e `text-balance` spezza le due
         * parole in modo equilibrato invece di lasciare una riga piena e una
         * con una parola sola.
         *
         * `leading-[1.25]` e non `leading-none`: con due righe, l'interlinea a
         * zero le fa toccare.
         */
        'inline-flex items-center gap-1.5 border-2 px-2.5 py-1.5 font-display text-[0.625rem] leading-[1.25] font-extrabold tracking-[0.12em] text-balance uppercase transition-colors',
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
