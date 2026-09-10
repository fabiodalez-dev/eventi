<!doctype html>
<html lang="it" style="color-scheme:light">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Collega una Pagina Meta · inCittà</title>@vite('resources/css/filament/admin/theme.css')</head>
<body style="background:oklch(98.8% 0.002 80);color:#262624;font-family:'Manrope Variable',sans-serif">
<main style="max-width:680px;margin:48px auto;padding:24px">
    <h1 style="font-family:'Bricolage Grotesque Variable',sans-serif;font-size:32px;font-weight:700">Scegli la Pagina da collegare</h1>
    <p style="margin:16px 0">Il collegamento userà questa Pagina Facebook e il suo account Instagram professionale, se presente. L’autopost resterà disattivato finché non lo abiliti nelle impostazioni.</p>
    <form method="post" action="{{ route('social.meta.select') }}">@csrf
        @foreach($pages as $page)
            <label style="display:flex;gap:12px;padding:20px;margin:12px 0;border:1px solid #deddd7;border-radius:8px"><input type="radio" name="page_id" value="{{ $page['id'] }}" required><span><strong>{{ $page['name'] }}</strong><br>{{ $page['instagram_id'] ? 'Instagram professionale collegato' : 'Solo Facebook: nessun account Instagram associato' }}</span></label>
        @endforeach
        <button type="submit" style="padding:14px 20px;background:#b54d23;color:oklch(99.4% 0.001 80);border:0;border-radius:6px;font-weight:700">Collega la Pagina selezionata</button>
        <a href="{{ \App\Filament\Admin\Pages\SocialSettings::getUrl() }}" style="display:inline-block;padding:16px">Annulla</a>
    </form>
</main>
</body></html>
