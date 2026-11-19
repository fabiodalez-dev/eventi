{{--
    «Le mie serate»: le date passate in cui un biglietto della persona è
    entrato. Una riga per serata con la locandina, la data, il locale e
    l'organizzatore da ritrovare, e quanti ingressi.
--}}
@php($timezone = auth()->user()->timezone ?: config('app.timezone'))
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.attended.lead')" :back="route('account.profile')">
        @if ($dates->isEmpty())
            <x-empty-state :title="__('account.attended.empty_title')" :description="__('account.attended.empty')">
                <x-button :href="route('tickets.index')" variant="secondary">{{ __('account.attended.tickets') }}</x-button>
            </x-empty-state>
        @else
            <x-card :title="trans_choice('account.attended.count', $dates->total(), ['count' => $dates->total()])" padding="none" data-attended-list>
                @foreach ($dates as $date)
                    @php($row = \App\Http\Controllers\Web\Account\AttendedController::toArray($date))
                    <div class="svc-row px-5 py-4 sm:px-6">
                        <x-service-event-row
                            :occurrence="$date"
                            :event="$date->event"
                            :href="$row['url'] ?? false"
                            :title="$row['title']"
                            :meta="$date->starts_at->timezone($timezone)->translatedFormat('l j F Y')"
                        >
                            <span>{{ trans_choice('account.attended.entries', $row['checked_in'], ['count' => $row['checked_in']]) }}</span>
                            @if ($row['venue'] || $row['organizer'])
                                <span class="flex flex-wrap gap-x-4">
                                    @if ($row['venue'])
                                        <a href="{{ route('venues.show', $row['venue']['slug']) }}" class="inline-flex min-h-10 items-center gap-1.5 font-semibold text-brand no-underline hover:underline"><x-lucide name="map-pin" class="size-4" />{{ $row['venue']['name'] }}</a>
                                    @endif
                                    @if ($row['organizer'])
                                        <a href="{{ route('organizers.show', $row['organizer']['slug']) }}" class="inline-flex min-h-10 items-center gap-1.5 font-semibold text-brand no-underline hover:underline"><x-lucide name="users" class="size-4" />{{ $row['organizer']['name'] }}</a>
                                    @endif
                                </span>
                            @endif
                        </x-service-event-row>
                    </div>
                @endforeach
            </x-card>
            <x-pagination :paginator="$dates" />
        @endif
    </x-service-page>
</x-layouts.app>
