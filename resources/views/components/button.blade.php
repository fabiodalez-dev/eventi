{{--
    Il pulsante del sistema (D47). Tre varianti:

    - `primary`: campitura piena d'accento, il gesto principale della pagina;
    - `secondary`: bordo, nessuna campitura — le azioni di contorno;
    - `ghost`: solo testo d'accento, per le azioni minori in linea.

    L'etichetta sta a filo a sinistra: un pulsante più largo del proprio testo
    comincia il testo al bordo interno sinistro, icona in coda compresa. È una
    regola esplicita del sistema, non un dettaglio — mai centrare.

    Gli stati sono del tema, mai del browser: hover e premuto scendono lungo
    la rampa dell'accento, il focus da tastiera è il contorno globale di
    `app.css` (2px pieni d'accento, staccati di 2px).

    Con `href` diventa un collegamento vestito da pulsante: la navigazione
    resta navigazione e funziona senza JavaScript.
--}}
@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'bg-brand text-on-brand hover:bg-brand-strong active:bg-brand-strong',
        'secondary' => 'border border-line text-ink hover:bg-ink/8 active:bg-ink/15',
        'ghost' => 'text-brand px-1.5 hover:bg-brand/10 active:bg-brand/20',
    ];

    $classes = [
        'ui-action inline-flex items-center justify-start gap-1.5 text-left',
        'font-display text-sm font-extrabold leading-tight',
        'min-h-12 px-4 py-2 cursor-pointer no-underline transition',
        'disabled:cursor-not-allowed disabled:opacity-45',
        $variants[$variant] ?? $variants['primary'],
    ];
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
