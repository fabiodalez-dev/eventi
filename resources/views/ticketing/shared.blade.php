{{-- `/biglietto/{ticket}`: un solo biglietto, per chi l'ha ricevuto dal prenotante. --}}
@php
    $instructions = \App\Support\Description::plain($ticket->booking?->occurrence?->booking_instructions);
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading">
        <x-ticket-pass :ticket="$ticket" :qr="$valid">
            @unless ($valid)
                <x-slot:stub><x-lucide name="ticket" class="size-10 text-ink-muted" /></x-slot:stub>
            @endunless
        </x-ticket-pass>
        @if ($valid)
            <p class="text-sm text-ink-muted">{{ __('ticketing.share.page_lead') }}</p>
        @else
            <x-notice tone="alert">{{ __('ticketing.share.page_invalid', ['status' => __('ticketing.statuses.'.$ticket->displayStatus()->value)]) }}</x-notice>
        @endif
        @if (filled($instructions))
            <x-card :title="__('ticketing.pass.instructions')">
                <p class="text-sm whitespace-pre-line text-ink-muted">{{ $instructions }}</p>
            </x-card>
        @endif
    </x-service-page>
</x-layouts.app>
