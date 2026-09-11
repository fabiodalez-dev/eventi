@foreach (\App\Models\ConsentScript::query()->where('enabled', true)->orderBy('sort_order')->orderBy('id')->get() as $consentScript)
    @if (app(\App\Support\Consent::class)->allows($consentScript->category))
        @if (filled($consentScript->code))
            <script @cspNonce data-consent-script="{{ $consentScript->id }}">{!! str_ireplace('</script', '<\\/script', $consentScript->code) !!}</script>
        @endif
        @if (filled($consentScript->src))
            <script @cspNonce defer src="{{ $consentScript->src }}" data-consent-script="{{ $consentScript->id }}"></script>
        @endif
    @endif
@endforeach

