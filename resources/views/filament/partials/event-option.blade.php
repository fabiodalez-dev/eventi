{{--
    Una voce del selettore di eventi dei pannelli (`EventSelect`, `EventOptions`,
    `CampaignSelect`): locandina, titolo, e sotto «sab 22 nov, 21:00 · locale».

    Si disegna da PHP (`view(...)->render()`) dentro le tendine di Filament con
    `allowHtml()`: ogni testo passa da `{{ }}`, quindi da `e()`.

    Variabili: `event` (obbligatoria), `when` (istante o testo già scritto),
    `more` (quante altre date in arrivo), `venue` (nome o null), `meta` (testo
    che sostituisce la seconda riga), `badge` (testo, oppure
    `['label' => …, 'color' => …]`).
--}}
@php
    use App\Filament\Support\PanelDate;
    use App\Filament\Support\PosterColumn;

    $when ??= null;
    $more = (int) ($more ?? 0);
    $venue ??= null;
    $meta ??= null;
    $badge ??= null;

    if ($meta === null) {
        $whenText = match (true) {
            $when === null => __('pannelli.select.no_dates'),
            $when instanceof \DateTimeInterface, preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $when) === 1 => PanelDate::event($when, $event->city?->timezone),
            default => $when,
        };
        $meta = collect([$whenText, filled($venue) ? $venue : null])->filter()->implode(' · ');
    }

    $badgeLabel = is_array($badge) ? ($badge['label'] ?? null) : $badge;
    $badgeColor = is_array($badge) ? ($badge['color'] ?? 'gray') : 'gray';
@endphp
<span class="incitta-event-option" data-event-option>
    <img src="{{ PosterColumn::url($event) }}" alt="" width="36" height="48" loading="lazy" decoding="async" class="incitta-event-option-poster">
    <span class="incitta-event-option-text">
        <span class="incitta-event-option-title">{{ $event->title }}</span>
        <span class="incitta-event-option-meta">{{ $meta }}@if ($more > 0)<span class="incitta-event-option-more"> {{ trans_choice('pannelli.select.more_dates', $more, ['count' => $more]) }}</span>@endif</span>
    </span>
    @if (filled($badgeLabel))
        <span class="fi-badge fi-size-sm fi-color fi-color-{{ $badgeColor }} incitta-event-option-badge">{{ $badgeLabel }}</span>
    @endif
</span>
