{{--
    Il banner del consenso (§16): preventivo, granulare, con il rifiuto semplice
    quanto l'accettazione.

    ## Perché è un modulo e non un pannello disegnato dallo script

    Perché il rifiuto deve costare un click a chiunque, anche a chi il
    JavaScript non lo esegue. Un banner che si chiude solo via script lascia a
    quelle persone una striscia in fondo allo schermo per sempre, che è il modo
    più efficace di far premere "accetta". Con lo script attivo il modulo viene
    inviato in sottofondo e il banner scompare senza ricaricare la pagina;
    senza, la pagina si ricarica e la scelta è comunque registrata.

    ## I due pulsanti hanno la stessa identica classe

    Non "simile": la stessa, presa da una variabile sola. È l'unico modo di
    rendere verificabile ciò che §16 chiede — «rifiuto semplice quanto
    l'accettazione» — invece di affidarlo all'occhio di chi rilegge il diff.
    Sono entrambi `<button type="submit">`, quindi raggiungibili da tastiera
    nell'ordine in cui stanno scritti, senza `tabindex` e senza trappole di
    focus.

    ## Non impedisce la lettura

    Sta in fondo, non copre la pagina, non ha uno sfondo che oscura il resto e
    non blocca lo scorrimento. Chi vuole leggere e decidere dopo, può.
--}}
@php
    $consent = app(\App\Support\Consent::class);
    $analytics = app(\App\Services\Analytics\AnalyticsScript::class);
    $optional = \App\Enums\ConsentCategory::optional();

    /*
     * La classe dei due pulsanti di scelta, scritta una volta sola. Sono
     * dichiarati come `variant: surface` e non uno pieno e uno scarno: due
     * pesi visivi diversi sono una preferenza espressa dal sito al posto di
     * chi legge.
     */
    $choiceClasses = 'flex-1 rounded-pill bg-brand px-4 py-2.5 text-center text-sm font-semibold text-on-brand transition hover:bg-brand-strong sm:flex-none sm:px-6';

    $policyUrl = \Illuminate\Support\Facades\Route::has('pages.show')
        ? route('pages.show', ['slug' => config('consent.policy_page')])
        : null;

    $cookieUrl = \Illuminate\Support\Facades\Route::has('pages.show')
        ? route('pages.show', ['slug' => config('consent.cookie_page')])
        : null;
@endphp

@unless ($consent->decided())
    <aside
        data-consent-banner
        role="region"
        aria-labelledby="consenso-titolo"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface shadow-lift"
    >
        <form
            method="POST"
            action="{{ route('consent.store') }}"
            data-consent-form
            class="mx-auto flex w-full max-w-content flex-col gap-3 px-gutter py-4"
        >
            @csrf

            <div class="flex flex-col gap-1">
                <h2 id="consenso-titolo" class="text-card text-ink">{{ __('consent.title') }}</h2>

                <p class="max-w-prose text-sm text-ink-muted">
                    {{ $analytics->configured() ? __('consent.body') : __('consent.body_without_analytics') }}
                </p>

                @if ($policyUrl !== null || $cookieUrl !== null)
                    <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        @if ($policyUrl !== null)
                            <a class="font-semibold text-brand underline" href="{{ $policyUrl }}">{{ __('consent.read_policy') }}</a>
                        @endif

                        @if ($cookieUrl !== null)
                            <a class="font-semibold text-brand underline" href="{{ $cookieUrl }}">{{ __('consent.read_cookies') }}</a>
                        @endif
                    </p>
                @endif
            </div>

            {{-- Il dettaglio è aperto da un `<details>`: funziona senza
                 JavaScript, è annunciato dai lettori di schermo come un
                 elemento che si apre, e non è una finestra che copre la
                 pagina. --}}
            <details class="rounded-card bg-surface-sunken px-4 py-3">
                <summary class="cursor-pointer text-sm font-semibold text-ink">{{ __('consent.preferences') }}</summary>

                <p class="mt-1 text-xs text-ink-subtle">{{ __('consent.preferences_hint') }}</p>

                <ul class="mt-3 flex flex-col gap-3">
                    {{-- La categoria necessaria si mostra ma non si sceglie:
                         una casella spuntata e bloccata dice la verità, una
                         casella libera che poi viene ignorata no. --}}
                    <li class="flex flex-col gap-0.5">
                        <span class="flex items-center gap-2 text-sm font-semibold text-ink">
                            {{ \App\Enums\ConsentCategory::Necessary->label() }}
                            <span class="rounded-pill bg-muted-badge px-2 py-0.5 text-eyebrow text-on-muted-badge uppercase">
                                {{ __('consent.always_active') }}
                            </span>
                        </span>
                        <span class="text-xs text-ink-subtle">{{ \App\Enums\ConsentCategory::Necessary->description() }}</span>
                    </li>

                    @foreach ($optional as $category)
                        <li>
                            <label class="flex gap-2.5" for="consenso-{{ $category->value }}">
                                <input
                                    id="consenso-{{ $category->value }}"
                                    type="checkbox"
                                    name="categories[]"
                                    value="{{ $category->value }}"
                                    class="mt-1 size-4 shrink-0 rounded border-line-strong text-brand focus:ring-brand"
                                >
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-sm font-semibold text-ink">{{ $category->label() }}</span>
                                    <span class="text-xs text-ink-subtle">{{ $category->description() }}</span>
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>

                <button
                    type="submit"
                    name="action"
                    value="{{ \App\Enums\ConsentAction::Custom->value }}"
                    class="mt-3 rounded-pill px-4 py-2 text-sm font-semibold text-ink ring-1 ring-line-strong transition hover:ring-brand"
                >
                    {{ __('consent.save_preferences') }}
                </button>
            </details>

            <div class="flex flex-wrap gap-2">
                <button type="submit" name="action" value="{{ \App\Enums\ConsentAction::AcceptAll->value }}" class="{{ $choiceClasses }}">
                    {{ __('consent.accept') }}
                </button>

                <button type="submit" name="action" value="{{ \App\Enums\ConsentAction::RejectAll->value }}" class="{{ $choiceClasses }}">
                    {{ __('consent.reject') }}
                </button>
            </div>
        </form>
    </aside>
@endunless
