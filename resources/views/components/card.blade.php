{{--
    Il riquadro delle pagine di servizio: un blocco di contenuto con un titolo,
    una spiegazione facoltativa, le sue azioni e un piede.

    Props:
    - `as`: il tag (`section` per difetto; `div`, `article`, `form`…);
    - `title`, `description`, `level` (`h2` per difetto: l'h1 è della pagina);
    - `tone`: `default`, `muted` (secondario), `accent` (da notare), `alert`
      (qualcosa non va, o una zona pericolosa);
    - `padding`: `md`, `sm`, oppure `none` per un riquadro fatto di righe
      `<x-card.row>` che arrivano fino al bordo;
    - `id`.

    Slot: quello predefinito, `actions` (a destra del titolo, sotto di lui sul
    telefono) e `footer` (separato da un filo). Bordo, fondo e raggio vengono
    dal tema (`service.css`): qui non c'è un colore scritto a mano.
--}}
@props([
    'as' => 'section',
    'title' => null,
    'description' => null,
    'level' => 'h2',
    'tone' => 'default',
    'padding' => 'md',
    'id' => null,
])
@php
    $tag = in_array($as, ['section', 'div', 'article', 'aside', 'form', 'fieldset', 'li'], true) ? $as : 'section';
    $heading = in_array($level, ['h2', 'h3', 'h4'], true) ? $level : 'h2';
    $tone = in_array($tone, ['default', 'muted', 'accent', 'alert'], true) ? $tone : 'default';
    $pad = ['md' => 'p-5 sm:p-6', 'sm' => 'p-4', 'none' => ''][$padding] ?? 'p-5 sm:p-6';
    $flush = $padding === 'none';
    $hasHead = filled($title) || filled($description) || isset($actions);
    $titleId = $id !== null && filled($title) ? $id.'-titolo' : null;
@endphp
<{{ $tag }}
    data-card
    @if ($id !== null) id="{{ $id }}" @endif
    @if ($titleId !== null && $tag === 'section') aria-labelledby="{{ $titleId }}" @endif
    {{ $attributes->class([
        'svc-card flex flex-col',
        'svc-card--'.$tone => $tone !== 'default',
        'svc-card--flush' => $flush,
        'gap-4' => ! $flush,
        $pad => ! $flush,
    ]) }}
>
    @if ($hasHead)
        <div @class(['flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between', 'svc-card__head--flush p-5 sm:px-6' => $flush])>
            @if (filled($title) || filled($description))
                <div class="flex min-w-0 flex-col gap-1">
                    @if (filled($title))
                        <{{ $heading }} @if ($titleId !== null) id="{{ $titleId }}" @endif class="font-display text-lg leading-tight font-extrabold tracking-[-0.015em] text-ink">{{ $title }}</{{ $heading }}>
                    @endif
                    @if (filled($description))
                        <p class="text-sm text-ink-muted">{{ $description }}</p>
                    @endif
                </div>
            @endif
            @isset($actions)
                <div class="flex shrink-0 flex-wrap gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    @if ($slot->isNotEmpty())
        @if ($flush)
            <div class="flex flex-col">{{ $slot }}</div>
        @else
            <div class="flex min-w-0 flex-col gap-4">{{ $slot }}</div>
        @endif
    @endif
    @isset($footer)
        <div @class(['svc-card__footer flex flex-wrap items-center gap-3', 'pt-4' => ! $flush, 'px-5 py-4 sm:px-6' => $flush])>{{ $footer }}</div>
    @endisset
</{{ $tag }}>
