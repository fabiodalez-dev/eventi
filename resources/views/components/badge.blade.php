{{--
    L'etichetta del sistema (D47): rettangolare — il raggio è zero ovunque —
    maiuscola, spaziata, in Archivo 800. Le tinte vengono dalle rampe: passi
    chiari come campitura, passi scuri per il testo sopra. Schema mono: il
    rosso pieno è riservato a "in corso", gli altri toni sono inchiostro e
    tinte della stessa rampa; "gratis" è il solo contorno.
--}}
@props([
    /* neutral · brand · live · soon · free · alert · muted · accent */
    'tone' => 'neutral',
    /* Quadratino davanti all'etichetta; 'pulse' lo fa pulsare (usato da "in corso") */
    'dot' => false,
    /* Colore del quadratino quando arriva dal dato (es. categories.color) */
    'dotColor' => null,
    'size' => 'md',
])

@php
    $tones = [
        'neutral' => 'bg-surface text-ink-muted border-2 border-line',
        'brand' => 'bg-brand-soft text-on-brand-soft',
        'live' => 'bg-live text-on-live',
        'soon' => 'bg-soon-soft text-on-soon-soft',
        'free' => 'bg-free-soft text-on-free-soft border-2 border-accent',
        'accent' => 'bg-accent-soft text-on-accent',
        'alert' => 'bg-live-soft text-on-live-soft',
        'muted' => 'bg-muted-badge text-on-muted-badge',
    ];

    $sizes = [
        'sm' => 'text-[0.625rem] px-2 py-0.5 gap-1',
        'md' => 'text-eyebrow px-2.5 py-1 gap-1.5',
    ];

    /* Il colore arriva dal database (categories.color): entra in un attributo
       style solo se è davvero una notazione esadecimale. */
    $safeDotColor = is_string($dotColor) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $dotColor) === 1
        ? $dotColor
        : null;
@endphp

<span {{ $attributes->class([
    'inline-flex items-center font-display font-extrabold uppercase tracking-[0.12em] whitespace-nowrap',
    $tones[$tone] ?? $tones['neutral'],
    $sizes[$size] ?? $sizes['md'],
]) }}>
    @if ($dot)
        <span
            @class([
                'size-1.5 shrink-0',
                'bg-current' => $safeDotColor === null,
                'pulse-dot' => $dot === 'pulse',
            ])
            @if ($safeDotColor !== null) style="background-color: {{ $safeDotColor }}" @endif
        ></span>
    @endif

    {{ $slot }}
</span>
