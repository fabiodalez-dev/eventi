{{--
    Il modello di tutte le email di notifica (§15.6).

    È scritto con tabelle e stili in linea, e non è una scelta nostalgica: i
    client di posta non hanno un motore di rendering moderno. Outlook su
    Windows usa quello di Word, Gmail rimuove i fogli di stile collegati e
    riscrive quelli incorporati, e la metà di chi legge lo fa su un telefono
    con una larghezza che nessun `flex` governerebbe. Una tabella larga 600
    pixel con gli stili sull'attributo `style` è l'unico impianto che arriva
    uguale ovunque.

    In fondo ci sono le due cose che §15.9 rende obbligatorie: la disiscrizione
    a un click e la pagina delle preferenze, raggiungibile **senza accesso**
    con un indirizzo firmato. Le tipologie che non si possono spegnere (§15.4)
    non mostrano il collegamento di disiscrizione: mostrarlo senza che
    disiscriva sarebbe peggio che non averlo.
--}}
@php
    /** @var \App\DTOs\NotificationMessage $notification */
    $product = config('app.name');
    $ink = '#16161a';
    $muted = '#5c5c66';
    $brand = '#4338ca';
    $line = '#e4e4e9';
    $surface = '#f6f6f8';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $notification->subject }}</title>
</head>
<body style="margin:0; padding:0; background-color:{{ $surface }}; color:{{ $ink }}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    {{-- Anteprima: è la riga che i client mostrano accanto all'oggetto. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $notification->lines[0] ?? $notification->heading }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $surface }};">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border:1px solid {{ $line }}; border-radius:12px;">
                    <tr>
                        <td style="padding:20px 24px; border-bottom:1px solid {{ $line }}; font-size:14px; font-weight:700; color:{{ $ink }};">
                            {{ $product }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px;">
                            <h1 style="margin:0 0 12px 0; font-size:20px; line-height:1.3; color:{{ $ink }};">{{ $notification->heading }}</h1>

                            @foreach ($notification->lines as $line)
                                <p style="margin:0 0 10px 0; font-size:15px; line-height:1.55; color:{{ $muted }};">{{ $line }}</p>
                            @endforeach

                            @if ($notification->items !== [])
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 4px 0;">
                                    @foreach ($notification->items as $item)
                                        <tr>
                                            <td style="padding:12px 0; border-top:1px solid {{ $line }};">
                                                <a href="{{ $item['url'] }}" style="font-size:15px; font-weight:700; color:{{ $ink }}; text-decoration:none;">{{ $item['title'] }}</a>
                                                <div style="margin-top:4px; font-size:13px; color:{{ $muted }};">{{ $item['meta'] }}</div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 0 0;">
                                <tr>
                                    <td style="background-color:{{ $brand }}; border-radius:999px;">
                                        <a href="{{ \App\Support\SafeUrl::href($notification->url) ?? url('/') }}" style="display:inline-block; padding:12px 22px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none;">{{ $notification->actionLabel }}</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:16px 0 0 0; font-size:12px; line-height:1.5; color:{{ $muted }};">
                                {{ __('notifications.mail.link_fallback') }}<br>
                                <a href="{{ \App\Support\SafeUrl::href($notification->url) ?? url('/') }}" style="color:{{ $brand }}; word-break:break-all;">{{ \App\Support\SafeUrl::href($notification->url) ?? url('/') }}</a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 24px 24px 24px; border-top:1px solid {{ $line }}; font-size:12px; line-height:1.6; color:{{ $muted }};">
                            <p style="margin:0 0 6px 0;">{{ __('notifications.mail.reason.'.$notification->type->value, ['product' => $product]) }}</p>

                            @if ($unsubscribeUrl !== null)
                                <a href="{{ $unsubscribeUrl }}" style="color:{{ $brand }};">{{ __('notifications.mail.unsubscribe') }}</a>
                                <span aria-hidden="true">{{ __('common.separator') }}</span>
                            @endif

                            @if ($preferencesUrl !== null)
                                <a href="{{ $preferencesUrl }}" style="color:{{ $brand }};">{{ __('notifications.mail.preferences') }}</a>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
