<!doctype html><html lang="it"><head><meta charset="utf-8"><style>
    @page{margin:18mm 14mm} body{font-family:DejaVu Sans,sans-serif;color:#141413;font-size:11px}
    h1{font-size:18px;margin:0 0 4px} .muted{color:#555;font-size:10px} .totals{margin:8px 0 12px}
    table{width:100%;border-collapse:collapse} th{text-align:left;border-bottom:2px solid #141413;padding:4px 3px;font-size:10px;text-transform:uppercase}
    td{border-bottom:1px solid #bbb;padding:5px 3px;vertical-align:top} .box{width:14px;height:14px;border:1.5px solid #141413;display:inline-block}
    .done{color:#555} .badge{font-size:9px;border:1px solid #141413;padding:0 3px}
</style></head><body>
    <p class="muted">inCittà · {{ __('ticketing.print.title') }}</p>
    <h1>{{ $date->event->title }}</h1>
    <p>{{ $date->starts_at->timezone($timezone)->format('d/m/Y H:i') }} · {{ $date->effectiveVenue()?->name }}</p>
    <p class="totals">{{ __('ticketing.print.admitted', ['count' => $tickets->count()]) }} · {{ __('ticketing.print.checked_in', ['count' => $checkedIn]) }}@if ($waitlisted > 0) · {{ __('ticketing.print.waitlisted', ['count' => $waitlisted]) }}@endif</p>
    @if ($tickets->isEmpty())
        <p>{{ __('ticketing.print.empty') }}</p>
    @else
        <table>
            <thead><tr><th></th><th>{{ __('ticketing.print.name') }}</th><th>{{ __('ticketing.print.ticket') }}</th><th>{{ __('ticketing.print.booking') }}</th><th>{{ __('ticketing.print.notes') }}</th></tr></thead>
            <tbody>
            @foreach ($tickets as $ticket)
                @php
                    $notes = collect($ticket->booking->booking_answers ?? [])->map(fn (array $answer): string => $answer['question'].': '.$answer['answer']);
                    if ($ticket->booking->isStaffBooking() && filled($ticket->booking->booker_data['note'] ?? null)) { $notes->prepend($ticket->booking->booker_data['note']); }
                @endphp
                <tr class="{{ $ticket->status === \App\Enums\AdmissionStatus::CheckedIn ? 'done' : '' }}" data-ticket="{{ $ticket->id }}">
                    <td>@if ($ticket->status === \App\Enums\AdmissionStatus::CheckedIn)✓@else<span class="box"></span>@endif</td>
                    <td><strong>{{ $ticket->last_name ? $ticket->last_name.' '.$ticket->first_name : $ticket->attendee_name }}</strong></td>
                    <td>#{{ $ticket->id }}</td>
                    <td>#{{ $ticket->booking->id }}@if ($ticket->booking->isStaffBooking()) <span class="badge">{{ __('ticketing.guest.badge') }}</span>@endif</td>
                    <td>{{ $notes->implode(' · ') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <p class="muted" style="margin-top:12px">{{ __('ticketing.print.generated', ['date' => now()->timezone($timezone)->format('d/m/Y H:i')]) }}</p>
</body></html>
