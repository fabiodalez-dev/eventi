<!doctype html><html lang="it"><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#141413} h1{font-size:26px} .ticket{border:2px solid #141413;padding:24px} .qr{margin:24px 0} .muted{font-size:12px}</style></head><body>
    <div class="ticket"><p>inCittà · {{ __('ticketing.free') }}</p><h1>{{ $booking['title'] }}</h1>
        <p>{{ $ticket->booking->occurrence?->starts_at?->timezone($ticket->booking->user?->timezone ?? 'Europe/Rome')->format('d/m/Y H:i') }}</p>
        <p>{{ $booking['venue'] }}<br>{{ $booking['address'] }}</p><h2>{{ $ticket->attendee_name }}</h2>
        <p>{{ __('ticketing.ticket') }} #{{ $ticket->id }} · {{ __('ticketing.statuses.'.$ticket->displayStatus()->value) }}</p>
        @if ($ticket->displayStatus() === \App\Enums\AdmissionStatus::Valid)
            <div class="qr"><img width="220" height="220" src="data:image/svg+xml;base64,{{ base64_encode((string) \SimpleSoftwareIO\QrCode\Facades\QrCode::size(220)->margin(2)->generate($ticket->code)) }}"></div>
        @endif
        <p class="muted">{{ __('ticketing.qr_hint') }}</p><p>{{ $booking['instructions'] }}</p>
        <p class="muted">{{ __('ticketing.mail.current_status') }}</p><p class="muted">{{ __('ticketing.admission_notice') }}</p>
    </div>
</body></html>
