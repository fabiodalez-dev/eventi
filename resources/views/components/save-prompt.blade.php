{{--
    L'invito ad accedere dopo il primo salvataggio da anonimo (§15.1, D49).

    **Il salvataggio e' gia' avvenuto quando questo si apre.** Non e' un
    cancello messo davanti al cuore: il click viene eseguito, la data finisce
    nel `localStorage`, e solo dopo compare questo. Chi chiude con «Non adesso»
    tiene il suo salvataggio e non se lo vede piu' chiedere; chi accede se lo
    ritrova sull'account, perche' al primo caricamento da collegato il travaso
    parte da solo (`POST /salvataggi/unisci`).

    Era un riquadro discreto in fondo alla pagina, e compariva al terzo
    salvataggio. Il primo pero' restava muto: chi ne faceva uno solo non
    scopriva mai che viveva soltanto in quel browser, e cambiando telefono lo
    perdeva senza essere mai stato avvisato.

    **`<dialog>` e non un div con `position: fixed`.** Il focus resta dentro
    finche' e' aperto, Esc chiude, il resto della pagina diventa inerte per chi
    naviga da tastiera e per i lettori di schermo: tutte cose che a mano si
    scrivono male e si dimenticano. Il costo e' una riga di JavaScript,
    `showModal()`, e senza quella riga questo elemento resta semplicemente
    chiuso — il salvataggio funziona lo stesso.
--}}
@guest
    <dialog
        data-save-prompt
        aria-labelledby="promemoria-titolo"
        class="m-auto w-[min(28rem,calc(100vw-1.5rem))] border-2 border-line bg-canvas p-0 text-ink backdrop:bg-ink/70"
    >
        <div class="flex flex-col gap-3 p-[clamp(1.25rem,3vw,1.75rem)]">
            <div class="flex items-start gap-2.5">
                {{-- Il segno di spunta dice «e' fatta» prima ancora del testo. --}}
                <span aria-hidden="true" class="mt-0.5 flex size-5 shrink-0 items-center justify-center bg-accent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="square" class="size-3 text-on-accent">
                        <path d="M4 12.5 9.5 18 20 6.5"></path>
                    </svg>
                </span>

                <h2 id="promemoria-titolo" class="font-display text-[clamp(1.125rem,1.6vw,1.375rem)] leading-none font-extrabold tracking-[-0.02em] uppercase">
                    {{ __('account.prompt.title') }}
                </h2>
            </div>

            <p class="text-sm text-ink-muted">{{ __('account.prompt.body') }}</p>

            <div class="mt-1 flex flex-wrap items-center gap-2">
                <a
                    href="{{ route('account.register') }}"
                    class="bg-accent px-3.5 py-2 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                >
                    {{ __('account.prompt.action') }}
                </a>

                <a
                    href="{{ route('login') }}"
                    class="border-2 border-line px-3.5 py-2 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent"
                >
                    {{ __('account.prompt.login') }}
                </a>

                {{-- «Non adesso» sta in fondo e non e' un pulsante pieno: chi
                     ha appena salvato ha gia' ottenuto quello che voleva, e
                     questa e' la via d'uscita, non un ripensamento. --}}
                <button
                    type="button"
                    data-save-prompt-dismiss
                    class="ml-auto px-2 py-2 text-sm font-semibold text-ink-subtle underline transition hover:text-ink"
                >
                    {{ __('account.prompt.dismiss') }}
                </button>
            </div>
        </div>
    </dialog>
@endguest
