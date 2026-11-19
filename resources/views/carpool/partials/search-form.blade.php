{{--
    «Avvisami quando c'è un passaggio»: una ricerca salvata per questa data,
    con la finestra di orari consentita e, se si vuole, un annuncio visibile ai
    conducenti verificati.
--}}
@php($searchWindow = $windows[old('leg', 'outbound')] ?? null)
<x-card id="ride-search" :title="__('carpool.search_create')" :description="__('carpool.web.search_description')" data-carpool-search>
    <form method="post" action="{{ route('carpool.discovery', 'search') }}" class="flex flex-col gap-4" data-ride-windows='@json($windows)'
        data-window-text="{{ __('carpool.web.search_window', ['from' => ':from', 'to' => ':to']) }}">
        @include('carpool.partials.key')
        <input type="hidden" name="occurrence_id" value="{{ $occurrence_id }}">
        @include('carpool.partials.field', ['name' => 'leg', 'id' => 'ricerca-tratta', 'label' => __('carpool.web.leg_filter'), 'type' => 'select',
            'options' => collect(\App\Enums\RideLeg::cases())->mapWithKeys(fn ($leg) => [$leg->value => $leg->label()])->all(), 'value' => old('leg', 'outbound')])
        @include('carpool.partials.field', ['name' => 'zone', 'id' => 'ricerca-zona', 'label' => __('carpool.zone'), 'maxlength' => 120, 'value' => old('zone')])
        @include('carpool.partials.field', ['name' => 'seats', 'id' => 'ricerca-posti', 'label' => __('carpool.seats'), 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 8, 'value' => old('seats', 1)])
        @foreach (['earliest', 'latest'] as $bound)
            @include('carpool.partials.field', ['name' => $bound.'_at', 'id' => 'ricerca-'.$bound, 'label' => __('carpool.'.$bound), 'type' => 'datetime-local', 'required' => true,
                'min' => $searchWindow['min'] ?? null, 'max' => $searchWindow['max'] ?? null, 'value' => old($bound.'_at'),
                'hint' => $bound === 'earliest' && $searchWindow ? __('carpool.web.search_window', ['from' => $searchWindow['from'], 'to' => $searchWindow['to']]) : null])
        @endforeach
        @include('carpool.partials.field', ['name' => 'accessibility', 'id' => 'ricerca-accessibilita', 'label' => __('carpool.accessibility'), 'type' => 'select',
            'options' => collect(\App\Enums\RideAccessibility::cases())->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all(), 'value' => old('accessibility', 'not_specified')])
        <input type="hidden" name="is_public" value="0">
        <label class="flex min-h-12 items-start gap-3 text-sm text-ink"><input type="checkbox" name="is_public" value="1" @checked(old('is_public')) class="mt-0.5 size-5 shrink-0 accent-brand"><span>{{ __('carpool.search_public') }}</span></label>
        <input type="hidden" name="alerts_enabled" value="1">
        <div><x-button type="submit">{{ __('carpool.save') }}</x-button></div>
    </form>
</x-card>
