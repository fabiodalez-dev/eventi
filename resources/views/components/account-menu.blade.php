{{--
    Il menu dell'account nella testata.

    **Perché esiste.** Prima c'era un solo bottone, «Salvati», che per chi non
    era collegato portava alla pagina di accesso: dopo l'accesso non cambiava
    niente e non c'era nessuna via verso il proprio profilo, il proprio feed o
    l'uscita. Chi amministra il sito, entrando dal sito pubblico, non aveva
    **nessun collegamento verso il pannello**: doveva ricordarsi `/admin` e
    scriverlo a mano.

    **`<details>` e non un menu costruito in JavaScript.** Si apre e si chiude
    da solo, funziona senza script, e da tastiera è un elemento che i browser
    sanno già gestire. Un menu a tendina scritto a mano richiede di riscrivere
    tutto ciò che quel tag fa gratis — fuoco, `Esc`, il clic fuori — e di
    solito se ne riscrive metà.

    Il ritorno all'elenco: le voci amministrative stanno **in fondo e dopo un
    divisore**, perché chi ha un ruolo di redazione usa il sito anche come
    persona normale, e la cosa che cerca più spesso non è il pannello.
--}}
@php
    $utente = auth()->user();
@endphp

@guest
    <a
        href="{{ route('login') }}"
        class="ui-action ml-auto hidden h-[38px] shrink-0 items-center gap-2 border-2 border-line px-3 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent sm:flex"
    >
        <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><path d="M10 17l5-5-5-5"></path><path d="M15 12H3"></path>
        </svg>
        {{ __('account.nav.login') }}
    </a>
@endguest

@auth
    <details class="group relative ml-auto hidden shrink-0 sm:block" data-account-menu>
        <summary
            class="flex h-[38px] cursor-pointer list-none items-center gap-2 border-2 border-line px-3 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:border-accent hover:text-accent [&::-webkit-details-marker]:hidden"
            aria-haspopup="menu"
        >
            {{-- L'iniziale al posto di un'icona generica: dice *chi* è
                 collegato, che è l'informazione per cui si guarda lì. --}}
            <span aria-hidden="true" class="flex size-[18px] shrink-0 items-center justify-center bg-accent text-[0.625rem] text-on-accent">
                {{ \Illuminate\Support\Str::upper(mb_substr($utente?->name ?: ($utente?->email ?? '?'), 0, 1)) }}
            </span>

            <span class="max-w-[9rem] truncate">{{ $utente?->name ?: __('account.nav.account') }}</span>

            <svg aria-hidden="true" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="square" class="shrink-0 transition-transform group-open:rotate-180">
                <path d="M6 9l6 6 6-6"></path>
            </svg>
        </summary>

        <div
            role="menu"
            class="absolute right-0 z-50 mt-0.5 flex w-60 flex-col border-2 border-line bg-canvas"
        >
            <a role="menuitem" href="{{ route('account.saved') }}" class="flex items-center justify-between gap-3 border-b-2 border-line px-3.5 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-accent hover:text-on-accent">
                {{ __('account.nav.saved') }}
                <span class="text-accent group-hover:text-on-accent" data-saved-count>{{ $savedCount ?? 0 }}</span>
            </a>

            <a role="menuitem" href="{{ route('account.feed') }}" class="border-b-2 border-line px-3.5 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-accent hover:text-on-accent">
                {{ __('account.nav.feed') }}
            </a>

            {{-- Le preferenze delle email vivevano solo dietro un collegamento
                 firmato dentro le email: chi non ne riceveva non poteva
                 iscriversi, perche' per riceverne serviva iscriversi. --}}
            <a role="menuitem" href="{{ route('account.notifications') }}" class="border-b-2 border-line px-3.5 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-accent hover:text-on-accent">
                {{ __('account.nav.notifications') }}
            </a>

            <a role="menuitem" href="{{ route('account.profile') }}" class="px-3.5 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-accent hover:text-on-accent">
                {{ __('account.nav.profile') }}
            </a>

            {{-- Le vie verso i pannelli, dopo un divisore più marcato: chi le
                 ha usa il sito anche come persona normale, e non è questo che
                 cerca più spesso. --}}
            @if ($utente?->isEditorialStaff())
                <a role="menuitem" href="{{ url('/admin') }}" class="flex items-center gap-2 border-t-4 border-line px-3.5 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-accent uppercase transition-colors hover:bg-accent hover:text-on-accent">
                    <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square">
                        <rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>
                    </svg>
                    {{ __('account.nav.admin') }}
                </a>
            @endif

            @if ($utente?->venues()->exists())
                <a role="menuitem" href="{{ url('/gestione') }}" class="flex items-center gap-2 {{ $utente?->isEditorialStaff() ? 'border-t-2' : 'border-t-4' }} border-line px-3.5 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase transition-colors hover:bg-accent hover:text-on-accent">
                    <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square">
                        <path d="M3 21h18"></path><path d="M5 21V8l7-5 7 5v13"></path><path d="M10 21v-6h4v6"></path>
                    </svg>
                    {{ __('account.nav.venue') }}
                </a>
            @endif

            {{-- L'uscita è un modulo e non un collegamento: una richiesta che
                 cambia lo stato non deve poter partire da un `href` che
                 qualcuno può far seguire a tua insaputa. --}}
            <form method="POST" action="{{ route('account.logout') }}" class="border-t-2 border-line">
                @csrf
                <button role="menuitem" type="submit" class="w-full px-3.5 py-2.5 text-left font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink-muted uppercase transition-colors hover:bg-line-strong hover:text-ink">
                    {{ __('account.nav.logout') }}
                </button>
            </form>
        </div>
    </details>
@endauth
