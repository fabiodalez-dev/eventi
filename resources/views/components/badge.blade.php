@props([
    /* neutral · brand · live · soon · free · alert · muted · accent */
    'tone' => 'neutral',
    /* Puntino davanti all'etichetta; 'pulse' lo fa pulsare (usato da "in corso") */
    'dot' => false,
    /* Colore del puntino quando arriva dal dato (es. categories.color) */
    'dotColor' => null,
    'size' => 'md',
])

@php
    $tones = [
        'neutral' => 'bg-surface-sunken text-ink-muted ring-1 ring-line',
        'brand' => 'bg-brand-soft text-on-brand-soft',
        'live' => 'bg-live text-on-live',
        'soon' => 'bg-soon-soft text-on-soon-soft',
        'free' => 'bg-free-soft text-on-free-soft',
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
    'inline-flex items-center rounded-pill font-semibold uppercase tracking-wide whitespace-nowrap',
    $tones[$tone] ?? $tones['neutral'],
    $sizes[$size] ?? $sizes['md'],
]) }}>
    @if ($dot)
        <span
            @class([
                'size-1.5 shrink-0 rounded-pill',
                'bg-current' => $safeDotColor === null,
                'pulse-dot' => $dot === 'pulse',
            ])
            @if ($safeDotColor !== null) style="background-color: {{ $safeDotColor }}" @endif
        ></span>
    @endif

    {{ $slot }}
</span>
