{{--
    Il tema dei pannelli è quello scelto sul sito (`/aspetto`), non uno a parte.

    Gira in testa alla pagina (`PanelsRenderHook::HEAD_START`), prima dello
    script con cui Filament decide il tema: Filament legge `localStorage.theme`
    (`light`, `dark` o `system`) e qui quel valore lo scrive il profilo. Così la
    pagina nasce già nel tema giusto, senza un lampo chiaro prima dello scuro, e
    il commutatore di Filament non serve (è spento nei provider): una sola
    scelta, in un solo posto.

    Ordine, lo stesso del sito: la scelta salvata nel profilo; se il profilo non
    ne ha (`null`), quella fatta in questo browser (cookie `incitta_appearance`,
    non cifrato apposta: vedi `bootstrap/app.php`); altrimenti il sistema.
    Lo script non legge nient'altro e non scrive altro che la preferenza.
--}}
@php
    $profileAppearance = auth()->user()?->appearance;
    $browserAppearance = request()->cookie('incitta_appearance');
    $panelTheme = match (true) {
        in_array($profileAppearance, ['light', 'dark'], true) => $profileAppearance,
        in_array($browserAppearance, ['light', 'dark'], true) => $browserAppearance,
        default => 'system',
    };
@endphp
<meta name="color-scheme" content="{{ $panelTheme === 'system' ? 'light dark' : $panelTheme }}">
<script @cspNonce data-panel-theme="{{ $panelTheme }}">
    (() => {
        const theme = @js($panelTheme);
        try { localStorage.setItem('theme', theme); } catch {}
        const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
    })();
</script>
