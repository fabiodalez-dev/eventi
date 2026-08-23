@props([
    'title',
    'description' => null,
    /* Rimando facoltativo: "Vedi tutti →" */
    'href' => null,
    'linkLabel' => null,
    'level' => 'h2',
    /* Barretta di colore a sinistra: neutral · live · soon · brand · accent */
    'tone' => 'brand',
    'id' => null,
])

@php
    $bars = [
        'brand' => 'bg-brand',
        'live' => 'bg-live',
        'soon' => 'bg-soon',
        'accent' => 'bg-accent',
        'neutral' => 'bg-line-strong',
    ];
@endphp

<div {{ $attributes->class(['flex items-end justify-between gap-4 mb-4']) }}>
    <div class="min-w-0">
        <div class="flex items-center gap-2.5">
            <span aria-hidden="true" class="h-5 w-1 rounded-pill {{ $bars[$tone] ?? $bars['brand'] }}"></span>

            <{{ $level }} @if ($id) id="{{ $id }}" @endif class="text-section text-ink">
                {{ $title }}
            </{{ $level }}>
        </div>

        @if ($description)
            <p class="mt-1.5 pl-3.5 text-sm text-ink-muted">{{ $description }}</p>
        @endif
    </div>

    @if ($href)
        <a
            href="{{ $href }}"
            class="shrink-0 rounded-pill px-3 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand-soft"
        >
            {{ $linkLabel ?? __('common.actions.show_all') }}
            <span aria-hidden="true">&rarr;</span>
        </a>
    @endif
</div>
