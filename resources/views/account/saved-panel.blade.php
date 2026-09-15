{{--
    Il contenuto del pannello delle date salvate (§15.1).

    Arriva per conto suo, chiesto dall'icona in testata, e si innesta dentro un
    `<dialog>`. Non è una pagina: non porta testata, piè di pagina né titolo di
    documento — solo l'elenco.

    **Righe compatte, non le card dell'elenco.** Una card è un annuncio: ha la
    locandina in 3/4, la categoria, il prezzo, lo stato. Dentro una finestra
    alta seicento pixel ne entrerebbero due, e chi apre i propri salvataggi
    vuole vedere quante date ha e quando, non riguardarle una per una. Qui la
    miniatura è quadrata e piccola, e la riga dice l'essenziale.

    **Il segnalibro resta.** È l'unica azione che ha senso qui dentro: togliere
    una data che non serve più. Dopo l'innesto lo script lancia
    `event-browser:updated`, che è il segnale con cui questo sito riaggancia i
    segnalibri comparsi dopo il primo disegno — senza, sarebbero pulsanti che
    non fanno niente.

    (Nel codice più vecchio questo comando è chiamato «il cuore»: il nome è
    rimasto, l'icona è un segnalibro.)
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $saves = app(\App\Support\CurrentSaves::class);
@endphp

<div class="flex flex-col gap-4" data-saved-panel>
    @if ($occurrences->isEmpty())
        <div class="flex flex-col gap-2 py-6 text-center">
            <p class="m-0 font-display text-lg font-extrabold text-ink">{{ __('account.saved.empty_title') }}</p>
            <p class="m-0 text-sm text-ink-muted">{{ __('account.saved.empty_body') }}</p>
        </div>
    @else
        <ul class="m-0 flex list-none flex-col gap-1.5 p-0" data-saved-panel-list>
            @foreach ($occurrences as $occurrence)
                @php
                    $event = $occurrence->event;
                    $venue = $occurrence->locationVenue();
                    $url = \App\Support\EventUrl::occurrence($occurrence);
                    $quando = $occurrence->is_all_day
                        ? $formatter->day($occurrence->business_date).' '.__('common.separator').' '.__('events.badge.all_day')
                        : $formatter->dayAndTime($occurrence->business_date, $occurrence->starts_at);
                @endphp

                <li class="saved-row group relative flex items-center gap-3 border-b-2 border-line p-2" data-saved-row="{{ $occurrence->getKey() }}">
                    <span class="saved-row__thumb relative block size-14 shrink-0 overflow-hidden bg-surface">
                        <x-event-artwork :event="$event" />
                    </span>

                    <span class="flex min-w-0 flex-auto flex-col gap-1">
                        <span class="font-display text-[0.688rem] leading-none font-extrabold tracking-[0.1em] text-accent uppercase">{{ $quando }}</span>

                        <a
                            href="{{ $url }}"
                            class="truncate font-display text-[0.938rem] leading-tight font-extrabold tracking-[-0.02em] text-ink after:absolute after:inset-0 after:content-['']"
                        >{{ $event->title }}</a>

                        @if ($venue !== null)
                            <span class="truncate text-xs text-ink-subtle">{{ $venue->name }}</span>
                        @endif
                    </span>

                    {{-- Il segnalibro sta sopra all'ancora che copre tutta la
                         riga, altrimenti togliere dai salvati aprirebbe
                         l'evento invece di toglierlo. --}}
                    <x-save-heart
                        :occurrence="$occurrence"
                        :saved="$saves->has((int) $occurrence->getKey())"
                        class="relative z-10 shrink-0"
                    />
                </li>
            @endforeach
        </ul>
    @endif

    <div class="flex flex-col gap-3 border-t-2 border-line pt-3">
        @if ($authenticated)
            <a
                href="{{ route('account.saved') }}"
                class="ui-action inline-flex min-h-12 items-center justify-center bg-accent px-4 font-display text-[0.875rem] leading-none font-extrabold tracking-[0.06em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
            >
                {{ $total > $occurrences->count() ? __('account.saved.panel_more', ['count' => $total]) : __('account.saved.panel_all') }}
            </a>
        @else
            {{-- Per chi non ha un account la pagina dei salvataggi chiede
                 l'accesso, quindi mandarcelo e basta sarebbe un vicolo cieco:
                 qui si dice prima che cosa aggiunge l'account, visto che il
                 salvataggio funziona già senza. --}}
            @if ($occurrences->isNotEmpty())
                <p class="m-0 text-xs leading-relaxed text-ink-muted">{{ __('account.saved.panel_guest_hint') }}</p>
            @endif

            <a
                href="{{ route('login') }}"
                class="ui-action inline-flex min-h-12 items-center justify-center bg-accent px-4 font-display text-[0.875rem] leading-none font-extrabold tracking-[0.06em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
            >
                {{ __('account.saved.panel_guest_action') }}
            </a>
        @endif
    </div>
</div>
