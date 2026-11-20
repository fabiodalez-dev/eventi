{{--
    Il biglietto in PDF, lo stesso disegno di `x-ticket-pass` riscritto per
    DomPDF: niente flex, grid né variabili CSS, solo tabelle e colori scritti
    a mano. La locandina arriva già in JPEG come `data:` (`$poster`), perché
    qui le immagini esterne sono spente.

    DejaVu Sans non ha le emoji: nelle indicazioni scritte da chi organizza
    («👉 Porte aperte alle 20.45») diventerebbero quadratini, quindi si tolgono.
--}}
@php
    $date = $ticket->booking->occurrence;
    $event = $date?->event;
    $timezone = $event?->city?->timezone ?? 'Europe/Rome';
    $starts = $date?->starts_at?->timezone($timezone);
    $organizer = $event?->organizer?->name ?? $event?->organizer_name;
    $status = $ticket->displayStatus();
    $valid = $status === \App\Enums\AdmissionStatus::Valid;
    $total = $ticket->booking->tickets->count();
    $position = $ticket->booking->tickets->search(fn ($other) => $other->is($ticket));
    $noEmoji = fn (?string $text): string => trim((string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', (string) $text));
    $instructions = $noEmoji($booking['instructions'] ?? null);
@endphp
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #141413; font-size: 11px; }
        table { border-collapse: collapse; }
        .ticket { width: 100%; border: 2px solid #141413; }
        .band { background: #141413; color: #f5f5f0; font-size: 9px; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; padding: 7px 12px; }
        .band-status { text-align: right; }
        .main { vertical-align: top; padding: 0; }
        .stub { width: 190px; vertical-align: middle; text-align: center; border-left: 2px dashed #141413; background: #f2f2ec; padding: 16px 12px; }
        .event td { vertical-align: top; padding: 14px 12px; }
        .poster { width: 84px; }
        .poster img { width: 84px; }
        .title { font-size: 22px; font-weight: bold; line-height: 1.1; text-transform: uppercase; margin: 0 0 6px; word-wrap: break-word; }
        .muted { color: #5c5c57; }
        .facts { width: 100%; border-top: 2px solid #141413; }
        .facts td { padding: 9px 12px; vertical-align: top; border-bottom: 1px solid #c9c9c2; }
        .facts tr.last td { border-bottom: 0; }
        .label { font-size: 8px; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; color: #5c5c57; margin: 0 0 3px; }
        .day { font-size: 34px; font-weight: bold; line-height: 1; }
        .big { font-size: 24px; font-weight: bold; line-height: 1; }
        .holder { font-size: 17px; font-weight: bold; }
        .qr { background: #fff; padding: 8px; display: inline-block; }
        .number { font-size: 15px; font-weight: bold; letter-spacing: 1.5px; margin: 10px 0 2px; }
        .notes { margin-top: 18px; }
        .notes h2 { font-size: 10px; letter-spacing: 1.5px; text-transform: uppercase; margin: 14px 0 4px; }
        .notes p { margin: 0 0 6px; line-height: 1.45; }
        .small { font-size: 9px; }
    </style>
</head>
<body>
    <table class="ticket">
        <tr>
            <td class="main">
                <table style="width:100%">
                    <tr>
                        <td class="band">{{ config('app.name') }} · {{ __('ticketing.ticket') }}@if ($total > 1 && $position !== false) {{ __('ticketing.pass.of', ['position' => $position + 1, 'total' => $total]) }}@endif</td>
                        <td class="band band-status">{{ __('ticketing.statuses.'.$status->value) }}</td>
                    </tr>
                </table>
                <table class="event" style="width:100%">
                    <tr>
                        @if ($poster)
                            <td class="poster"><img src="{{ $poster }}" alt=""></td>
                        @endif
                        <td>
                            <p class="title">{{ $booking['title'] }}</p>
                            @if (filled($organizer))<p class="muted" style="margin:0">{{ __('ticketing.pass.organizer', ['name' => $organizer]) }}</p>@endif
                            <p class="muted small" style="margin:4px 0 0">{{ __('ticketing.free') }}</p>
                        </td>
                    </tr>
                </table>
                <table class="facts">
                    @if ($starts !== null)
                        <tr>
                            <td style="width:58%">
                                <p class="label">{{ __('ticketing.pass.date') }}</p>
                                <span class="day">{{ $starts->translatedFormat('j') }}</span>
                                <strong style="text-transform:uppercase">{{ $starts->translatedFormat('F') }}</strong> <span class="muted">{{ $starts->translatedFormat('l Y') }}</span>
                            </td>
                            <td style="border-left:1px solid #c9c9c2">
                                @unless ($date->is_all_day)
                                    <p class="label">{{ __('ticketing.pass.time') }}</p>
                                    <span class="big">{{ $starts->format('H:i') }}</span>
                                @endunless
                            </td>
                        </tr>
                    @endif
                    @if (filled($booking['venue']))
                        <tr>
                            <td colspan="2">
                                <p class="label">{{ __('ticketing.pass.place') }}</p>
                                <strong>{{ $booking['venue'] }}</strong>@if (filled($booking['address']))<br><span class="muted">{{ $booking['address'] }}</span>@endif
                            </td>
                        </tr>
                    @endif
                    <tr class="last">
                        <td colspan="2">
                            <p class="label">{{ __('ticketing.pass.holder') }}</p>
                            <span class="holder">{{ $ticket->attendee_name }}</span>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="stub">
                @if ($valid)
                    <div class="qr"><img width="150" height="150" src="data:image/svg+xml;base64,{{ base64_encode((string) \SimpleSoftwareIO\QrCode\Facades\QrCode::size(150)->margin(1)->errorCorrection('M')->generate($ticket->code)) }}" alt=""></div>
                @endif
                <p class="label" style="margin-top:10px">{{ __('ticketing.pass.number') }}</p>
                <p class="number" style="margin-top:0">{{ str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT) }}</p>
                <p class="muted small" style="margin:0">{{ __('ticketing.booking_number', ['id' => $ticket->booking->id]) }}</p>
            </td>
        </tr>
    </table>

    <div class="notes">
        <p class="small muted">{{ __('ticketing.qr_hint') }}</p>
        @if ($instructions !== '')
            <h2>{{ __('ticketing.pass.instructions') }}</h2>
            <p>{!! nl2br(e($instructions)) !!}</p>
        @endif
        <p class="small muted" style="margin-top:14px">{{ __('ticketing.mail.current_status') }}</p>
        <p class="small muted">{{ __('ticketing.admission_notice') }}</p>
    </div>
</body>
</html>
