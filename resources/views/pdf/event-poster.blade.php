{{--
    La locandina A4 di un evento.

    **Scritta per DomPDF, non per un browser.** DomPDF non conosce flexbox,
    grid, variabili CSS né le classi di Tailwind: qui si torna a `table` e a
    stili in linea, e non è pigrizia — è l'unico linguaggio che quel motore
    legge. Provare a riusare i componenti del sito produce un foglio bianco.

    I colori sono scritti a mano per lo stesso motivo: i token del sito vivono
    in un foglio di stile che qui non arriva.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #141413;
        }
        .foglio { padding: 48px 56px; }
        .occhiello {
            font-size: 11px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #6b6b66;
            margin: 0 0 18px;
        }
        h1 {
            font-size: 44px;
            line-height: 1.05;
            margin: 0 0 10px;
            /* Un titolo lungo non deve uscire dal foglio: senza questo, una
               parola di venti lettere sfonda il margine e finisce tagliata
               dalla stampante. */
            word-wrap: break-word;
        }
        .sottotitolo { font-size: 19px; color: #44443f; margin: 0 0 34px; }
        .quando {
            font-size: 27px;
            font-weight: bold;
            border-top: 3px solid #141413;
            border-bottom: 3px solid #141413;
            padding: 14px 0;
            margin: 0 0 22px;
        }
        .dove { font-size: 16px; line-height: 1.5; margin: 0 0 8px; }
        .prezzo { font-size: 16px; font-weight: bold; margin: 14px 0 0; }
        .piede { position: absolute; bottom: 42px; left: 56px; right: 56px; }
        .qr { width: 130px; }
        .indirizzo { font-size: 11px; color: #6b6b66; word-wrap: break-word; }
        .marchio { font-size: 13px; font-weight: bold; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="foglio">
        @if ($event->category !== null)
            <p class="occhiello">{{ $event->category->name }}</p>
        @endif

        <h1>{{ $event->title }}</h1>

        @if (filled($event->subtitle))
            <p class="sottotitolo">{{ $event->subtitle }}</p>
        @endif

        <p class="quando">
            {{ $occurrence->starts_at->setTimezone($event->city->timezone)->translatedFormat('l j F Y') }}
            &nbsp;·&nbsp;
            {{ $occurrence->starts_at->setTimezone($event->city->timezone)->format('H:i') }}
        </p>

        @if ($event->venue !== null)
            <p class="dove">
                <strong>{{ $event->venue->name }}</strong><br>
                {{ $event->venue->address }}, {{ $event->venue->municipality }}
            </p>
        @endif

        @if ($event->is_free)
            <p class="prezzo">{{ __('events.price.free') }}</p>
        @elseif ($event->price_min !== null)
            <p class="prezzo">{{ __('events.price.from', ['amount' => number_format((float) $event->price_min, 2, ',', '.').' €']) }}</p>
        @endif
    </div>

    <div class="piede">
        <table width="100%">
            <tr>
                <td class="qr" valign="bottom"><img src="{{ $qr }}" alt="" width="130" height="130"></td>
                <td valign="bottom" style="padding-left: 16px;">
                    <p class="marchio">{{ config('app.name') }}</p>
                    <p class="indirizzo">{{ $url }}</p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
