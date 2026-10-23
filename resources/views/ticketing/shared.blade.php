{{-- `/biglietto/{ticket}`: un solo biglietto, per chi l'ha ricevuto dal prenotante. --}}
<x-layouts.app :narrow="true" :meta="$meta">
    @php
        $date = $ticket->booking?->occurrence;
        $timezone = $date?->event?->city?->timezone ?? config('app.timezone');
    @endphp
    <div class="mx-auto flex max-w-md flex-col gap-4">
        <h1 class="text-hero">{{ $meta->heading }}</h1>
        <p class="font-display text-2xl font-extrabold uppercase">{{ $date?->event?->title }}</p>
        <p>{{ $date?->starts_at?->timezone($timezone)->format('d/m/Y H:i') }} · {{ $date?->effectiveVenue()?->name }}</p>
        <p class="text-sm text-ink-muted">{{ $date?->effectiveVenue()?->address }}</p>
        <p class="font-bold">{{ $ticket->attendee_name }}</p>
        @if ($valid)
            <div class="w-fit bg-white p-4" role="img" aria-label="{{ __('ticketing.ticket') }} #{{ $ticket->id }}">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(240)->margin(2)->generate($ticket->code) !!}</div>
            <p class="text-sm text-ink-muted">{{ __('ticketing.share.page_lead') }}</p>
        @else
            <p role="status" class="border-2 border-line bg-canvas p-4">{{ __('ticketing.share.page_invalid', ['status' => __('ticketing.statuses.'.$ticket->displayStatus()->value)]) }}</p>
        @endif
    </div>
</x-layouts.app>
