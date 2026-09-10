<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\DTOs\EventFilters;
use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Search\EventFinder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Sceglie quali campagne mostrare in una collocazione.
 *
 * Tre cose, in quest'ordine.
 *
 * **1. Il tetto.** Ogni collocazione ne ammette un numero fisso
 * (`SponsorshipPlacement::limit()`). Vendere non deve poter cambiare l'aspetto
 * del prodotto: se domani si firmano sei contratti per la pagina iniziale, ne
 * compare comunque uno per volta e gli altri entrano in rotazione.
 *
 * **2. La priorità.** Chi ha pagato di più — o è stato promesso prima — sta
 * davanti. È un numero, non un prezzo: il prezzo sta nel contratto, qui c'è
 * solo l'ordine.
 *
 * **3. La rotazione.** A parità di priorità le campagne si alternano, e
 * l'alternanza è **deterministica dentro il minuto**. Non è un dettaglio: le
 * pagine pubbliche stanno in cache per un minuto (`page_cache.ttl_minutes`), e
 * una rotazione casuale a ogni richiesta produrrebbe una pagina diversa da
 * quella salvata — cioè, in pratica, sempre la stessa per tutto il minuto,
 * scelta a caso. Legandola al minuto la rotazione avviene davvero, e chi
 * ricarica dentro lo stesso minuto vede la stessa pagina che ha visto il
 * visitatore prima di lui.
 *
 * Con una campagna sola per collocazione — il caso normale all'inizio — tutto
 * questo non fa niente di visibile, ed è giusto così.
 *
 * **E, solo per chi è autenticato, l'affinità.** A parità di priorità, le
 * campagne ricevono il peso delle scelte esplicite e dei salvataggi recenti
 * (`BannerAffinity`), senza superare le categorie nascoste. Vale solo per gli autenticati perché le
 * loro pagine non passano dalla full-page cache (`CachePage` le esclude): per
 * gli anonimi la pagina è condivisa, e una scelta personalizzata finita in
 * cache verrebbe servita a tutti. Per loro non cambia niente.
 */
final class SponsorshipSelector
{
    /**
     * Le campagne da mostrare in una collocazione, già ordinate.
     *
     * @return Collection<int, Sponsorship>
     */
    public function forPlacement(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null, ?User $user = null, ?EventFilters $filters = null): Collection
    {
        $adesso = $now ?? CarbonImmutable::now('UTC');

        /** @var Collection<int, Sponsorship> $candidate */
        $candidate = Sponsorship::query()
            ->visible($adesso)
            ->where('city_id', $city->getKey())
            ->where('placement', $placement)
            ->with(['event.venue', 'event.category', 'event.tags'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        if ($candidate->isEmpty()) {
            return $candidate;
        }

        if ($placement === SponsorshipPlacement::HomeHero || ($placement === SponsorshipPlacement::ListTop && $filters !== null)) {
            $eligibleIds = EventOccurrenceQuery::for($city)
                ->forEvents($candidate->pluck('event_id')->unique()->values()->all())
                ->promotable()->eventIds();
            $candidate = $candidate->whereIn('event_id', $eligibleIds)->values();
        }

        $grantEventIds = $candidate->whereNotNull('sponsorship_grant_id')->pluck('event_id')->unique()->values()->all();
        if ($grantEventIds !== []) {
            $upcomingIds = EventOccurrenceQuery::for($city)->forEvents($grantEventIds)->upcoming()->eventIds();
            $candidate = $candidate->filter(fn (Sponsorship $campaign): bool => $campaign->sponsorship_grant_id === null || in_array($campaign->event_id, $upcomingIds, true))->values();
        }

        /* L'utente si legge qui e non arriva dai controller: così ogni punto
           di chiamata — compresi quelli futuri — ha la personalizzazione senza
           doversene ricordare, e fuori da una richiesta web (code, comandi)
           il guard risponde `null` e non cambia niente. */
        $utente = $user ?? Auth::user();
        $weighted = app(BannerAffinity::class)->apply($candidate, $city, $utente instanceof User ? $utente : null, []);

        // Prefer the archive's actual results, across all pages. Commercial
        // priority and rotation apply within that pool; use the general pool
        // only when no eligible campaign matches the selected filters.
        if ($filters !== null && $filters->activeCount() > 0 && $weighted->isNotEmpty()) {
            $matchingIds = app(EventFinder::class)->query($city, $filters)
                ->forEvents($weighted->pluck('event_id')->unique()->values()->all())
                ->promotable()->eventIds();
            $matching = $weighted->whereIn('event_id', $matchingIds)->values();
            if ($matching->isNotEmpty()) {
                $weighted = $matching;
            }
        }

        return $this->rotate($weighted, $placement->limit(), $adesso);
    }

    /**
     * La prima campagna di una collocazione, o `null`.
     *
     * Le collocazioni con tetto uno — oggi tutte — la usano al posto di
     * `forPlacement()->first()`, che direbbe la stessa cosa in modo più
     * rumoroso.
     */
    public function first(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null, ?User $user = null, ?EventFilters $filters = null): ?Sponsorship
    {
        return $this->forPlacement($city, $placement, $now, $user, $filters)->first();
    }

    /** @param array<string, mixed> $context */
    public function banner(City $city, ?string $excludeEvent = null, ?User $user = null, array $context = []): ?Sponsorship
    {
        $candidates = Sponsorship::query()->visible()->where('city_id', $city->id)
            ->when($excludeEvent, fn ($q) => $q->whereHas('event', fn ($event) => $event->where('slug', '!=', $excludeEvent)))
            ->with(['event.venue', 'event.category', 'event.media', 'event.tags', 'grant'])
            ->orderByDesc('priority')->orderBy('id')->get()->unique('event_id')->values();

        // Eligibility and commercial priority stay unchanged; preferences adjust the rotation weight only.
        $weighted = app(BannerAffinity::class)->apply($candidates, $city, $user, $context);
        $filters = EventFilters::fromArray($context);
        if ($filters->activeCount() > 0 && $weighted->isNotEmpty()) {
            $ids = app(EventFinder::class)->query($city, $filters)
                ->forEvents($weighted->pluck('event_id')->unique()->values()->all())->promotable()->eventIds();
            $matching = $weighted->whereIn('event_id', $ids)->values();
            if ($matching->isNotEmpty()) {
                $weighted = $matching;
            }
        }

        return $this->rotate($weighted, 1, CarbonImmutable::now('UTC'))->first();
    }

    /**
     * Ruota di un passo per minuto, dentro ogni gruppo di pari priorità.
     *
     * @param  Collection<int, Sponsorship>  $candidate
     * @return Collection<int, Sponsorship>
     */
    private function rotate(Collection $candidate, int $limit, CarbonImmutable $now): Collection
    {
        /* Il passo cambia ogni minuto ed è lo stesso per tutti in quel minuto:
           è ciò che rende la rotazione compatibile con la pagina in cache. */
        $passo = (int) $now->format('YmdHi');

        return $candidate
            ->groupBy(fn (Sponsorship $sponsorship): int => (int) $sponsorship->priority)
            ->sortKeysDesc()
            ->flatMap(fn (Collection $gruppo): Collection => $this->ruotaPerPeso($gruppo, $passo))
            ->take($limit)
            ->values();
    }

    /**
     * La rotazione dentro un gruppo di pari priorità, **proporzionale al
     * peso**.
     *
     * **Perché il peso e non solo la priorità.** La priorità è un ordine: chi
     * ce l'ha più alta sta davanti. Nella collocazione in apertura, che ammette
     * una sola campagna, questo significa che la più alta prende il cento per
     * cento delle apparizioni e le altre non compaiono mai — anche se hanno
     * pagato. Fra due clienti si può vendere solo «il primo posto», e una
     * volta venduto non c'è più niente da vendere.
     *
     * Il peso vende una **quota**: peso 3 contro peso 1 vuol dire che su
     * quattro minuti la prima compare tre volte e la seconda una. Nessuna
     * resta a zero, e lo spazio si può vendere a più di un cliente.
     *
     * **Come si ottiene senza il caso.** Si costruisce una ruota in cui ogni
     * campagna compare tante volte quanto pesa, e il minuto corrente decide da
     * dove si comincia a leggerla. Il risultato è deterministico dentro il
     * minuto — che è ciò che permette alla pagina in cache di restare valida
     * per tutti — e proporzionale sul lungo periodo, che è ciò che si è
     * venduto.
     *
     * Una funzione casuale darebbe la stessa proporzione e romperebbe la
     * cache: la pagina salvata conterrebbe una scelta a caso valida per tutto
     * il minuto, cioè in pratica sempre la stessa.
     *
     * @param  Collection<int, Sponsorship>  $gruppo
     * @return Collection<int, Sponsorship>
     */
    private function ruotaPerPeso(Collection $gruppo, int $passo): Collection
    {
        $quante = $gruppo->count();

        if ($quante < 2) {
            return $gruppo->values();
        }

        $ordinate = $gruppo->values();

        /*
         * La ruota. Un peso a zero o negativo varrebbe «mai», che non è ciò
         * che si aspetta chi scrive zero in un campo chiamato peso: vale uno,
         * come il valore predefinito.
         */
        $ruota = [];

        foreach ($ordinate as $indice => $campagna) {
            $peso = max(1, (int) $campagna->weight);

            for ($i = 0; $i < $peso; $i++) {
                $ruota[] = $indice;
            }
        }

        $giri = count($ruota);
        $inizio = $passo % $giri;

        /*
         * Si legge la ruota dall'inizio scelto e si tengono le campagne nel
         * loro ordine di prima apparizione, saltando i doppioni: chi pesa di
         * più ha più occasioni di uscire per prima, ma quando escono insieme
         * nessuna compare due volte nella stessa pagina.
         */
        $ordine = [];

        for ($i = 0; $i < $giri; $i++) {
            $indice = $ruota[($inizio + $i) % $giri];

            if (! in_array($indice, $ordine, strict: true)) {
                $ordine[] = $indice;
            }
        }

        return collect($ordine)
            ->map(fn (int $indice): Sponsorship => $ordinate[$indice])
            ->values();
    }
}
