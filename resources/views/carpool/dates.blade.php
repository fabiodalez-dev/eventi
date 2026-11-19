{{--
    I passaggi di una data: la serata, «Offro passaggio», la tratta come scelta
    segmentata, gli altri filtri a scomparsa, l'elenco dei passaggi, chi cerca
    un passaggio e l'avviso per quando ne compare uno.
--}}
@php
    $eventUrl = $occurrence ? \App\Support\EventUrl::occurrence($occurrence) : null;
    $filtered = request()->hasAny(['zone', 'accessibility', 'sort']) || (int) request('seats', 1) > 1;
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.web.dates_title')" :subtitle="__('carpool.web.dates_subtitle')" :eyebrow="__('carpool.free')"
        :back="$eventUrl" :back-label="__('carpool.web.back_event')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        @if ($access['eligible'])
            <x-slot:actions>
                <x-button :href="route('carpool.create', $occurrence_id)"><x-lucide name="plus" class="size-4" />{{ __('carpool.offer') }}</x-button>
                <x-button variant="secondary" href="#ride-search">{{ __('carpool.search_create') }}</x-button>
            </x-slot:actions>
        @endif

        @include('carpool.partials.event')

        @if (! $access['eligible'])
            <x-card :title="__('carpool.web.blocked_title')">
                @include('carpool.partials.gate', ['hideHeading' => true])
            </x-card>
            @include('carpool.partials.how')
        @else
            <x-segmented :label="__('carpool.web.leg_filter')" :items="collect([null, ...\App\Enums\RideLeg::cases()])->map(fn ($leg) => [
                'label' => $leg?->label() ?? __('carpool.web.all_legs'),
                'href' => request()->fullUrlWithQuery(['leg' => $leg?->value, 'page' => null]),
                'active' => (string) request('leg', '') === (string) ($leg?->value ?? ''),
            ])->all()" />

            <x-card padding="sm" data-carpool-filters>
                <details class="group" @if ($filtered) open @endif>
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3">
                        <span class="flex flex-col">
                            <span class="font-semibold text-ink">{{ __('carpool.web.more_filters') }}</span>
                            <span class="text-sm text-ink-muted">{{ __('carpool.web.filters_description') }}</span>
                        </span>
                        <x-lucide name="chevron-down" class="size-5 text-ink-muted transition group-open:rotate-180" />
                    </summary>
                    <form method="get" class="mt-4 flex flex-col gap-4">
                        @if (request('leg'))<input type="hidden" name="leg" value="{{ request('leg') }}">@endif
                        @include('carpool.partials.field', ['name' => 'zone', 'id' => 'filtro-zona', 'label' => __('carpool.zone'), 'maxlength' => 120, 'value' => request('zone')])
                        @include('carpool.partials.field', ['name' => 'seats', 'id' => 'filtro-posti', 'label' => __('carpool.seats'), 'type' => 'number', 'min' => 1, 'max' => 8, 'value' => request('seats', 1)])
                        @include('carpool.partials.field', ['name' => 'accessibility', 'id' => 'filtro-accessibilita', 'label' => __('carpool.accessibility'), 'type' => 'select',
                            'options' => collect(\App\Enums\RideAccessibility::cases())->mapWithKeys(fn ($option) => [$option->value => $option->label()])->all(), 'value' => request('accessibility')])
                        @include('carpool.partials.field', ['name' => 'sort', 'id' => 'filtro-ordine', 'label' => __('carpool.sort'), 'type' => 'select',
                            'options' => ['departure' => __('carpool.sort_departure'), 'recent' => __('carpool.sort_recent')], 'value' => request('sort', 'departure')])
                        <x-form-actions>
                            <x-button type="submit">{{ __('carpool.filter') }}</x-button>
                            @if ($filtered)<x-button variant="ghost" :href="route('carpool.dates', array_filter(['occurrence' => $occurrence_id, 'leg' => request('leg')]))">{{ __('carpool.web.reset_filters') }}</x-button>@endif
                        </x-form-actions>
                    </form>
                </details>
            </x-card>

            @if ($offers !== [])
                <x-card padding="none" :title="__('carpool.web.offers_title')" data-carpool-offers>
                    @foreach ($offers as $offer)
                        @include('carpool.partials.offer-row')
                    @endforeach
                </x-card>
                @include('carpool.partials.pages')
            @else
                <x-empty-state :title="__('carpool.web.empty_offers_title')" :description="__('carpool.empty_offers')">
                    <x-button :href="route('carpool.create', $occurrence_id)">{{ __('carpool.offer') }}</x-button>
                    <x-button variant="secondary" href="#ride-search">{{ __('carpool.search_create') }}</x-button>
                </x-empty-state>
            @endif

            @if (count($wanted))
                <x-card padding="none" :title="__('carpool.wanted')" :description="__('carpool.web.wanted_description')" data-carpool-wanted>
                    @php($ownOffers = collect($my_offers)->where('is_own', true))
                    @foreach ($wanted as $search)
                        <div class="svc-row flex flex-col gap-3 px-5 py-4 sm:px-6">
                            <div class="flex min-w-0 items-start gap-3">
                                <x-avatar :name="$search['name']" />
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span class="font-semibold text-ink">{{ $search['name'] }}@if ($search['zone']) · {{ $search['zone'] }}@endif</span>
                                    <span class="text-sm text-ink-muted">{{ $search['leg_label'] }} · {{ trans_choice('carpool.web.seats_requested', $search['seats'], ['count' => $search['seats']]) }}</span>
                                </div>
                            </div>
                            @if ($ownOffers->isNotEmpty())
                                <form method="post" action="{{ route('carpool.discovery', 'suggest') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                    @include('carpool.partials.key')
                                    <input type="hidden" name="search_id" value="{{ $search['id'] }}">
                                    <div class="min-w-0 flex-1">
                                        @include('carpool.partials.field', ['name' => 'offer_id', 'id' => 'proponi-'.$search['id'], 'label' => __('carpool.web.suggest_with'), 'type' => 'select', 'required' => true,
                                            'options' => $ownOffers->mapWithKeys(fn ($own) => [$own['id'] => $own['zone'].' · '.$own['departure_label']])->all(), 'value' => null])
                                    </div>
                                    <x-button type="submit" variant="secondary">{{ __('carpool.suggest') }}</x-button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </x-card>
                @include('carpool.partials.pages', ['page_key' => 'wanted_page', 'page' => $wanted_page, 'has_more' => $wanted_has_more])
            @endif

            @include('carpool.partials.search-form')
            @include('carpool.partials.how')
        @endif
    </x-service-page>
</x-layouts.app>
