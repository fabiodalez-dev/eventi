{{--
    La scheda di un evento (§11.5).

    Un evento non è una data: qui si elencano **tutte** le date future, ognuna
    con i propri orari, il proprio stato e il proprio pulsante per il
    calendario. È l'unica forma onesta per una rassegna di dieci serate.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $venue = $event->venue;
    /*
     * Quanto spazio occupa DAVVERO. La fascia di apertura e' una griglia a due
     * colonne che si affiancano a 800px (`minmax(min(400px,100%),1fr)`), e da
     * quando questa scheda e' a tutta larghezza ogni colonna vale mezzo
     * schermo: su 1440 sono 719px misurati, non i 448 che si dichiaravano
     * prima — quando la pagina stava ancora dentro il contenitore centrato.
     *
     * Un `sizes` che dichiara meno del vero fa scegliere al browser una
     * variante troppo piccola, e la locandina si vede sgranata; dichiararne
     * di piu' gli fa scaricare peso che non serve. Va tenuto insieme alla
     * geometria: se cambia una, va cambiato l'altro.
     */
    $poster = \App\Support\Poster::imageSet($event)?->withSizes('(min-width: 800px) 50vw, 100vw');
    $custom = is_array($event->custom_location) ? $event->custom_location : [];
    $shareUrl = route('events.show', $event);
    $dates = $occurrences->isNotEmpty() ? $occurrences : $pastOccurrences;
    $shown = $dates->take(config('eventi.dates_shown'));
    $lineups = $dates->flatMap(fn ($occurrence) => $occurrence->lineups)->unique('id');

    /* I fatti sovrapposti alla locandina: i primi quattro della scheda
       tecnica. Sono le cose che si guardano prima di decidere — durata, età
       minima, che tipo di posto è — e sopra la piega valgono più che in fondo
       a una tabella che quasi nessuno scorre. La tabella completa resta al suo
       posto, più sotto: qui si anticipa, non si sostituisce. */
    $facts = array_slice(\App\DTOs\FactList::fromMixed($event->facts)->toArray(), 0, 4);

    /* La capienza della PROSSIMA data, non di tutte: la barra sopra la piega
       risponde a «faccio in tempo a prendere il biglietto per la prima?». */
    $nextCapacity = $occurrences->first() !== null
        ? \App\Support\Capacity::for($occurrences->first())
        : null;

    /* Gli indirizzi che finiscono in un `href` passano da `SafeUrl`: Blade
       sfugge il contenuto dell'attributo ma non impedisce che l'indirizzo
       stesso sia `javascript:`, che al clic esegue codice. Li scrive chi
       gestisce il locale o propone l'evento. */
    $ticketUrl = \App\Support\SafeUrl::href($event->ticket_url);
@endphp

<x-layouts.app :meta="$meta" :preload="$poster" wide>
    @if ($isPreview ?? false)
        <p role="status" class="bg-accent text-on-accent p-4 font-bold">{{ __('promotions.preview_notice') }}</p>
    @endif
    <x-slot:head>
        <x-json-ld :data="$structuredData" />

        {{-- La mappa del locale, più in basso, è lo stesso riquadro MapLibre di
             tutto il sito e senza questo script non si accende: restava un
             rettangolo con la propria frase, e sembrava rotta. --}}
        @vite('resources/js/map.js')
    </x-slot:head>

    {{-- La riga di ritorno: dove sono e da dove vengo. Nel riferimento è una
         fascia sottile fra la testata e l'apertura, e serve a non lasciare la
         scheda senza contesto quando ci si arriva da una ricerca. --}}
    <nav aria-label="{{ __('ui.breadcrumb') }}" class="flex flex-wrap items-center gap-4 border-b-2 border-line px-gutter py-3.5">
        <a
            href="{{ route('events.index') }}"
            class="inline-flex items-center gap-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.16em] uppercase transition-colors hover:text-accent"
        >
            <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"><path d="M19 12H5M11 5l-7 7 7 7"></path></svg>
            {{ __('events.title') }}
        </a>

        <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.16em] text-ink-subtle uppercase">
            @if ($event->category !== null)
                <a class="hover:text-accent" href="{{ route('events.category', $event->category) }}">{{ $event->category->name }}</a>
                <span aria-hidden="true"> / </span>
            @endif
            @if ($venue?->zone)
                {{ $venue->zone }}<span aria-hidden="true"> / </span>
            @endif
            {{ $shown->first() !== null ? $formatter->day($shown->first()->business_date) : '' }}
        </span>
    </nav>

    {{-- ------------------------------------------------------------------
         L'apertura: la locandina a sinistra con sopra i dati essenziali, il
         titolo e le azioni a destra. È l'unico punto della scheda in cui la
         fotografia occupa spazio — sotto, il contenuto è tutto testo.
    ------------------------------------------------------------------- --}}
    <section class="grid gap-0.5 border-b-2 border-line bg-line [grid-template-columns:repeat(auto-fit,minmax(min(400px,100%),1fr))]">
        <div class="relative min-h-[clamp(20.625rem,42vw,33.75rem)] overflow-hidden bg-canvas">
            @if ($poster !== null)
                <x-media-image
                    :set="$poster"
                    :alt="__('events.card.poster_alt', ['title' => $event->title])"
                    width="1200"
                    height="1600"
                    :sizes="$poster->sizes"
                    :eager="true"
                    class="absolute inset-0 size-full object-cover opacity-60 grayscale-photo"
                />
            @endif

            <span aria-hidden="true" class="absolute inset-0 bg-[linear-gradient(120deg,rgba(11,11,11,.85)_0%,rgba(11,11,11,.3)_60%,rgba(11,11,11,.7)_100%)]"></span>

            <div class="relative flex h-full flex-col justify-between gap-6 p-[clamp(1.125rem,2.2vw,2rem)]">
                <div class="flex flex-wrap gap-1.5">
                    @if ($event->category !== null)
                        <a
                            href="{{ route('events.category', $event->category) }}"
                            class="bg-accent px-2.5 py-[7px] font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-on-accent uppercase"
                        >
                            {{ $event->category->name }}
                        </a>
                    @endif

                    @if ($venue?->zone)
                        <span class="border-2 border-ink px-2.5 py-[5px] font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] uppercase">{{ $venue->zone }}</span>
                    @endif

                    @if ($event->is_outdoor)
                        <span class="border-2 border-ink px-2.5 py-[5px] font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] uppercase">{{ __('events.badge.outdoor') }}</span>
                    @endif
                </div>

                <div class="flex flex-col gap-2.5">
                    @if ($venue !== null || filled($custom['name'] ?? null))
                        <a
                            @if ($venue !== null) href="{{ route('venues.show', $venue) }}" @endif
                            class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase"
                        >
                            {{ collect([$venue?->name ?? $custom['name'] ?? null, $venue?->address ?? $custom['address'] ?? null])->filter()->implode(' '.__('common.separator').' ') }}
                        </a>
                    @endif

                    {{-- I fatti in evidenza: durata, età minima, porte. Sono le
                         cose che si cercano prima di decidere, e stanno qui
                         invece che in fondo alla tabella. --}}
                    @if ($facts !== [])
                        <div class="flex flex-wrap items-stretch gap-0.5">
                            @foreach ($facts as $fatto)
                                <span class="flex flex-col gap-1 border-2 border-line bg-[rgba(11,11,11,.72)] px-3 py-2.5">
                                    <span class="font-display text-[0.563rem] leading-none font-extrabold tracking-[0.14em] text-ink-subtle uppercase">{{ $fatto['label'] }}</span>
                                    <span class="font-display text-[0.813rem] leading-none font-extrabold tracking-[-0.01em]">{{ $fatto['value'] }}</span>
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-col justify-center gap-[clamp(1rem,1.8vw,1.5rem)] bg-canvas p-[clamp(1.25rem,2.4vw,2.25rem)]">
            <h1 class="m-0 font-display text-[clamp(2.125rem,4.4vw,4.75rem)] leading-[0.9] font-extrabold tracking-[-0.045em] uppercase reveal-clip">
                {{ $event->title }}
            </h1>

            @if ($event->subtitle)
                <p class="m-0 max-w-[52ch] text-[clamp(0.875rem,1.1vw,1.031rem)] leading-[1.6] text-pretty text-ink-muted">{{ $event->subtitle }}</p>
            @endif

            @if ($nextCapacity !== null && $nextCapacity->percentSold() !== null)
                <div class="flex flex-col gap-2">
                    <div class="h-1 overflow-hidden bg-ink/[0.18]">
                        <div class="h-full origin-left bg-accent animate-[lineGrow_1.2s_cubic-bezier(.2,.8,.2,1)_both]" style="width: {{ $nextCapacity->percentSold() }}%"></div>
                    </div>
                    <div class="flex justify-between gap-3 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase">
                        <span class="text-ink-muted">{{ __('events.capacity.total', ['count' => $nextCapacity->total]) }}</span>
                        <span class="text-accent">{{ trans_choice('events.capacity.left', $nextCapacity->left, ['count' => $nextCapacity->left]) }}</span>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2">
                @if ($ticketUrl !== null)
                    <a
                        href="{{ $ticketUrl }}"
                        target="_blank"
                        rel="noopener nofollow"
                        class="inline-flex h-[54px] items-center gap-2 bg-accent px-[22px] font-display text-xs leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                    >
                        {{ __('events.detail.tickets') }} <x-price-tag :event="$event" as="text" class="text-on-accent" />
                        <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"><path d="M7 17 17 7M9 7h8v8"></path></svg>
                    </a>
                @endif

                <x-share-links :url="$shareUrl" :title="$event->title" />
            </div>
        </div>
    </section>

    {{-- **A tutta larghezza sono le fasce, non il corpo.** La riga di ritorno,
         il poster e le griglie in fondo guadagnano ad arrivare al vetro; questa
         colonna no. Senza il limite, su uno schermo da 1440 il testo arriva a
         900px — centoventi caratteri per riga, dove l'occhio tornando a capo
         perde la riga giusta — e su un monitor grande peggiora ancora. --}}
    <div class="mx-auto grid w-full max-w-content gap-8 px-gutter py-[clamp(1.5rem,2.8vw,2.75rem)] lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <article class="flex flex-col gap-6">
            @if ($occurrences->isEmpty() && $pastOccurrences->isNotEmpty())
                <p class="bg-surface px-4 py-3 text-sm font-semibold text-ink-muted">
                    {{ __('events.detail.finished') }}
                </p>
            @endif

            @if ($shown->isNotEmpty())
                <section aria-labelledby="date-evento" class="flex flex-col gap-3">
                    <h2 id="date-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('events.detail.all_dates') }}</h2>

                    <ul class="flex flex-col gap-2">
                        @foreach ($shown as $occurrence)
                            <li class="flex flex-wrap items-center justify-between gap-3 bg-canvas px-4 py-3 border-2 border-line">
                                <div class="flex min-w-0 flex-col gap-1">
                                    <time
                                        datetime="{{ $occurrence->is_all_day ? $formatter->isoDay($occurrence->business_date) : $formatter->iso($occurrence->starts_at) }}"
                                        class="font-semibold text-ink"
                                    >
                                        {{ $formatter->weekdayDate($occurrence->business_date) }}
                                    </time>

                                    @if (filled($occurrence->highlight))
                                        <x-badge tone="soon" size="sm" class="self-start">{{ $occurrence->highlight }}</x-badge>
                                    @endif

                                    <span class="text-sm text-ink-muted">
                                        @if ($occurrence->is_all_day)
                                            {{ __('events.badge.all_day') }}
                                        @else
                                            {{ $formatter->timeRange($occurrence->starts_at, $occurrence->ends_at) }}
                                            @if ($occurrence->ends_at === null)
                                                <span class="text-ink-subtle">{{ __('common.separator') }} {{ __('events.detail.ends_estimated') }} {{ $formatter->time($occurrence->effective_ends_at) }}</span>
                                            @endif
                                        @endif

                                        @if ($occurrence->doors_at !== null)
                                            <span aria-hidden="true">{{ __('common.separator') }}</span>
                                            {{ __('events.detail.doors_at', ['time' => $formatter->time($occurrence->doors_at)]) }}
                                        @endif
                                    </span>

                                    @if ($occurrence->status !== \App\Enums\OccurrenceStatus::Scheduled)
                                        <span class="text-sm font-semibold text-live">
                                            {{ $occurrence->status->label() }}@if (filled($occurrence->status_note)) <span class="font-normal text-ink-muted">{{ $occurrence->status_note }}</span>@endif
                                        </span>
                                    @endif

                                    @php
                                        $dateTiers = \App\Support\TicketTiers::for($event, $occurrence);
                                        $dateCapacity = \App\Support\Capacity::for($occurrence);
                                    @endphp

                                    @if ($dateCapacity !== null)
                                        <x-capacity-meter :capacity="$dateCapacity" :compact="true" class="max-w-xs" />
                                    @endif

                                    {{-- Il listino di **questa data soltanto**, quando c'è: sostituisce
                                         quello dell'evento, e dirlo qui è l'unico modo perché chi legge
                                         non creda che valga il prezzo scritto più in alto. --}}
                                    @if ($occurrence->ticketTiers->isNotEmpty())
                                        <x-ticket-tiers
                                            :tiers="$dateTiers"
                                            :heading="__('events.tiers.for_this_date')"
                                            :heading-id="'fasce-data-'.$occurrence->getKey()"
                                            level="h3"
                                            class="mt-1"
                                        />
                                    @endif
                                </div>

                                @if ($occurrences->isNotEmpty() && ! ($isPreview ?? false))
                                    @if ($booking = $activeBookings->get($occurrence->id))
                                        <x-button :href="route('tickets.show', $booking)" class="min-h-12">{{ __('ticketing.manage_booking') }}</x-button>
                                    @elseif ($occurrence->booking_enabled && $event->venue?->ticketing_enabled)
                                        <x-button :href="route('tickets.create', $occurrence)" class="min-h-12">{{ __('ticketing.reserve') }}</x-button>
                                    @endif
                                    <div class="flex flex-wrap gap-2">
                                        <a
                                            href="{{ route('events.calendar', ['slug' => $event->slug, 'occurrence' => $occurrence->getKey()]) }}"
                                            class="bg-surface-sunken px-3 py-2 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.12em] text-ink uppercase border-2 border-line transition hover:border-accent"
                                        >
                                            {{ __('common.actions.add_to_calendar') }}
                                        </a>

                                        {{-- Google, Outlook e Yahoo. Prima c'era solo Google:
                                             chi ha un calendario Microsoft — cioe' quasi tutti
                                             gli uffici — poteva solo scaricare il file .ics e
                                             aprirlo a mano. --}}
                                        {{-- La locandina A4 col QR: chi organizza la stampa
                                             per la vetrina e chi passa davanti trova la scheda
                                             sempre aggiornata, invece di un orario stampato a
                                             mano che al primo cambio diventa falso. --}}
                                        <a
                                            href="{{ route('events.poster', ['slug' => $event->slug, 'occurrence' => $occurrence->getKey()]) }}"
                                            class="bg-surface-sunken px-3 py-2 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.12em] text-ink uppercase border-2 border-line transition hover:border-accent"
                                        >
                                            {{ __('common.actions.poster') }}
                                        </a>

                                        @foreach ($calendar->links($occurrence) as $servizio => $indirizzo)
                                            <a
                                                href="{{ $indirizzo }}"
                                                rel="noopener noreferrer"
                                                target="_blank"
                                                class="bg-surface-sunken px-3 py-2 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.12em] text-ink uppercase border-2 border-line transition hover:border-accent"
                                            >
                                                {{ __('common.actions.calendar_'.$servizio) }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($dates->count() > $shown->count())
                        <p class="text-sm text-ink-subtle">
                            {{ trans_choice('events.card.more_dates', $dates->count() - $shown->count(), ['count' => $dates->count() - $shown->count()]) }}
                        </p>
                    @endif
                </section>
            @endif

            {{-- Il cuore della scheda (§15.3): una data sola si salva senza
                 chiedere, più date aprono il selettore, una serie ricorrente
                 offre anche «segui questo evento». --}}
            @if (! ($isPreview ?? false))
            <x-save-event
                :event="$event"
                :occurrences="$occurrences"
                :saved="app(\App\Support\CurrentSaves::class)->all()"
                :following="app(\App\Support\CurrentFollows::class)->has(\App\Enums\FollowableType::Event, (int) $event->getKey())"
            />
            @endif

            @if (filled($event->description))
                <section aria-labelledby="descrizione-evento" class="flex flex-col gap-3">
                    <h2 id="descrizione-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('events.detail.description') }}</h2>

                    {{-- `max-w-prose` è 65 caratteri: il punto oltre il quale
                         rileggere due volte la stessa riga smette di essere un
                         caso e diventa la norma. --}}
                    <div class="flex max-w-prose flex-col gap-3 text-ink-muted">
                        @foreach (preg_split('/\R{2,}/', (string) $event->description) ?: [] as $paragraph)
                            @if (trim($paragraph) !== '')
                                <p>{{ $paragraph }}</p>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- La scheda tecnica dell'evento: apertura porte, durata, età
                 minima. Coppie etichetta/valore, e nessuna sezione se non ce
                 ne sono (§8.6). --}}
            <x-fact-table
                :facts="$event->facts"
                :heading="__('events.detail.facts')"
                heading-id="scheda-evento"
            />

            {{-- Le fasce di prezzo: lo stato è per fascia, non per data. È la
                 risposta a «tutto esaurito o ci sono ancora biglietti?» che
                 `OccurrenceStatus::SoldOut` da solo non sa dare. --}}
            <x-ticket-tiers
                :tiers="$tiers"
                heading-id="fasce-evento"
                :note="__('events.detail.tickets_note')"
            />

            @if ($lineups->isNotEmpty())
                <section aria-labelledby="lineup-evento" class="flex flex-col gap-3">
                    <h2 id="lineup-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('events.detail.lineup') }}</h2>

                    <ul class="flex flex-col gap-2">
                        @foreach ($lineups->sortBy('sort_order') as $act)
                            <li class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-semibold text-ink">
                                    @php $sitoArtista = \App\Support\SafeUrl::href($act->url); @endphp
                                    @if ($sitoArtista !== null)
                                        <a class="hover:underline" href="{{ $sitoArtista }}" rel="noopener noreferrer" target="_blank">{{ $act->name }}</a>
                                    @else
                                        {{ $act->name }}
                                    @endif
                                </span>

                                <x-badge tone="neutral" size="sm">{{ $act->role->label() }}</x-badge>

                                @if ($act->starts_at !== null)
                                    <span class="text-ink-subtle">{{ $formatter->time($act->starts_at) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($event->tags->isNotEmpty())
                <section aria-labelledby="tag-evento" class="flex flex-col gap-3">
                    <h2 id="tag-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('events.detail.tags') }}</h2>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($event->tags as $tag)
                            <a
                                href="{{ route('events.tag', $tag) }}"
                                class="bg-surface px-3 py-1.5 text-sm font-semibold text-ink-muted border-2 border-line transition hover:text-ink hover:border-accent"
                            >
                                #{{ $tag->name }}
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </article>

        {{-- **Su telefono la locandina viene prima del testo.**

             Su schermo largo sta nella colonna di destra, accanto al corpo
             della pagina. In una colonna sola invece finirebbe dopo date,
             descrizione, mappa e accessibilità — in fondo a uno scorrimento
             lungo, cioè dove non la vede nessuno. Ed è il pezzo che porta
             l'informazione che spesso da nessun'altra parte esiste: gli ospiti,
             l'orario esatto, il prezzo scritto dall'organizzatore.

             `order` e non un secondo blocco duplicato: il markup resta uno, e
             l'ordine di lettura per chi usa uno screen reader segue quello
             visivo perché a cambiare è la griglia, non il documento. --}}
        <aside class="flex flex-col gap-6 max-lg:order-first">
            @if ($poster !== null)
                <x-event-poster :event="$event" :set="$poster" />
            @endif

            <section class="flex flex-col gap-3 bg-canvas p-5 border-2 border-line" aria-labelledby="prezzo-evento">
                <h2 id="prezzo-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('events.detail.price') }}</h2>

                <p class="text-card text-ink"><x-price-tag :event="$event" as="text" /></p>

                @if (filled($event->price_notes))
                    <p class="text-sm text-ink-muted">{{ $event->price_notes }}</p>
                @endif

                @if ($event->booking_required)
                    <p class="text-sm font-semibold text-ink-muted">{{ __('events.detail.booking_required') }}</p>
                @endif

                <div class="flex flex-col gap-2">
                    @if ($ticketUrl !== null)
                        <a
                            href="{{ $ticketUrl }}"
                            rel="noopener noreferrer"
                            target="_blank"
                            class="bg-brand px-4 py-2 text-center text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
                        >
                            {{ __('common.actions.buy_tickets') }}
                        </a>
                    @endif

                    @php $prenotazione = \App\Support\SafeUrl::href($event->booking_url); @endphp
                    @if ($prenotazione !== null)
                        <a
                            href="{{ $prenotazione }}"
                            rel="noopener noreferrer"
                            target="_blank"
                            class="bg-surface-sunken px-4 py-2 text-center text-sm font-semibold text-ink border-2 border-line transition hover:border-accent"
                        >
                            {{ __('common.actions.book') }}
                        </a>
                    @endif

                    @if (filled($event->booking_phone))
                        <a
                            href="tel:{{ $event->booking_phone }}"
                            class="bg-surface-sunken px-4 py-2 text-center text-sm font-semibold text-ink border-2 border-line transition hover:border-accent"
                        >
                            {{ __('common.actions.call') }} {{ $event->booking_phone }}
                        </a>
                    @endif
                </div>

                <dl class="flex flex-col gap-1 text-sm text-ink-muted">
                    @if (filled($event->age_restriction))
                        <div class="flex gap-2">
                            <dt class="sr-only">{{ __('events.detail.age') }}</dt>
                            <dd>{{ __('events.detail.age_restriction', ['value' => $event->age_restriction]) }}</dd>
                        </div>
                    @endif

                    @if ($event->is_outdoor)
                        <div class="flex gap-2">
                            <dt class="sr-only">{{ __('events.detail.place_kind') }}</dt>
                            <dd>{{ __('events.detail.outdoor') }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            @if ($venue !== null)
                <section class="flex flex-col gap-4 border-2 border-line bg-canvas p-5" aria-labelledby="luogo-evento">
                    <h2 id="luogo-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">
                        {{ __('events.detail.where') }}
                    </h2>

                    <div class="flex flex-col gap-1.5">
                        <a
                            class="font-display text-[1.125rem] leading-tight font-extrabold tracking-[-0.02em] uppercase hover:text-accent"
                            href="{{ route('venues.show', $venue) }}"
                        >{{ $venue->name }}</a>

                        <p class="m-0 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-ink-subtle uppercase">
                            {{ collect([$venue->type?->label(), $venue->zone ?: $venue->municipality])->filter()->implode(' '.__('common.separator').' ') }}
                        </p>
                    </div>

                    {{-- **La presentazione del locale, scritta dal locale.**
                         Chi legge la scheda di una serata sta anche decidendo
                         se gli piace il posto: due righe di chi lo gestisce
                         dicono più di un indirizzo. La scrive il referente in
                         `/gestione` e vale per tutti i suoi eventi — non si
                         ripete a ogni serata. --}}
                    @if (filled($venue->short_description))
                        <p class="m-0 text-[0.875rem] leading-[1.55] text-ink-muted">{{ $venue->short_description }}</p>
                    @endif

                    <p class="m-0 text-sm leading-[1.5] text-ink-muted">
                        {{ $venue->address }}@if (filled($venue->address_extra))<br>{{ $venue->address_extra }}@endif<br>
                        {{ $venue->postal_code }} {{ $venue->municipality }}
                    </p>

                    {{-- I contatti del locale: chi vuole chiedere un posto o
                         sapere se c'è ancora spazio scrive o telefona, e
                         cercarli altrove significa perderli. --}}
                    @if (filled($venue->phone) || filled($venue->email) || filled($venue->website))
                        <div class="flex flex-wrap gap-x-4 gap-y-1 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.12em] uppercase">
                            @if (filled($venue->phone))
                                <a class="hover:text-accent" href="tel:{{ preg_replace('/\s+/', '', $venue->phone) }}">{{ $venue->phone }}</a>
                            @endif

                            @if (filled($venue->email))
                                <a class="hover:text-accent" href="mailto:{{ $venue->email }}">{{ $venue->email }}</a>
                            @endif

                            @php $sitoLocale = \App\Support\SafeUrl::href($venue->website); @endphp
                            @if ($sitoLocale !== null)
                                <a class="hover:text-accent" href="{{ $sitoLocale }}" rel="noopener noreferrer" target="_blank">{{ __('venues.detail.website') }}</a>
                            @endif
                        </div>
                    @endif

                    <x-venue-map :venue="$venue" />
                </section>

                {{-- «Come arrivare» e accessibilità stanno sul locale, non
                     sull'evento: cambiano col luogo e non con la serata. Qui
                     si mostrano perché è dove servono — mentre si decide se
                     andarci. --}}
                <x-transit-guide
                    :transit="$venue->transit"
                    heading-id="come-arrivare-evento"
                    class="bg-canvas p-5 border-2 border-line"
                />

                <x-accessibility-list
                    :accessibility="$venue->accessibility"
                    heading-id="accessibilita-evento"
                    class="bg-canvas p-5 border-2 border-line"
                />

                <x-fact-table
                    :facts="$venue->info"
                    :heading="__('venues.detail.info')"
                    heading-id="info-locale-evento"
                    class="bg-canvas p-5 border-2 border-line"
                />
            @endif

            <x-external-links :links="$event->external_links" />

            <section class="flex flex-col gap-3" aria-labelledby="condividi-evento">
                <h2 id="condividi-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('common.actions.share') }}</h2>

                <x-share-links :url="$shareUrl" :title="$event->title" />

                <a
                    href="{{ route('events.report', ['slug' => $event->slug]) }}"
                    class="self-start text-sm font-semibold text-ink-muted underline hover:text-ink"
                >
                    {{ __('common.actions.report') }}
                </a>
            </section>
        </aside>
    </div>

    {{-- Le due sezioni in fondo si incolonnano con il corpo, non con il bordo
         dello schermo. La scheda di un evento è un documento e ha un asse
         verticale solo: il titolo, la descrizione e «altri eventi» devono
         partire tutti dalla stessa riga verticale. La home fa il contrario, e
         ha ragione — lì le sezioni sono un tabellone e vanno a filo — ma è
         un'altra pagina, con un'altra natura.

         Il caso è nato rendendo questa scheda a tutta larghezza: prima
         ereditava il rientro dal contenitore del layout e la questione non si
         poneva. --}}
    @if ($atVenue->isNotEmpty() && $venue !== null)
        <section class="mx-auto mt-section w-full max-w-content px-gutter" aria-labelledby="sezione-stesso-locale">
            <x-section-heading
                id="sezione-stesso-locale"
                :title="__('events.sections.same_venue')"
                tone="neutral"
                :href="route('venues.show', $venue)"
            />

            <x-event-grid :occurrences="$atVenue" :show-venue="false" />
        </section>
    @endif

    @if ($related->isNotEmpty())
        <section class="mx-auto mt-section w-full max-w-content px-gutter" aria-labelledby="sezione-simili">
            <x-section-heading
                id="sezione-simili"
                :title="__('events.sections.similar')"
                tone="neutral"
                :href="$event->category !== null ? route('events.category', $event->category) : route('events.index')"
            />

            <x-event-grid :occurrences="$related" />
        </section>
    @endif
</x-layouts.app>
