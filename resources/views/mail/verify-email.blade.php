<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ __('account.mail.verify.subject', ['product' => $product]) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f7f7f6;color:#262624;font-family:Manrope,-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ __('account.mail.verify.intro') }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f7f7f6;">
        <tr><td align="center" style="padding:32px 16px;">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;">
                <tr><td style="padding:0 0 24px;font-size:28px;font-weight:800;letter-spacing:-1px;color:#262624;">{{ $product }}<span style="color:#b54d23;">.</span></td></tr>
                <tr><td style="padding:32px 24px;background-color:#fdfdfc;border:1px solid #e2e2df;">
                    <p style="margin:0 0 20px;color:#b54d23;font-size:12px;line-height:1.5;font-weight:700;letter-spacing:1px;text-transform:uppercase;">{{ __('account.verify.step') }}</p>
                    <h1 style="margin:0 0 20px;color:#262624;font-family:'Bricolage Grotesque',Manrope,Arial,sans-serif;font-size:32px;line-height:1.15;font-weight:800;letter-spacing:-0.7px;">{{ __('account.verify.title') }}</h1>
                    <p style="margin:0 0 28px;color:#686863;font-size:16px;line-height:1.65;">{{ __('account.mail.verify.intro') }}</p>
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="#b54d23" style="background-color:#b54d23;border-radius:6px;text-align:center;">
                        <a href="{{ $verificationUrl }}" style="display:inline-block;border:16px solid #b54d23;border-radius:6px;color:#fdfdfc;font-size:16px;line-height:20px;font-weight:700;text-decoration:none;">{{ __('account.mail.verify.action') }}</a>
                    </td></tr></table>
                    <p style="margin:20px 0 0;color:#686863;font-size:13px;line-height:1.6;">{{ __('account.mail.verify.expires', ['minutes' => $minutes]) }}</p>
                    <p style="margin:28px 0 0;padding-top:20px;border-top:1px solid #e2e2df;color:#686863;font-size:12px;line-height:1.6;">{{ __('notifications.mail.link_fallback') }}<br><a href="{{ $verificationUrl }}" style="color:#b54d23;word-break:break-all;overflow-wrap:anywhere;">{{ $verificationUrl }}</a></p>
                </td></tr>
                <tr><td style="padding:20px 0;color:#686863;font-size:12px;line-height:1.6;">{{ __('account.mail.verify.ignore') }}</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
