{{--
    Il selettore del tema: scuro, chiaro oppure come il sistema. Tre righe una
    sotto l'altra, ognuna con l'anteprima in miniatura a sinistra e il nome a
    destra: sul telefono si leggono senza stringersi, sullo schermo largo
    restano nella colonna della pagina.

    «Come il sistema» nel profilo è `null`: nessuna preferenza, vale il tema
    del dispositivo e cambia con lui. Per chi è collegato la scelta corrente la
    sa il server; per l'ospite la decide `appearance.js` leggendo il browser
    (la pagina dell'ospite può arrivare dalla cache, uguale per tutti).

    Le anteprime sono le stesse del tema (`appearance.css`), disegnate a
    200×150 e rimpicciolite: l'altezza la fissa quel foglio di stile.
--}}
@php
    $user = auth()->user();
    $current = $user === null ? null : ($user->appearance ?? 'system');
    $options = ['dark', 'light', 'system'];
@endphp
<form data-appearance-form action="{{ route('appearance.update') }}" method="POST"
    data-msg-saving="{{ __('account.appearance.saving') }}"
    data-msg-saved="{{ __('account.appearance.saved_profile') }}"
    data-msg-saved-browser="{{ __('account.appearance.saved_browser') }}"
    data-msg-system-browser="{{ __('account.appearance.system_browser') }}"
    data-msg-not-stored="{{ __('account.appearance.not_stored') }}"
    data-msg-failed="{{ __('account.appearance.failed') }}"
>
    @csrf
    @method('PATCH')
    <fieldset class="m-0 min-w-0 border-0 p-0">
        <legend class="sr-only">{{ __('account.appearance.legend') }}</legend>
        <div class="flex flex-col gap-3">
            @foreach ($options as $value)
                <button
                    type="{{ $user !== null ? 'submit' : 'button' }}"
                    name="appearance"
                    value="{{ $value }}"
                    data-appearance-choice="{{ $value }}" aria-pressed="{{ $current === $value ? 'true' : 'false' }}"
                    class="appearance-option flex w-full min-w-0 items-center gap-4"
                >
                    <span class="relative block h-[84px] w-28 shrink-0 overflow-hidden" aria-hidden="true">
                        @foreach ($value === 'system' ? ['dark', 'light'] : [$value] as $preview)
                            <span @class([
                                'appearance-preview appearance-preview--'.$preview,
                                'absolute top-0 left-0 w-[200px] origin-top-left scale-[0.56]',
                                '[clip-path:polygon(100%_0,100%_100%,0_100%)]' => $value === 'system' && $preview === 'light',
                            ])>
                                <span class="preview-nav"><span></span><i></i><i></i></span>
                                <span class="preview-content"><b></b><i></i><span><em></em><em></em><em></em></span></span>
                            </span>
                        @endforeach
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col gap-1 py-1">
                        <strong class="text-base font-semibold text-ink">{{ __('account.appearance.options.'.$value.'.label') }}</strong>
                        <small class="text-sm text-ink-muted">{{ __('account.appearance.options.'.$value.'.description') }}</small>
                    </span>
                    <span class="appearance-check shrink-0 pr-2" aria-hidden="true"><x-lucide name="check" class="size-5" /></span>
                </button>
            @endforeach
        </div>
    </fieldset>
    <p class="mt-4 mb-0 text-sm text-ink-muted">{{ $user !== null ? __('account.appearance.help_user') : __('account.appearance.help_guest') }}</p>
    <p data-appearance-status role="status" aria-live="polite" class="mt-2 mb-0 text-sm empty:hidden"></p>
    <noscript><p class="mt-3 mb-0 text-sm">{{ $user !== null ? __('account.appearance.noscript_user') : __('account.appearance.noscript_guest') }}</p></noscript>
</form>
