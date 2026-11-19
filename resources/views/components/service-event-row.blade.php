{{--
    Un evento dentro un elenco di servizio — salvati, biglietti, serate,
    passaggi, date da gestire: la locandina a sinistra, titolo e riga «quando ·
    dove» a destra. Sempre con la locandina: un elenco di soli titoli costringe
    a leggere tutto per riconoscere una serata che si riconosce a colpo
    d'occhio dalla sua immagine. Senza locandina resta un riquadro della stessa
    misura, così le righe non cambiano altezza.

    Props: `occurrence` oppure `event`; `href` (per difetto la scheda
    dell'evento, `false` per nessun collegamento); `title` e `meta` sostituiscono
    il titolo e la riga «quando · dove»; `size` `md` (72×96) o `sm` (56×75).
    Slot: `badge` (accanto al titolo: lo stato), quello predefinito (righe in
    più sotto la data) e `actions` (pulsanti sotto il testo).

    Chi la usa carica prima `event.media` (o `occurrence.event.media`) e la
    sede: la riga non deve fare una query per ogni evento dell'elenco.
--}}
@props([
    'occurrence' => null,
    'event' => null,
    'href' => null,
    'title' => null,
    'meta' => null,
    'size' => 'md',
])
@php
    $event ??= $occurrence?->event;
    $formatter = app(\App\Support\DateFormatter::class);

    if ($href === null && $occurrence !== null && $event !== null && \Illuminate\Support\Facades\Route::has('events.occurrence')) {
        $href = \App\Support\EventUrl::occurrence($occurrence);
    } elseif ($href === null && $event !== null && \Illuminate\Support\Facades\Route::has('events.show')) {
        $href = route('events.show', ['slug' => $event->slug]);
    }
    $href = $href === false ? null : $href;

    if ($meta === null) {
        $venue = $occurrence?->locationVenue() ?? $event?->locationVenue();
        $place = $venue?->name ?? (is_array($event?->custom_location) ? ($event->custom_location['name'] ?? null) : null);
        $when = null;
        if ($occurrence !== null) {
            $when = $occurrence->is_all_day
                ? $formatter->day($occurrence->business_date)
                : $formatter->dayAndTime($occurrence->business_date, $occurrence->starts_at);
        }
        $meta = collect([$when, $place])->filter()->implode(' '.__('common.separator').' ');
    }

    $artwork = $event !== null ? \App\Support\Poster::imageSet($event) : null;
    $titleText = $title ?? $event?->title ?? '';
@endphp
<article data-event-row {{ $attributes->class(['flex min-w-0 items-start gap-4']) }}>
    @if ($href !== null)
        <a href="{{ $href }}" tabindex="-1" aria-hidden="true" @class(['svc-poster', 'svc-poster--sm' => $size === 'sm'])>
    @else
        <span aria-hidden="true" @class(['svc-poster', 'svc-poster--sm' => $size === 'sm'])>
    @endif
        @if ($artwork !== null)
            <x-event-artwork :event="$event" :sizes="$size === 'sm' ? '56px' : '72px'" />
        @else
            <span class="svc-poster__placeholder" data-poster-placeholder><x-lucide name="calendar" class="size-6" /></span>
        @endif
    @if ($href !== null)
        </a>
    @else
        </span>
    @endif
    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
        <div class="flex flex-wrap items-start gap-x-3 gap-y-1.5">
            <h3 class="m-0 min-w-0 font-display text-base leading-snug font-extrabold tracking-[-0.01em] text-ink">
                @if ($href !== null)
                    <a href="{{ $href }}" class="text-ink no-underline hover:text-brand">{{ $titleText }}</a>
                @else
                    {{ $titleText }}
                @endif
            </h3>
            @isset($badge)
                <span class="flex shrink-0 flex-wrap gap-1.5">{{ $badge }}</span>
            @endisset
        </div>
        @if (filled($meta))
            <p class="m-0 text-sm text-ink-muted">{{ $meta }}</p>
        @endif
        @if ($slot->isNotEmpty())
            <div class="flex flex-col gap-1 text-sm text-ink-muted">{{ $slot }}</div>
        @endif
        @isset($actions)
            <div class="mt-1.5 flex flex-wrap gap-2">{{ $actions }}</div>
        @endisset
    </div>
</article>
