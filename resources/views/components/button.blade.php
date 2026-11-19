{{--
    Il pulsante del sistema (D47). Tre varianti:

    - `primary`: campitura piena d'accento, il gesto principale della pagina;
    - `secondary`: superficie separata e bordo — le azioni di contorno restano
      distinguibili anche quando poggiano su un pannello chiaro;
    - `ghost`: solo testo d'accento, per le azioni minori in linea.

    L'etichetta sta a filo a sinistra: un pulsante più largo del proprio testo
    comincia il testo al bordo interno sinistro, icona in coda compresa. È una
    regola esplicita del sistema, non un dettaglio — mai centrare.

    Gli stati sono del tema, mai del browser: hover e premuto scendono lungo
    la rampa dell'accento, il focus da tastiera è il contorno globale di
    `app.css` (2px pieni d'accento, staccati di 2px).

    Con `href` diventa un collegamento vestito da pulsante: la navigazione
    resta navigazione e funziona senza JavaScript.

    `danger` è il gesto distruttivo (eliminare, annullare, uscire): pieno, nel
    rosso del tema, e non va usato per altro. `size` sceglie l'altezza — `md`
    48px, il bersaglio comodo per il pollice, `sm` 40px per le azioni dentro
    una riga — e `block` allarga il pulsante a tutta la riga sul telefono,
    dove due pulsanti affiancati di larghezze diverse si leggono male.
--}}
@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'size' => 'md',
    'block' => false,
])

@php
    $variants = [
        'primary' => 'bg-brand text-on-brand hover:bg-brand-strong active:bg-brand-strong',
        'secondary' => 'border-2 border-line bg-surface text-ink hover:border-accent hover:bg-canvas active:bg-ink/15',
        'ghost' => 'text-brand px-1.5 hover:bg-brand/10 active:bg-brand/20',
        'danger' => 'svc-button--danger bg-danger text-on-danger',
    ];

    $sizes = [
        'md' => 'min-h-12 px-4 py-2',
        'sm' => 'min-h-10 px-3 py-1.5',
    ];

    $classes = [
        'ui-action inline-flex items-center justify-start gap-1.5 text-left',
        'font-display text-sm font-extrabold leading-tight',
        'cursor-pointer no-underline transition',
        'disabled:cursor-not-allowed disabled:opacity-45',
        $sizes[$size] ?? $sizes['md'],
        'w-full sm:w-auto' => $block,
        $variants[$variant] ?? $variants['primary'],
    ];
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
