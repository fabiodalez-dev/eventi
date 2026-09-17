{{--
    Il modale che chiede l'iscrizione prima di commentare o reagire.

    **Perché un `<dialog>`.** Se lo porta il browser: fondo scuro, chiusura
    con Esc, fuoco intrappolato dentro, ritorno del fuoco a chi lo ha aperto.
    È lo stesso elemento che il sito usa già per la locandina a schermo intero
    e per l'anteprima del calendario.

    **Perché un modale e non un collegamento.** Chi sta leggendo un evento e
    vuole commentarlo non deve perdere la pagina per iscriversi: il modale
    spiega cosa serve e lascia l'evento dov'è.
--}}
<dialog
    id="iscriviti-per-partecipare"
    aria-labelledby="iscriviti-titolo"
    class="m-auto w-[calc(100%-2rem)] max-w-md border-2 border-accent bg-canvas p-6 text-ink backdrop:bg-black/70"
>
    {{-- La X sta in un `form method="dialog"`: chiude il browser, senza una
         riga di JavaScript. `aria-label` perché il contenuto è un disegno. --}}
    <form method="dialog" class="flex items-start justify-between gap-4">
        <h2 id="iscriviti-titolo" class="font-display text-2xl leading-tight font-extrabold tracking-[-0.02em]">
            {{ __('comments.join.title') }}
        </h2>

        <button
            type="submit"
            aria-label="{{ __('comments.join.close') }}"
            class="ui-action -mt-2 -mr-2 inline-flex size-12 shrink-0 items-center justify-center text-ink-muted transition hover:text-ink"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <path d="M5 5l14 14M19 5L5 19" />
            </svg>
        </button>
    </form>

    <p class="mt-3 text-ink-muted">{{ __('comments.join.body') }}</p>

    <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('account.register', ['intended' => request()->fullUrl().'#commenti']) }}" class="ui-action inline-flex min-h-12 items-center bg-accent px-5 py-3 font-semibold text-on-accent">
            {{ __('comments.join.register') }}
        </a>

        <a href="{{ route('login', ['intended' => request()->fullUrl().'#commenti']) }}" class="ui-action inline-flex min-h-12 items-center border-2 border-line px-5 py-3 font-semibold hover:border-accent">
            {{ __('comments.join.login') }}
        </a>
    </div>
</dialog>
