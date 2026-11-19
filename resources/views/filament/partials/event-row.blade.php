{{--
    La riga di un evento nelle viste dei pannelli: locandina, titolo in
    grassetto, sotto «sab 22 nov, 21:00 · Officina dei Portici».

    Si usa come componente, `<x-pannelli::event-row :event="$event" … />`, o
    con `@include('filament.partials.event-row', [...])` quando non servono
    gli slot.

    Props: `event`, `when` (istante da mostrare; se manca, la prossima data:
    chi disegna un elenco la carica prima, o passa `when`, per non fare una
    query per riga), `venue` (nome o null), `href`, `size` (`sm` 36×48, `md`
    48×64, `lg` 72×96), `bar` (0–100 o null: barra sottile sotto il testo).
    Slot: `badges`, quello predefinito (righe in più), `trailing` (un numero
    con l'etichetta o dei pulsanti; sul telefono va sotto il testo).
--}}
@props([
    'event',
    'when' => null,
    'venue' => null,
    'href' => null,
    'size' => 'md',
    'bar' => null,
])
@php
    use App\Filament\Support\PanelDate;
    use App\Filament\Support\PosterColumn;

    $size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
    [$width, $height] = ['sm' => [36, 48], 'md' => [48, 64], 'lg' => [72, 96]][$size];

    if ($when === null) {
        $when = $event->getAttribute('next_starts_at')
            ?? ($event->relationLoaded('occurrences')
                ? $event->occurrences->where('starts_at', '>=', now())->min('starts_at')
                : $event->occurrences()->where('starts_at', '>=', now())->min('starts_at'));
    }

    $meta = collect([
        $when === null ? __('pannelli.select.no_dates') : PanelDate::event($when, $event->city?->timezone),
        filled($venue) ? $venue : null,
    ])->filter()->implode(' · ');

    $bar = $bar === null ? null : max(0, min(100, (float) $bar));
    $tag = filled($href) ? 'a' : 'div';
@endphp
<{{ $tag }}
    @if (filled($href)) href="{{ $href }}" @endif
    {{ $attributes->class(['incitta-event-row', 'incitta-event-row-'.$size, 'incitta-event-row-link' => filled($href)]) }}
    data-event-row
>
    <img src="{{ PosterColumn::url($event) }}" alt="" width="{{ $width }}" height="{{ $height }}" loading="lazy" decoding="async" class="incitta-event-row-poster">
    <div class="incitta-event-row-body">
        <p class="incitta-event-row-title">{{ $event->title }}</p>
        <p class="incitta-event-row-meta">{{ $meta }}</p>
        @isset($badges)
            <div class="incitta-event-row-badges">{{ $badges }}</div>
        @endisset
        {{ $slot ?? '' }}
        @if ($bar !== null)
            <div class="incitta-event-row-bar" aria-hidden="true"><span style="width: {{ $bar }}%"></span></div>
        @endif
    </div>
    @isset($trailing)
        <div class="incitta-event-row-trailing">{{ $trailing }}</div>
    @endisset
</{{ $tag }}>
