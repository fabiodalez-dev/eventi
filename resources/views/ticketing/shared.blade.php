{{-- `/biglietto/{ticket}`: un solo biglietto, per chi l'ha ricevuto dal prenotante. --}}
@php
    $date = $ticket->booking?->occurrence;
    $timezone = $date?->event?->city?->timezone ?? config('app.timezone');
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading">
        @if ($date !== null)
            <x-card>
                <x-service-event-row :occurrence="$date" :meta="collect([$date->starts_at?->timezone($timezone)->format('d/m/Y H:i'), $date->effectiveVenue()?->name])->filter()->implode(' · ')">
                    @if (filled($date->effectiveVenue()?->address))
                        <span>{{ $date->effectiveVenue()->address }}</span>
                    @endif
                </x-service-event-row>
            </x-card>
        @endif
        <x-card>
            <div class="flex flex-col gap-1">
                <p class="text-sm text-ink-muted">{{ __('ticketing.shared_page.attendee') }}</p>
                <p class="font-display text-xl font-extrabold text-ink">{{ $ticket->attendee_name }}</p>
            </div>
            @if ($valid)
                <div class="w-fit bg-white p-4" role="img" aria-label="{{ __('ticketing.ticket') }} #{{ $ticket->id }}">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(240)->margin(2)->generate($ticket->code) !!}</div>
                <p class="text-sm text-ink-muted">{{ __('ticketing.share.page_lead') }}</p>
            @else
                <x-notice tone="alert">{{ __('ticketing.share.page_invalid', ['status' => __('ticketing.statuses.'.$ticket->displayStatus()->value)]) }}</x-notice>
            @endif
        </x-card>
    </x-service-page>
</x-layouts.app>
