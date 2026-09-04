{{--
    "In corso adesso" e "Inizia tra poco" (§11.2). Si disegnano dal server
    insieme al resto della pagina; se non c'è nulla in nessuna delle due
    finestre non disegna alcuna sezione (§8.6) e resta un contenitore senza
    altezza.

    **Perché non usa più `x-section-heading`.** Lo faceva, e il risultato era
    che due sezioni su sette avevano l'intestazione di un altro sistema: riga
    sottile, quadratino, titolo piccolo, e per giunta a filo del bordo mentre
    le sorelle rientravano di un margine. Nella stessa schermata convivevano
    due modi di annunciare una sezione.

    Qui la home ha il suo: occhiello in alto, titolo grandissimo, e la fascia
    chiusa da un divisore pieno. Quel formato è scritto a mano nella home
    perché dipende dalla numerazione progressiva delle sezioni — ed è proprio
    la numerazione che queste due non possono avere: compaiono solo quando c'è
    qualcosa dentro, e un numero che dipende dall'ora farebbe cominciare la
    stessa pagina da «01 —» o da «03 —» a seconda del momento in cui la si
    apre.

    Al posto del numero c'è quindi una marca di stato: il pallino che pulsa per
    ciò che è in corso davvero, il quadratino pieno per ciò che sta per
    cominciare. L'incolonnamento del titolo resta identico a quello delle
    sorelle, che è la cosa che conta.
--}}
<div>
    @if ($ongoing->isNotEmpty())
        <section class="border-b-2 border-line defer-offscreen" aria-labelledby="sezione-ongoing">
            <div class="flex flex-col gap-2 px-gutter pt-[clamp(1.5rem,2.8vw,2.75rem)] pb-[clamp(1.125rem,2vw,1.625rem)]">
                <span class="flex items-center gap-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase">
                    <span aria-hidden="true" class="size-[7px] shrink-0 rounded-full bg-accent blink-dot"></span>
                    {{ __('events.sections.ongoing_eyebrow') }}
                </span>

                <h2 id="sezione-ongoing" class="m-0 font-display text-[clamp(1.875rem,4vw,4rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase reveal-left">
                    {{ __('events.sections.ongoing') }}
                </h2>
            </div>

            <x-event-grid :occurrences="$ongoing" context="ongoing" />
        </section>
    @endif

    @if ($startingSoon->isNotEmpty())
        <section class="border-b-2 border-line defer-offscreen" aria-labelledby="sezione-starting-soon">
            <div class="flex flex-col gap-2 px-gutter pt-[clamp(1.5rem,2.8vw,2.75rem)] pb-[clamp(1.125rem,2vw,1.625rem)]">
                <span class="flex items-center gap-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-ink-muted uppercase">
                    <span aria-hidden="true" class="size-[7px] shrink-0 bg-ink"></span>
                    {{ __('events.sections.starting_soon_eyebrow') }}
                </span>

                <h2 id="sezione-starting-soon" class="m-0 font-display text-[clamp(1.875rem,4vw,4rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase reveal-left">
                    {{ __('events.sections.starting_soon') }}
                </h2>
            </div>

            <x-event-grid :occurrences="$startingSoon" context="starting_soon" />
        </section>
    @endif
</div>
