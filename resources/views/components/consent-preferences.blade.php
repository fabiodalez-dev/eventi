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
    /*
     * Lo stato riassunto in una riga.
     *
     * Con una sola finalità facoltativa bastava «accettate» o «rifiutate». Con
     * due, il caso più comune diventa il terzo — una sì e una no — e chiamarlo
     * «rifiutate» sarebbe falso. Si elencano quelle attive, perché è la
     * risposta alla domanda vera: «cosa ho acconsentito?».
     */
    $attive = collect(\App\Enums\ConsentCategory::optional())
        ->filter(fn (\App\Enums\ConsentCategory $c): bool => $consent->allows($c))
        ->map(fn (\App\Enums\ConsentCategory $c): string => mb_strtolower($c->label()));

    $current = match (true) {
        ! $consent->decided() => __('consent.manage.current_none'),
        $attive->isEmpty() => __('consent.manage.current_denied'),
        default => __('consent.manage.current_partial', ['elenco' => $attive->join(', ', ' e ')]),
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
            : (\App\Models\ConsentScript::query()->where('enabled', true)->where('category', \App\Enums\ConsentCategory::Statistics)->exists() ? '' : __('consent.analytics.inactive')) }}
    </p>
    @php($configuredScripts = \App\Models\ConsentScript::query()->where('enabled', true)->orderBy('name')->pluck('name'))
    @if ($configuredScripts->isNotEmpty())
        <p class="mt-1 text-sm text-ink-muted">{{ __('consent.analytics.scripts', ['names' => $configuredScripts->join(', ')]) }}</p>
    @endif

    {{--
        **Una casella per finalità, non un interruttore solo.**

        Fin qui c'erano due pulsanti — accetta tutto, rifiuta tutto — e
        bastavano finché la finalità facoltativa era una. Con «Annunci» accanto
        a «Statistiche», accettare tutto per avere le une significherebbe
        accettare anche le altre: è esattamente il consenso «in blocco» che
        l'articolo 7 del GDPR non considera libero.

        I due pulsanti restano, perché per chi ha già deciso sono la strada più
        corta. Ma accanto c'è la scelta per singola finalità, che è quella che
        rende la decisione davvero granulare.

        Senza JavaScript funziona lo stesso: è un modulo, con caselle vere e un
        pulsante di invio.
    --}}
    <form method="POST" action="{{ route('consent.store') }}" class="mt-5 flex flex-col gap-4">
        @csrf

        <fieldset class="flex flex-col gap-3">
            <legend class="sr-only">{{ __('consent.manage.choose') }}</legend>

            @foreach (\App\Enums\ConsentCategory::optional() as $categoria)
                <label class="flex cursor-pointer items-start gap-3 border-2 border-line p-3.5 transition-colors hover:border-accent">
                    <input
                        type="checkbox"
                        name="categories[]"
                        value="{{ $categoria->value }}"
                        @checked($consent->allows($categoria))
                        class="mt-0.5 size-4 shrink-0 accent-[var(--brand)]"
                    >

                    <span class="flex flex-col gap-1">
                        <span class="font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase">{{ $categoria->label() }}</span>
                        <span class="text-sm text-ink-muted">{{ $categoria->description() }}</span>
                    </span>
                </label>
            @endforeach
        </fieldset>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" name="action" value="{{ \App\Enums\ConsentAction::Custom->value }}" class="{{ $choiceClasses }}">
                {{ __('consent.manage.save') }}
            </button>

            <button type="submit" name="action" value="{{ \App\Enums\ConsentAction::AcceptAll->value }}" class="{{ $choiceClasses }}">
                {{ __('consent.accept') }}
            </button>

            <button type="submit" name="action" value="{{ \App\Enums\ConsentAction::RejectAll->value }}" class="{{ $choiceClasses }}">
                {{ __('consent.reject') }}
            </button>
        </div>
    </form>

    <div class="mt-3 flex flex-wrap items-center gap-2">

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
