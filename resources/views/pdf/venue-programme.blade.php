{{--
    Il programma della settimana di un locale, A4 per la bacheca.

    Scritto per DomPDF come la locandina: tabelle e stili in linea, colori a
    mano. Una riga per data; i giorni senza date non compaiono (§8.6 vale
    anche sulla carta: niente «nessun evento»).
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #141413; }
        .foglio { padding: 44px 52px 150px; }
        .occhiello { font-size: 11px; letter-spacing: 3px; text-transform: uppercase; color: #6b6b66; margin: 0 0 12px; }
        h1 { font-size: 36px; line-height: 1.05; margin: 0 0 6px; word-wrap: break-word; }
        .settimana { font-size: 18px; font-weight: bold; border-top: 3px solid #141413; border-bottom: 3px solid #141413; padding: 10px 0; margin: 16px 0 18px; }
        .giorno { font-size: 13px; letter-spacing: 2px; text-transform: uppercase; color: #6b6b66; margin: 16px 0 4px; }
        table.date { width: 100%; border-collapse: collapse; }
        table.date td { padding: 6px 0; border-bottom: 1px solid #d9d9d4; vertical-align: top; }
        .ora { width: 64px; font-weight: bold; font-size: 15px; }
        .titolo { font-size: 15px; font-weight: bold; }
        .meta { font-size: 11px; color: #6b6b66; }
        .vuoto { font-size: 14px; color: #44443f; margin: 24px 0; }
        .piede { position: absolute; bottom: 40px; left: 52px; right: 52px; }
        .qr { width: 110px; }
        .indirizzo { font-size: 11px; color: #6b6b66; word-wrap: break-word; }
        .marchio { font-size: 13px; font-weight: bold; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="foglio">
        <p class="occhiello">{{ __('venues.programme.eyebrow') }}</p>
        <h1>{{ $venue->name }}</h1>
        <p class="meta">{{ $venue->address }}, {{ $venue->municipality }}</p>
        <p class="settimana">{{ __('venues.programme.week', ['from' => $monday->translatedFormat('j F'), 'to' => $sunday->translatedFormat('j F Y')]) }}</p>

        @forelse ($days as $day => $items)
            <p class="giorno">{{ \Carbon\CarbonImmutable::parse($day, $city->timezone)->translatedFormat('l j F') }}</p>
            <table class="date">
                @foreach ($items as $occurrence)
                    <tr>
                        <td class="ora">{{ $occurrence->is_all_day ? __('venues.programme.all_day') : $occurrence->starts_at->setTimezone($city->timezone)->format('H:i') }}</td>
                        <td>
                            <div class="titolo">{{ $occurrence->event->title }}</div>
                            <div class="meta">
                                @if ($occurrence->event->category !== null){{ $occurrence->event->category->name }}@endif
                                @if ($occurrence->event->is_free) · {{ __('events.price.free') }}@elseif ($occurrence->event->price_min !== null) · {{ __('events.price.from', ['amount' => number_format((float) $occurrence->event->price_min, 2, ',', '.').' €']) }}@endif
                                @if (filled($occurrence->event->subtitle)) · {{ $occurrence->event->subtitle }}@endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table>
        @empty
            <p class="vuoto">{{ __('venues.programme.empty') }}</p>
        @endforelse
    </div>

    <div class="piede">
        <table width="100%">
            <tr>
                <td class="qr" valign="bottom"><img src="{{ $qr }}" alt="" width="110" height="110"></td>
                <td valign="bottom" style="padding-left: 16px;">
                    <p class="marchio">{{ config('app.name') }}</p>
                    <p class="indirizzo">{{ __('venues.programme.footer') }}<br>{{ $url }}</p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
