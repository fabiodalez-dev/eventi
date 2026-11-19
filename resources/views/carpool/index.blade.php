{{--
    I miei passaggi: offerte, richieste, ricerche e storico, con una scelta
    segmentata. Senza una scheda scelta si apre la prima che ha qualcosa
    (il controller la passa in `selected_tab`).
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.mine')" :subtitle="__('carpool.web.mine_subtitle')" :eyebrow="__('carpool.free')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        @if (! $access['eligible'])
            <x-notice tone="alert" :title="__('carpool.profile_blocked.title')">
                <p>{{ __('carpool.onboarding') }}</p>
                <div><x-button size="sm" :href="route('carpool.requirements')">{{ __('carpool.profile_blocked.action') }}</x-button></div>
            </x-notice>
        @endif

        <x-segmented :label="__('carpool.web.mine_filter')" :items="collect(['offered', 'requested', 'searches', 'history'])->map(fn ($tab) => [
            'label' => __('carpool.'.$tab), 'href' => route('carpool.index', ['tab' => $tab]), 'active' => $selected_tab === $tab, 'count' => ($counts[$tab] ?? 0) ?: null,
        ])->all()" />

        @php
            $showOffers = in_array($selected_tab, ['offered', 'history'], true);
            $showRequests = in_array($selected_tab, ['requested', 'history'], true);
            $nothing = ($selected_tab === 'offered' && $offers === []) || ($selected_tab === 'requested' && $requests === [])
                || ($selected_tab === 'searches' && $searches === []) || ($selected_tab === 'history' && $offers === [] && $requests === []);
        @endphp

        @if ($nothing)
            <x-empty-state :title="__('carpool.web.empty.'.$selected_tab.'.title')" :description="__('carpool.web.empty.'.$selected_tab.'.description')">
                <x-button :href="route('events.index')">{{ __('carpool.web.browse_events') }}</x-button>
            </x-empty-state>
        @endif

        @if ($showOffers && $offers !== [])
            <div class="flex flex-col gap-3" data-carpool-list="offers">
                @foreach ($offers as $offer)
                    @include('carpool.partials.offer-item')
                @endforeach
                @include('carpool.partials.pages')
            </div>
        @endif

        @if ($showRequests && $requests !== [])
            <div class="flex flex-col gap-3" data-carpool-list="requests">
                @foreach ($requests as $item)
                    @include('carpool.partials.request-item')
                @endforeach
                @include('carpool.partials.pages', ['page_key' => 'requests_page', 'page' => $requests_page, 'has_more' => $requests_has_more])
            </div>
        @endif

        @if ($selected_tab === 'searches' && $searches !== [])
            <x-card padding="none" :title="__('carpool.searches')" data-carpool-list="searches">
                @foreach ($searches as $search)
                    <x-card.row :title="$search['event_title']" :description="$search['leg_label'].' · '.collect([$search['zone'], trans_choice('carpool.web.seats_requested', $search['seats'], ['count' => $search['seats']])])->filter()->implode(' · ')">
                        <x-slot:leading><x-lucide name="search" class="size-5 text-ink-muted" /></x-slot:leading>
                        <span>{{ $search['active'] ? __('carpool.web.search_active') : __('carpool.web.search_paused') }}</span>
                        <x-slot:trailing>
                            <form method="post" action="{{ route('carpool.discovery', 'toggle-search') }}">
                                @include('carpool.partials.key')
                                <input type="hidden" name="search_id" value="{{ $search['id'] }}">
                                <input type="hidden" name="active" value="{{ $search['active'] ? 0 : 1 }}">
                                <x-button type="submit" size="sm" variant="secondary">{{ __('carpool.'.($search['active'] ? 'search_stop' : 'search_start')) }}</x-button>
                            </form>
                        </x-slot:trailing>
                    </x-card.row>
                @endforeach
            </x-card>
            @include('carpool.partials.pages', ['page_key' => 'searches_page', 'page' => $searches_page, 'has_more' => $searches_has_more])
        @endif

        @if (count($templates))
            <x-card padding="none" :title="__('carpool.templates')" :description="__('carpool.web.templates_description')" data-carpool-templates>
                @foreach ($templates as $template)
                    <x-card.row :title="$template['name']" icon="bookmark">
                        <x-slot:trailing>
                            <form method="post" action="{{ route('carpool.discovery', 'delete-template') }}">
                                @include('carpool.partials.key')
                                <input type="hidden" name="template_id" value="{{ $template['id'] }}">
                                <x-button type="submit" size="sm" variant="ghost">{{ __('carpool.delete') }}</x-button>
                            </form>
                        </x-slot:trailing>
                    </x-card.row>
                @endforeach
            </x-card>
        @endif

        @include('carpool.partials.how')
    </x-service-page>
</x-layouts.app>
