{{--
    L'intestazione di sezione del sistema (D47): una regola piena di 2px sopra
    la riga del titolo — è il divisore a organizzare la pagina, non il
    bianco — titolo in Archivo 800 a filo a sinistra, e un quadratino pieno
    come marca tipografica. Il tono colora solo la marca: il resto è
    inchiostro su fondo.
--}}
@props([
    'title',
    'description' => null,
    /* Rimando facoltativo: "Vedi tutti →" */
    'href' => null,
    'linkLabel' => null,
    'level' => 'h2',
    /* Colore della marca quadrata: neutral · live · soon · brand · accent */
    'tone' => 'brand',
    'id' => null,
])

@php
    $marks = [
        'brand' => 'bg-accent',
        'live' => 'bg-accent',
        'soon' => 'bg-ink',
        'accent' => 'bg-accent',
        'neutral' => 'bg-line-strong',
    ];
@endphp

<div {{ $attributes->class(['border-t-2 border-line pt-3 mb-4 flex items-end justify-between gap-4']) }}>
    <div class="min-w-0">
        <div class="flex items-center gap-2.5">
            <span aria-hidden="true" class="size-2.5 shrink-0 {{ $marks[$tone] ?? $marks['brand'] }}"></span>

            <{{ $level }} @if ($id) id="{{ $id }}" @endif class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase uppercase">
                {{ $title }}
            </{{ $level }}>
        </div>

        @if ($description)
            <p class="mt-1.5 pl-[1.25rem] text-sm text-ink-muted">{{ $description }}</p>
        @endif
    </div>

    @if ($href)
        <a
            href="{{ $href }}"
            class="ui-action shrink-0 inline-flex items-center gap-1 px-1.5 py-1.5 font-display text-eyebrow font-extrabold uppercase text-brand transition hover:bg-brand/10"
        >
            {{ $linkLabel ?? __('common.actions.show_all') }}
            <x-lucide name="arrow-right" class="size-3.5" />
        </a>
    @endif
</div>
