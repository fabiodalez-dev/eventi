{{--
    Una riga d'elenco dentro un `<x-card padding="none">`: una voce del
    profilo, un avviso, un'impostazione. Alta almeno 48px, il filo divisore lo
    mette il foglio di stile fra una riga e la successiva.

    Con `href` l'intera riga è il collegamento, con la freccia in coda.
    Props: `href`, `title`, `description`, `icon` (nome Lucide), `badge`
    (testo breve). Slot: `leading` (al posto dell'icona: un avatar, una
    locandina), `trailing` (al posto della freccia: un pulsante, un valore) e
    quello predefinito, sotto la descrizione.
--}}
@props([
    'href' => null,
    'title' => null,
    'description' => null,
    'icon' => null,
    'badge' => null,
])
@php($tag = $href !== null ? 'a' : 'div')
<{{ $tag }}
    data-card-row
    @if ($href !== null) href="{{ $href }}" @endif
    {{ $attributes->class(['svc-row flex min-h-12 items-center gap-3 px-5 py-3 sm:px-6', 'text-ink no-underline' => $href !== null]) }}
>
    @isset($leading)
        <span class="flex shrink-0 items-center">{{ $leading }}</span>
    @elseif ($icon !== null)
        <span class="flex shrink-0 items-center text-ink-muted"><x-lucide :name="$icon" class="size-5" /></span>
    @endisset
    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
        @if (filled($title) || filled($badge))
            <span class="flex flex-wrap items-center gap-2">
                @if (filled($title))
                    <span class="font-semibold text-ink">{{ $title }}</span>
                @endif
                @if (filled($badge))
                    <x-badge tone="brand" size="sm">{{ $badge }}</x-badge>
                @endif
            </span>
        @endif
        @if (filled($description))
            <span class="text-sm text-ink-muted">{{ $description }}</span>
        @endif
        @if ($slot->isNotEmpty())
            <span class="flex flex-col gap-1 text-sm text-ink-muted">{{ $slot }}</span>
        @endif
    </span>
    @isset($trailing)
        <span class="flex shrink-0 items-center gap-2">{{ $trailing }}</span>
    @elseif ($href !== null)
        <span class="shrink-0 text-ink-subtle"><x-lucide name="chevron-right" class="size-5" /></span>
    @endisset
</{{ $tag }}>
