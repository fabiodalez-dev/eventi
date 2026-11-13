{{--
    Gli script di consenso li scrive un amministratore e girano sulla stessa
    origine del pannello: nella sessione di un membro dello staff potrebbero
    agire con i suoi poteri, compreso il dare a qualcuno un ruolo che chi li
    ha scritti non può dare. Allo staff collegato non si caricano.
--}}
@unless (auth()->user()?->isEditorialStaff())
@foreach (\App\Models\ConsentScript::enabledScripts() as $consentScript)
    @if (app(\App\Support\Consent::class)->allows($consentScript->category))
        @if (filled($consentScript->code))
            <script @cspNonce data-consent-script="{{ $consentScript->id }}">{!! str_ireplace('</script', '<\\/script', $consentScript->code) !!}</script>
        @endif
        @if (filled($consentScript->src))
            <script @cspNonce defer src="{{ $consentScript->src }}" data-consent-script="{{ $consentScript->id }}"></script>
        @endif
    @endif
@endforeach
@endunless
