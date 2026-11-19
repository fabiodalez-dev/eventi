{{--
    I campi di un'offerta, divisi in tre gruppi: partenza (`departure`), posti
    e accessibilità (`seats`), dettagli facoltativi (`details`). Il modulo
    «Offro passaggio» mette ogni gruppo in una card numerata; la modifica di
    un passaggio esistente li stampa tutti insieme (`section` vuoto).

    Parametri: `section`, `values` (l'offerta esistente o null), `windows`
    (le finestre di partenza per tratta), `fixedLeg` (la tratta fissa della
    modifica; senza, lo script segue la scelta del modulo), `prefix` (id).
--}}
@php
    $section ??= null;
    $values ??= null;
    $prefix ??= 'offerta';
    $legWindow = $fixedLeg ?? null;
    // Con la tratta fissa vale la sua finestra; nel modulo nuovo, senza script, l'unione delle
    // due (dall'inizio dell'andata alla fine del ritorno): lo script la restringe alla tratta scelta.
    $current = $legWindow !== null ? ($windows[$legWindow] ?? null)
        : ($windows !== [] ? ['min' => $windows['outbound']['min'], 'max' => $windows['return']['max']] : null);
    $windowHint = $legWindow !== null || $windows === []
        ? ($current ? __('carpool.web.departure_window_leg', ['from' => $current['from'], 'to' => $current['to']]) : null)
        : __('carpool.web.departure_window', ['outbound_from' => $windows['outbound']['from'], 'outbound_to' => $windows['outbound']['to'], 'return_from' => $windows['return']['from'], 'return_to' => $windows['return']['to']]);
    $show = fn (string $group): bool => $section === null || $section === $group;
@endphp
@if ($show('departure'))
    @include('carpool.partials.field', ['name' => 'zone', 'id' => $prefix.'-zona', 'label' => __('carpool.zone'), 'hint' => __('carpool.web.zone_help'), 'required' => true, 'maxlength' => 120, 'value' => old('zone', $values['zone'] ?? '')])
    @include('carpool.partials.field', ['name' => 'departure_at', 'id' => $prefix.'-partenza', 'label' => __('carpool.departure'), 'type' => 'datetime-local', 'required' => true,
        'hint' => $windowHint,
        'min' => $current['min'] ?? null, 'max' => $current['max'] ?? null, 'value' => old('departure_at', $values['departure_local'] ?? '')])
@endif
@if ($show('seats'))
    @include('carpool.partials.field', ['name' => 'capacity', 'id' => $prefix.'-posti', 'label' => __('carpool.seats'), 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 8, 'hint' => __('carpool.seat_help'), 'value' => old('capacity', $values['capacity'] ?? 1)])
    @include('carpool.partials.field', ['name' => 'accessibility', 'id' => $prefix.'-accessibilita', 'label' => __('carpool.accessibility'), 'type' => 'select', 'hint' => __('carpool.accessibility_disclaimer'),
        'options' => collect(\App\Enums\RideAccessibility::cases())->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all(), 'value' => old('accessibility', $values['accessibility'] ?? 'not_specified')])
    @include('carpool.partials.field', ['name' => 'accessibility_note', 'id' => $prefix.'-accessibilita-nota', 'label' => __('carpool.accessibility_note'), 'maxlength' => 300, 'value' => old('accessibility_note', $values['accessibility_note'] ?? '')])
@endif
@if ($show('details'))
    <fieldset class="flex min-w-0 flex-col gap-3">
        <legend class="mb-1 text-sm font-semibold text-ink">{{ __('carpool.stops') }}</legend>
        <p class="text-xs text-ink-subtle">{{ __('carpool.web.stops_help') }}</p>
        @for ($i = 0; $i < 3; $i++)
            @include('carpool.partials.field', ['name' => 'stops[]', 'error' => 'stops.'.$i, 'id' => $prefix.'-tappa-'.($i + 1), 'label' => __('carpool.stop').' '.($i + 1), 'maxlength' => 120, 'value' => old('stops.'.$i, $values['stops'][$i] ?? '')])
        @endfor
    </fieldset>
    @include('carpool.partials.field', ['name' => 'note', 'id' => $prefix.'-nota', 'label' => __('carpool.note'), 'type' => 'textarea', 'maxlength' => 500, 'value' => old('note', $values['note'] ?? '')])
@endif
