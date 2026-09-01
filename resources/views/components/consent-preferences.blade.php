{{--
    Il pannello con cui si cambia idea, dentro la Cookie Policy (§16: una scelta
    deve poter essere ritirata con la stessa facilità con cui è stata data).

    Il banner, a quel punto, è sparito da un pezzo: senza questo pannello
    l'unico modo di tornare sui propri passi sarebbe cancellare i cookie del
    sito a mano dalle impostazioni del browser, che non è "la stessa facilità".

    Tre pulsanti, tre gesti diversi:
    - accettare o rifiutare, che scrivono una scelta nuova nel registro;
    - **cancellare la scelta**, che è un'altra cosa: toglie il cookie e fa
      ricomparire il banner alla pagina successiva. Un rifiuto registrato non è
      l'assenza di una scelta, ed è giusto poter tornare anche a quella.
--}}
@php
    $consent = app(\App\Support\Consent::class);
    $analytics = app(\App\Services\Analytics\AnalyticsScript::class);
    $granted = $consent->allows(\App\Enums\ConsentCategory::Statistics);

    $current = match (true) {
        ! $consent->decided() => __('consent.manage.current_none'),
        $granted => __('consent.manage.current_granted'),
        default => __('consent.manage.current_denied'),
    };

    $choiceClasses = 'bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong';

    $provider = $analytics->provider();
    $host = $analytics->configured() ? (parse_url($analytics->src(), PHP_URL_HOST) ?: $analytics->src()) : null;
@endphp

<section aria-labelledby="preferenze-titolo" class="mt-section bg-surface p-card">
    <h2 id="preferenze-titolo" class="font-display text-card text-ink">{{ __('consent.manage.title') }}</h2>

    <p class="mt-1.5 text-sm text-ink-muted">{{ $current }}</p>

    {{-- Lo stato dello strumento di statistica si legge dalla configurazione,
         non è scritto nel testo della pagina: il giorno in cui viene acceso o
         spento, la Cookie Policy dice comunque la verità. --}}
    <p class="mt-1 text-sm text-ink-muted">
        {{ $provider !== null && $host !== null
            ? __('consent.analytics.active', ['provider' => $provider->label(), 'host' => $host])
            : __('consent.analytics.inactive') }}
    </p>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <form method="POST" action="{{ route('consent.store') }}" class="contents">
            @csrf

            <button type="submit" name="action" value="{{ \App\Enums\ConsentAction::AcceptAll->value }}" class="{{ $choiceClasses }}">
                {{ __('consent.accept') }}
            </button>

            <button type="submit" name="action" value="{{ \App\Enums\ConsentAction::RejectAll->value }}" class="{{ $choiceClasses }}">
                {{ __('consent.reject') }}
            </button>
        </form>

        @if ($consent->decided())
            <form method="POST" action="{{ route('consent.destroy') }}" class="contents">
                @csrf
                @method('DELETE')

                <button type="submit" class="px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase-muted underline transition hover:text-ink">
                    {{ __('consent.manage.revoke') }}
                </button>
            </form>
        @endif
    </div>
</section>
