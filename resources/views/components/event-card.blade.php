{{--
    La card di un'occorrenza: lo stesso componente in home, nelle liste, nella
    mappa, nella scheda del locale e nei risultati di ricerca (§11.4).

    **Il contesto lo passa chi disegna la sezione, non lo deduce la card.**
    "In corso", "inizia tra poco" e "stasera" sono finestre di
    `App\Queries\EventOccurrenceQuery` (§8): la sezione sa da quale finestra
    arrivano le occorrenze che ha in mano e lo dichiara qui. Se la card
    ricalcolasse da sé se un evento è in corso, in sei mesi esisterebbero due
    definizioni diverse e le due risposte divergerebbero sulla stessa pagina.

    **Perché non c'è la locandina** (D46). Nel riferimento adottato la card è
    tipografica: numero d'ordine, categoria, titolo grande, luogo, ora, prezzo.
    Non è un'omissione — è ciò che permette alle card di stare in una griglia
    a due pixel di distanza l'una dall'altra e comportarsi come un tabellone.
    Le fotografie restano dove pesano: sulla scheda dell'evento e nel riquadro
    in evidenza della prima schermata.
--}}
@props([
    'occurrence',
    /* upcoming (default) · ongoing · starting_soon · tonight · today · tomorrow */
    'context' => 'upcoming',
    'href' => null,
    'showVenue' => true,
    /* Il numero d'ordine nella sezione ("01", "02"…). La sezione lo conosce,
       la card no: passarlo è ciò che rende la griglia un elenco numerato. */
    'index' => null,
    'level' => 'h3',
    /* Il valore di `rel` sul collegamento al titolo. Serve a una cosa sola, ma
       obbligatoria: una card sponsorizzata deve dichiarare `rel="sponsored"`,
       che è come si segnala a un motore di ricerca un collegamento pagato.
       Ometterlo su un contenuto a pagamento è una violazione delle linee guida
       di Google, non una svista di stile. */
    'rel' => null,
])

@php
    $formatter = app(\App\Support\DateFormatter::class);

    $event = $occurrence->event;
    $venue = $occurrence->effectiveVenue();
    $category = $event->category;
    $status = $occurrence->status;

    $url = $href ?? (\Illuminate\Support\Facades\Route::has('events.show')
        ? \App\Support\EventUrl::occurrence($occurrence)
        : null);

    $isScheduled = $status === \App\Enums\OccurrenceStatus::Scheduled;

    /* Una riga sola di stato, e solo quando dice qualcosa che la data non dice
       già: una data annullata, un tutto esaurito, un evento in corso adesso. */
    /* «In corso adesso» è l'unico stato che pulsa: è la sola informazione
       della card che scade mentre la si guarda. Gli altri stati sono fatti
       compiuti — annullato, esaurito — e un fatto compiuto non lampeggia. */
    $stateIsLive = $context === 'ongoing' && $status === \App\Enums\OccurrenceStatus::Scheduled;

    $stateLabel = match (true) {
        $status === \App\Enums\OccurrenceStatus::Cancelled => __('events.badge.cancelled'),
        $status === \App\Enums\OccurrenceStatus::SoldOut => __('events.badge.sold_out'),
        $status === \App\Enums\OccurrenceStatus::Postponed => __('events.badge.postponed'),
        $status === \App\Enums\OccurrenceStatus::Moved => __('events.badge.moved'),
        $context === 'ongoing' => __('events.badge.ongoing'),
        $context === 'starting_soon' => __('events.badge.starting_soon', ['countdown' => $formatter->countdown($occurrence->starts_at)]),
        default => null,
    };

    $whenLabel = $occurrence->is_all_day
        ? $formatter->day($occurrence->business_date).' '.__('common.separator').' '.__('events.badge.all_day')
        : $formatter->dayAndTime($occurrence->business_date, $occurrence->starts_at);

    $place = match (true) {
        $venue !== null => $venue->name,
        is_array($event->custom_location) && filled($event->custom_location['name'] ?? null) => $event->custom_location['name'],
        default => null,
    };

    $area = $venue?->zone ?: $venue?->municipality;

    $venueLine = collect([$place ?? __('events.card.no_venue'), $area])->filter()->implode(' '.__('common.separator').' ');

    $highlight = filled($occurrence->highlight) ? $occurrence->highlight : null;

    /* Posti rimasti: `null` finché nessuno li ha dichiarati (§8.6). La barra
       compare solo quando c'è un numero vero — una barra piena al 100% perché
       non sappiamo niente direbbe una cosa falsa. */
    $capacity = \App\Support\Capacity::for($occurrence);

    $distance = $occurrence->getAttribute(\App\Queries\EventOccurrenceQuery::DISTANCE_ALIAS);

    $distanceLabel = $distance === null
        ? null
        : ((float) $distance >= 1000
            ? __('common.units.km', ['value' => \Illuminate\Support\Number::format((float) $distance / 1000, maxPrecision: 1, locale: app()->getLocale())])
            : __('common.units.meters', ['value' => \Illuminate\Support\Number::format(round((float) $distance), locale: app()->getLocale())]));
@endphp

<article {{ $attributes->class([
    'event-card group relative flex h-full min-h-[252px] flex-col gap-[13px] overflow-hidden bg-canvas px-[22px] py-5 pl-[26px] transition-transform duration-300 ease-out-soft',
    'hover:-translate-y-[3px] hover:bg-accent/[0.055]' => $url !== null,
]) }}>
    {{-- La lastra: entra da sinistra al passaggio del puntatore. È l'unico
         movimento della card, e sostituisce l'ombra che questo sistema non
         usa. --}}
    <span aria-hidden="true" class="absolute inset-y-0 left-0 w-0 bg-accent transition-[width] duration-300 ease-[cubic-bezier(.76,0,.24,1)] group-hover:w-[7px]"></span>

    <div class="flex items-start justify-between gap-2.5">
        <span class="font-display text-[0.625rem] leading-none font-extrabold tracking-[0.1em] text-ink-subtle transition-colors group-hover:text-accent">
            {{ $index !== null ? str_pad((string) $index, 2, '0', STR_PAD_LEFT) : '' }}
        </span>

        @if ($category !== null)
            <span class="border-2 border-line px-2 py-[5px] font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] whitespace-nowrap text-ink-muted uppercase transition-colors group-hover:border-accent group-hover:bg-accent group-hover:text-on-accent">
                {{ $category->name }}
            </span>
        @endif
    </div>

    {{--
        **Perché il titolo non ha la copia fantasma del riferimento.** Lì, al
        passaggio, una seconda copia in giallo-verde ritagliata al 54%
        dell'altezza scivolava in diagonale sotto al titolo. Funziona con
        titoli di una riga, che è quello che aveva la demo; con un titolo vero
        su due o tre righe quel 54% taglia in mezzo al blocco — non in mezzo
        alla prima riga — e la copia si accavalla al testo invece di
        affiancarlo. Restano lo scorrimento del titolo e la lastra laterale,
        che di quel movimento sono la parte leggibile.
    --}}
    <div class="relative pt-0.5">
        <{{ $level }} class="relative m-0 font-display text-[clamp(1.188rem,1.55vw,1.563rem)] leading-[1.03] font-extrabold tracking-[-0.025em] text-balance text-ink uppercase transition-transform duration-300 ease-[cubic-bezier(.76,0,.24,1)] group-hover:translate-x-[9px]">
            @if ($url !== null)
                <a
                    href="{{ $url }}"
                    @if ($rel !== null) rel="{{ $rel }}" @endif
                    class="after:absolute after:inset-0 after:content-['']"
                >{{ $event->title }}</a>
            @else
                {{ $event->title }}
            @endif
        </{{ $level }}>
    </div>

    <div class="mb-auto flex flex-col gap-0.5">
        @if ($showVenue)
            <span class="text-[0.781rem] leading-[1.35] text-ink-subtle">{{ $venueLine }}</span>
        @endif

        <time
            datetime="{{ $occurrence->is_all_day ? $formatter->isoDay($occurrence->business_date) : $formatter->iso($occurrence->starts_at) }}"
            class="font-display text-[0.688rem] leading-[1.3] font-extrabold tracking-[0.1em] text-ink/90 uppercase transition-[letter-spacing] duration-300 group-hover:tracking-[0.14em]"
        >
            {{ $whenLabel }}
        </time>
    </div>

    @if ($stateLabel !== null || $highlight !== null || $distanceLabel !== null)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] uppercase">
            @if ($stateLabel !== null)
                <span class="inline-flex items-center gap-1.5 text-accent">
                    @if ($stateIsLive)
                        <span aria-hidden="true" class="size-1.5 shrink-0 bg-current pulse-dot"></span>
                    @endif
                    {{ $stateLabel }}
                </span>
            @endif

            @if ($highlight !== null)
                <span class="text-ink">{{ $highlight }}</span>
            @endif

            @if ($distanceLabel !== null)
                <span class="text-ink-subtle">{{ __('events.card.distance', ['distance' => $distanceLabel]) }}</span>
            @endif
        </div>
    @endif

    @if ($capacity !== null && ! $capacity->isSoldOut() && $capacity->percentSold() !== null)
        <div class="flex flex-col gap-1.5 pt-1">
            <div class="h-[3px] overflow-hidden bg-ink/[0.16]">
                <div class="h-full origin-left bg-accent" style="width: {{ $capacity->percentSold() }}%"></div>
            </div>
            <span class="font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-accent uppercase">
                {{ trans_choice('events.capacity.left', $capacity->left, ['count' => $capacity->left]) }}
            </span>
        </div>
    @endif

    <div class="flex items-center justify-between gap-2.5 border-t-2 border-line pt-3">
        <span class="font-display text-base leading-none font-extrabold tracking-[-0.01em]">
            <x-price-tag :event="$event" />
        </span>

        <div class="flex items-center gap-2">
            {{-- Il cuore (§15.1). Sta **sopra** l'ancora che copre tutta la
                 card (`z-10`), altrimenti il click aprirebbe l'evento invece
                 di salvarlo. Una card è una data sola: qui non c'è niente da
                 scegliere, e infatti non si chiede niente (§15.3). --}}
            @if ($isScheduled)
                <x-save-heart
                    :occurrence="$occurrence"
                    :saved="app(\App\Support\CurrentSaves::class)->has((int) $occurrence->getKey())"
                />
            @endif

            <span aria-hidden="true" class="grid size-[30px] place-items-center text-ink-subtle transition-[transform,color] duration-300 ease-[cubic-bezier(.76,0,.24,1)] group-hover:translate-x-1.5 group-hover:text-accent">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square">
                    <path d="M5 12h14M13 5l7 7-7 7"></path>
                </svg>
            </span>
        </div>
    </div>
</article>
