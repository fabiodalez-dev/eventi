{{--
    La card di un'occorrenza: lo stesso componente in home, nelle liste, nella
    mappa, nella scheda del locale e nei risultati di ricerca (§11.4).

    **Il contesto lo passa chi disegna la sezione, non lo deduce la card.**
    "In corso", "inizia tra poco" e "stasera" sono finestre di
    `App\Queries\EventOccurrenceQuery` (§8): la sezione sa da quale finestra
    arrivano le occorrenze che ha in mano e lo dichiara qui. Se la card
    ricalcolasse da sé se un evento è in corso, in sei mesi esisterebbero due
    definizioni diverse e le due risposte divergerebbero sulla stessa pagina.
--}}
@props([
    'occurrence',
    /* upcoming (default) · ongoing · starting_soon · tonight · today · tomorrow */
    'context' => 'upcoming',
    'href' => null,
    'showVenue' => true,
    /* La prima riga di card è sopra la piega: la sua locandina non va rinviata */
    'eager' => false,
    'level' => 'h3',
])

@php
    $formatter = app(\App\Support\DateFormatter::class);

    $event = $occurrence->event;
    $venue = $event->venue;
    $category = $event->category;
    $status = $occurrence->status;

    /* La rotta pubblica dell'evento arriva con il sito pubblico: finché non
       esiste, la card resta leggibile e non è un link morto. */
    $url = $href ?? (\Illuminate\Support\Facades\Route::has('events.show')
        ? route('events.show', $event)
        : null);

    /* Un solo posto sa dove sta la locandina: card, scheda, dati strutturati e
       anteprime social devono mostrare la stessa immagine. */
    $poster = \App\Support\Poster::imageSet($event);

    $isScheduled = $status === \App\Enums\OccurrenceStatus::Scheduled;

    /* Un solo badge di stato in alto a destra: quello temporale se la data è
       confermata, altrimenti quello che dice perché non lo è. */
    $stateBadge = match (true) {
        $status === \App\Enums\OccurrenceStatus::Cancelled => ['tone' => 'alert', 'label' => __('events.badge.cancelled'), 'dot' => false],
        $status === \App\Enums\OccurrenceStatus::SoldOut => ['tone' => 'muted', 'label' => __('events.badge.sold_out'), 'dot' => false],
        $status === \App\Enums\OccurrenceStatus::Postponed => ['tone' => 'muted', 'label' => __('events.badge.postponed'), 'dot' => false],
        $status === \App\Enums\OccurrenceStatus::Moved => ['tone' => 'muted', 'label' => __('events.badge.moved'), 'dot' => false],
        $context === 'ongoing' => ['tone' => 'live', 'label' => __('events.badge.ongoing'), 'dot' => 'pulse'],
        $context === 'starting_soon' => ['tone' => 'soon', 'label' => __('events.badge.starting_soon', ['countdown' => $formatter->countdown($occurrence->starts_at)]), 'dot' => false],
        $context === 'tonight' => ['tone' => 'brand', 'label' => __('events.badge.tonight', ['time' => $formatter->time($occurrence->starts_at)]), 'dot' => false],
        $context === 'today' => ['tone' => 'brand', 'label' => __('events.badge.today', ['time' => $formatter->time($occurrence->starts_at)]), 'dot' => false],
        $context === 'tomorrow' => ['tone' => 'neutral', 'label' => __('events.badge.tomorrow', ['time' => $formatter->time($occurrence->starts_at)]), 'dot' => false],
        default => null,
    };

    /* Riga della data, sempre presente: il badge dice "stasera", questa dice
       quale sera. Per un after che comincia all'una di notte la giornata è
       quella della serata (`business_date`) e l'orario resta l'01:30. */
    $whenLabel = $occurrence->is_all_day
        ? $formatter->day($occurrence->business_date).' '.__('common.separator').' '.__('events.badge.all_day')
        : $formatter->dayAndTime($occurrence->business_date, $occurrence->starts_at);

    $place = match (true) {
        $venue !== null => $venue->name,
        is_array($event->custom_location) && filled($event->custom_location['name'] ?? null) => $event->custom_location['name'],
        default => null,
    };

    $area = $venue?->municipality;

    /* `distance_m` esiste solo dopo `near()`: è un dato della query, non un
       calcolo della vista. */
    $distance = $occurrence->getAttribute(\App\Queries\EventOccurrenceQuery::DISTANCE_ALIAS);

    $distanceLabel = $distance === null
        ? null
        : ((float) $distance >= 1000
            ? __('common.units.km', ['value' => \Illuminate\Support\Number::format((float) $distance / 1000, maxPrecision: 1, locale: app()->getLocale())])
            : __('common.units.meters', ['value' => \Illuminate\Support\Number::format(round((float) $distance), locale: app()->getLocale())]));
@endphp

<article {{ $attributes->class([
    'group relative flex h-full flex-col overflow-hidden rounded-card bg-surface shadow-card ring-1 ring-line transition duration-300 ease-out-soft',
    'hover:-translate-y-0.5 hover:shadow-lift hover:ring-line-strong' => $url !== null,
    'opacity-75' => ! $isScheduled,
]) }}>
    {{-- Locandina 3:4. Il rapporto è dichiarato dal contenitore e le misure
         dall'immagine: niente salto di layout mentre carica, niente rettangolo
         morto se la locandina non c'è (§11.11). --}}
    <div class="relative aspect-[3/4] w-full overflow-hidden poster-placeholder">
        @if ($poster !== null)
            <x-media-image
                :set="$poster"
                :alt="__('events.card.poster_alt', ['title' => $event->title])"
                width="800"
                height="1067"
                sizes="(min-width: 1024px) 280px, (min-width: 640px) 45vw, 90vw"
                :eager="$eager"
                class="size-full object-cover transition duration-500 ease-out-soft group-hover:scale-[1.03]"
            />
        @else
            <span class="absolute inset-0 flex items-center justify-center p-4 text-center text-eyebrow text-on-brand-soft/70">
                {{ $event->title }}
            </span>
        @endif

        {{-- I due badge vanno a capo invece di uscire dalla locandina: la
             categoria può essere lunga ("Comunità e assemblee") e il conto alla
             rovescia cresce fino a "2 h 11 min". --}}
        <div class="absolute inset-x-2 top-2 flex flex-wrap items-start justify-between gap-1.5">
            @if ($category !== null)
                <x-badge tone="neutral" size="sm" :dot="true" :dot-color="$category->color" class="bg-surface/90 backdrop-blur">
                    {{ $category->name }}
                </x-badge>
            @endif

            @if ($stateBadge !== null)
                <x-badge :tone="$stateBadge['tone']" size="sm" :dot="$stateBadge['dot']" class="ml-auto shadow-card">
                    {{ $stateBadge['label'] }}
                </x-badge>
            @endif
        </div>
    </div>

    <div class="flex flex-1 flex-col gap-1.5 p-card">
        <{{ $level }} class="text-card text-ink line-clamp-title">
            @if ($url !== null)
                {{-- L'ancora copre tutta la card: il titolo resta il testo del
                     link, che è ciò che legge uno screen reader. --}}
                <a href="{{ $url }}" class="after:absolute after:inset-0 after:content-['']">{{ $event->title }}</a>
            @else
                {{ $event->title }}
            @endif
        </{{ $level }}>

        <p class="text-sm text-ink-muted">
            <time datetime="{{ $occurrence->is_all_day ? $formatter->isoDay($occurrence->business_date) : $formatter->iso($occurrence->starts_at) }}">
                {{ $whenLabel }}
            </time>
        </p>

        @if ($showVenue && ($place !== null || $area !== null))
            <p class="truncate text-sm text-ink-subtle">
                {{ $place ?? __('events.card.no_venue') }}@if ($area) <span aria-hidden="true">{{ __('common.separator') }}</span> {{ $area }}@endif
            </p>
        @endif

        <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
            <x-price-tag :event="$event" />

            @if ($event->is_outdoor)
                <x-badge tone="neutral" size="sm">{{ __('events.badge.outdoor') }}</x-badge>
            @endif

            @if ($distanceLabel !== null)
                <span class="text-xs text-ink-subtle">{{ __('events.card.distance', ['distance' => $distanceLabel]) }}</span>
            @endif

            {{-- Il cuore (§15.1). Sta **sopra** l'ancora che copre tutta la
                 card (`z-10`), altrimenti il click aprirebbe l'evento invece
                 di salvarlo. Una card è una data sola: qui non c'è niente da
                 scegliere, e infatti non si chiede niente (§15.3). --}}
            @if ($isScheduled)
                <x-save-heart
                    class="ml-auto"
                    :occurrence="$occurrence"
                    :saved="app(\App\Support\CurrentSaves::class)->has((int) $occurrence->getKey())"
                />
            @endif
        </div>
    </div>
</article>
