{{--
    Il riquadro del terzo salvataggio (§15.1).

    Compare **dopo** il terzo salvataggio da anonimo, mai prima: il primo click
    deve funzionare e basta. Lo rivela lo script contando le voci nel
    `localStorage`; finché non succede resta fuori dal flusso del documento e
    non occupa spazio.

    Quello che offre è il **promemoria**, non il salvataggio: il salvataggio
    funziona già, ed è esattamente per questo che chiederlo come motivo per
    registrarsi non convincerebbe nessuno.
--}}
@guest
    <aside
        data-save-prompt
        hidden
        role="complementary"
        aria-labelledby="promemoria-titolo"
        class="fixed inset-x-3 bottom-3 z-40 mx-auto max-w-md rounded-card bg-surface p-card shadow-lift ring-1 ring-line"
    >
        <h2 id="promemoria-titolo" class="text-card text-ink">{{ __('account.prompt.title') }}</h2>

        <p class="mt-1.5 text-sm text-ink-muted">{{ __('account.prompt.body') }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <a
                href="{{ route('account.register') }}"
                class="rounded-pill bg-brand px-3.5 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
            >
                {{ __('account.prompt.action') }}
            </a>

            <button
                type="button"
                data-save-prompt-dismiss
                class="rounded-pill px-3.5 py-2 text-sm font-semibold text-ink-muted transition hover:text-ink"
            >
                {{ __('account.prompt.dismiss') }}
            </button>
        </div>
    </aside>
@endguest
