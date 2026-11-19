{{--
    Il riepilogo di chi gestisce la biglietteria (`/gestione-biglietti`): una
    card per data, con la locandina, lo stato delle prenotazioni, i posti, il
    ruolo e i pulsanti per gestire o aprire lo scanner. I permessi arrivano
    già calcolati dal controller, una volta per data.
--}}
@php
    $periodUrl = fn (string $value): string => route('ticketing.manage.index', array_filter(['period' => $value === 'upcoming' ? null : $value, 'q' => $search !== '' ? $search : null]));
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('ticketing.manage')" :subtitle="__('ticketing.dashboard_page.subtitle')" :back="route('account.profile')">
        <x-card as="div">
            <form method="GET" data-ticket-search class="flex flex-col gap-3" role="search">
                <input type="hidden" name="period" value="{{ $period }}">
                <x-field name="q" :label="__('ticketing.dashboard_page.search')" :value="$search" autocomplete="off" />
                <div><x-button type="submit" variant="secondary"><x-lucide name="search" class="size-4" />{{ __('ticketing.dashboard_page.submit') }}</x-button></div>
            </form>
            <p data-search-status role="status" class="text-sm text-ink-muted empty:hidden" data-loading="{{ __('ticketing.search_status.loading') }}" data-done="{{ __('ticketing.search_status.done') }}" data-failed="{{ __('ticketing.search_status.failed') }}"></p>
        </x-card>

        <div data-ticket-results class="flex flex-col gap-4">
            <x-segmented :label="__('ticketing.dashboard_page.period_label')" :items="[
                ['label' => __('ticketing.dashboard_page.upcoming'), 'href' => $periodUrl('upcoming'), 'active' => $period === 'upcoming'],
                ['label' => __('ticketing.dashboard_page.past'), 'href' => $periodUrl('past'), 'active' => $period === 'past'],
            ]" />
            @forelse ($dates as $date)
                @php
                    $can = $abilities[$date->id] ?? ['manage' => false, 'check_in' => false];
                    $count = $counts[$date->id] ?? null;
                    $target = $can['manage'] ? route('ticketing.manage.show', $date) : route('ticketing.manage.scanner', $date);
                @endphp
                <x-card as="article" data-ticket-date="{{ $date->id }}">
                    <x-service-event-row :occurrence="$date" :href="$target">
                        <x-slot:badge>
                            <x-badge :tone="$date->booking_enabled ? 'brand' : 'muted'" size="sm">{{ $date->booking_enabled ? __('ticketing.dashboard_page.booking_on') : __('ticketing.dashboard_page.booking_off') }}</x-badge>
                        </x-slot:badge>
                        @if ($count !== null)
                            <span>
                                {{ $count['capacity'] === null ? __('ticketing.dashboard_page.seats_unlimited', ['used' => $count['confirmed']]) : __('ticketing.dashboard_page.seats', ['used' => $count['confirmed'], 'capacity' => $count['capacity']]) }}@if ($count['waitlisted'] > 0) · {{ __('ticketing.dashboard_page.waitlisted', ['count' => $count['waitlisted']]) }}@endif
                            </span>
                        @endif
                        <span>{{ $can['manage'] ? __('ticketing.dashboard_page.role_manager') : __('ticketing.dashboard_page.role_staff') }}</span>
                    </x-service-event-row>
                    <x-slot:footer>
                        @if ($can['manage'])
                            <x-button size="sm" :href="route('ticketing.manage.show', $date)">{{ __('ticketing.dashboard_page.manage') }}</x-button>
                        @endif
                        @if ($can['check_in'])
                            <x-button size="sm" :variant="$can['manage'] ? 'secondary' : 'primary'" :href="route('ticketing.manage.scanner', $date)"><x-lucide name="scan-line" class="size-4" />{{ __('ticketing.dashboard_page.scanner') }}</x-button>
                        @endif
                    </x-slot:footer>
                </x-card>
            @empty
                <x-empty-state :title="$search !== '' ? __('ticketing.dashboard_page.empty_search') : __('ticketing.dashboard_page.empty_'.$period)" />
            @endforelse
            {{ $dates->links() }}
        </div>
    </x-service-page>
</x-layouts.app>
