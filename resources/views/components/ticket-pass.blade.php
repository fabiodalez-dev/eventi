{{--
    Il biglietto di una persona, come si mostra all'ingresso: la locandina e
    il titolo della serata, la data in grande, il nome di chi entra e, nella
    matrice staccata da una perforazione, il QR su fondo bianco.

    È lo stesso disegno nella scheda della prenotazione (`/biglietti/...`) e
    nella pagina del biglietto condiviso (`/biglietto/...`); il PDF ne ha una
    versione per DomPDF in `ticketing/pdf.blade.php`.

    Props: `ticket` (con `booking`, oppure la prenotazione in `booking`); `position` e `total` («Biglietto 2 di 3», solo se più di
    uno); `qr` (false per mostrare tutto tranne il codice, per esempio sotto
    impersonificazione). Slot `stub`: quello che va nella matrice al posto del
    QR quando il codice non c'è.
--}}
@props(['ticket', 'booking' => null, 'position' => null, 'total' => null, 'qr' => true])
@php
    // Chi ha già la prenotazione la passa: niente query per ogni biglietto.
    $booking ??= $ticket->booking;
    $date = $booking?->occurrence;
    $event = $date?->event;
    $timezone = $event?->city?->timezone ?? config('app.timezone');
    $starts = $date?->starts_at?->timezone($timezone);
    $venue = $date?->effectiveVenue();
    $organizer = $event?->organizer?->name ?? $event?->organizer_name;
    $status = $ticket->displayStatus();
    $valid = $status === \App\Enums\AdmissionStatus::Valid;
    $artwork = $event !== null ? \App\Support\Poster::imageSet($event) : null;
    $tone = match ($status) {
        \App\Enums\AdmissionStatus::Valid => 'brand',
        \App\Enums\AdmissionStatus::CheckedIn => 'accent',
        \App\Enums\AdmissionStatus::Waitlisted => 'soon',
        default => 'muted',
    };
@endphp
<article data-ticket-pass {{ $attributes->class(['ticket-pass', 'ticket-pass--inactive' => ! $valid]) }}>
    <div class="ticket-pass__main">
        <header class="ticket-pass__band">
            <span>{{ config('app.name') }} · {{ __('ticketing.ticket') }}@if ($total !== null && $total > 1) {{ __('ticketing.pass.of', ['position' => $position, 'total' => $total]) }}@endif</span>
            <x-badge :tone="$tone" size="sm">{{ __('ticketing.statuses.'.$status->value) }}</x-badge>
        </header>

        <div class="ticket-pass__event">
            <span class="svc-poster" aria-hidden="true">
                @if ($artwork !== null)
                    <x-event-artwork :event="$event" sizes="72px" />
                @else
                    <span class="svc-poster__placeholder"><x-lucide name="ticket" class="size-6" /></span>
                @endif
            </span>
            <div class="flex min-w-0 flex-col gap-1">
                <h3 class="ticket-pass__title">{{ $event?->title ?? __('ticketing.unavailable') }}</h3>
                @if (filled($organizer))
                    <p class="text-sm text-ink-muted">{{ __('ticketing.pass.organizer', ['name' => $organizer]) }}</p>
                @endif
            </div>
        </div>

        <dl class="ticket-pass__facts">
            @if ($starts !== null)
                <div class="ticket-pass__fact ticket-pass__fact--date">
                    <dt>{{ __('ticketing.pass.date') }}</dt>
                    <dd>
                        <span class="ticket-pass__day">{{ $starts->translatedFormat('j') }}</span>
                        <span class="flex flex-col leading-tight"><span class="font-bold uppercase">{{ $starts->translatedFormat('F') }}</span><span class="text-ink-muted">{{ $starts->translatedFormat('l Y') }}</span></span>
                    </dd>
                </div>
                @unless ($date->is_all_day)
                    <div class="ticket-pass__fact">
                        <dt>{{ __('ticketing.pass.time') }}</dt>
                        <dd class="ticket-pass__big">{{ $starts->format('H:i') }}</dd>
                    </div>
                @endunless
            @endif
            @if ($venue !== null)
                <div class="ticket-pass__fact ticket-pass__fact--wide">
                    <dt>{{ __('ticketing.pass.place') }}</dt>
                    <dd><span class="font-bold">{{ $venue->name }}</span>@if (filled($venue->address))<span class="block text-ink-muted">{{ $venue->address }}</span>@endif</dd>
                </div>
            @endif
            <div class="ticket-pass__fact ticket-pass__fact--wide">
                <dt>{{ __('ticketing.pass.holder') }}</dt>
                <dd class="ticket-pass__holder">{{ $ticket->attendee_name }}</dd>
            </div>
        </dl>
    </div>

    <div class="ticket-pass__stub">
        @if ($valid && $qr)
            <div class="ticket-pass__qr" role="img" aria-label="{{ __('ticketing.tickets_page.qr_label') }} · {{ __('ticketing.ticket') }} #{{ $ticket->id }}">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(200)->margin(1)->errorCorrection('M')->generate($ticket->code) !!}</div>
        @else
            {{ $stub ?? '' }}
        @endif
        <p class="ticket-pass__number"><span>{{ __('ticketing.pass.number') }}</span> {{ str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT) }}</p>
        @if ($booking !== null)
            <p class="text-xs text-ink-muted">{{ __('ticketing.booking_number', ['id' => $booking->id]) }}</p>
        @endif
    </div>
</article>
