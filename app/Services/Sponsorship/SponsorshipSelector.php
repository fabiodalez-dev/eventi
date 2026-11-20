<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\DTOs\EventFilters;
use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Account\ContentPreferences;
use App\Services\Search\EventFinder;
use App\Services\Sponsorship\Delivery\DeliveryPlan;
use App\Services\Sponsorship\Delivery\DeliveryPlanBuilder;
use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\HashDraw;
use App\Services\Sponsorship\Delivery\ViewerMultiplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Sceglie quale campagna mostrare in una collocazione.
 *
 * È la porta d'ingresso di sempre (`forPlacement`, `first`, `banner`), ma
 * dentro non ruota più per minuto: chiede al **piano di consegna**
 * (`DeliveryPlanBuilder`) chi può comparire e con quale quota, poi estrae.
 *
 * **1. Il tetto.** Ogni collocazione ne mostra una (`SponsorshipPlacement::limit()`):
 * vendere non cambia l'aspetto del prodotto.
 *
 * **2. La priorità.** È una fascia: la più alta prende ciò che le serve, il
 * resto scende alla fascia sotto e alla fine al contenuto redazionale.
 *
 * **3. La quota.** Dentro la fascia le unità a visualizzazioni prendono la
 * quota che chiede il loro ritmo, quelle a periodo si dividono il resto in
 * proporzione al peso. L'unità è l'abilitazione, non la singola riga: un
 * locale con quaranta eventi non vale quaranta posti.
 *
 * **4. Il caso.** L'estrazione usa `Draw`: in produzione il caso vero, nei
 * test un seme. L'apertura della home fa eccezione: sta dentro lo scheletro
 * della pagina in cache, quindi la scelta è la stessa per tutti dentro la
 * casella lunga quanto la cache (`HashDraw`). Card e cima dell'elenco, per
 * gli anonimi, non passano da qui alla richiesta: sono buchi nella copia
 * salvata, riempiti dal piano a ogni visita (`SponsorSlots`).
 *
 * **E, solo per chi è collegato, l'affinità.** Le scelte esplicite e i
 * salvataggi recenti (`BannerAffinity`) moltiplicano la quota dell'unità senza
 * toccare la priorità; le categorie nascoste e i locali nascosti spariscono.
 * Le pagine dei collegati non passano dalla cache, quindi una scelta
 * personale non finisce a nessun altro.
 */
final class SponsorshipSelector
{
    private ?Draw $draw = null;

    public function __construct(
        private readonly DeliveryPlanBuilder $plans,
        private readonly ViewerMultiplier $viewerMultiplier,
    ) {}

    /**
     * Lo stesso selettore con un caso scelto da chi chiama: serve ai test,
     * che fissano un seme, e a chi deve ripetere una scelta.
     */
    public function withDraw(Draw $draw): self
    {
        $clone = clone $this;
        $clone->draw = $draw;

        return $clone;
    }

    /**
     * Le campagne da mostrare in una collocazione: una, oppure nessuna quando
     * tocca al contenuto redazionale o non c'è niente.
     *
     * @return Collection<int, Sponsorship>
     */
    public function forPlacement(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null, ?User $user = null, ?EventFilters $filters = null): Collection
    {
        $chosen = $this->first($city, $placement, $now, $user, $filters);

        return $chosen === null ? new Collection : new Collection([$chosen]);
    }

    /**
     * La campagna di una collocazione, o `null`.
     *
     * Con un filtro attivo (la cima dell'elenco) vengono prima le unità con un
     * evento che lo rispetta; solo se non ce n'è nessuna si pesca fra tutte.
     */
    public function first(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null, ?User $user = null, ?EventFilters $filters = null): ?Sponsorship
    {
        $adesso = $now ?? CarbonImmutable::now('UTC');
        $plan = $this->plans->current($city, $placement, $adesso);

        if ($plan->isEmpty()) {
            return null;
        }

        /* L'utente si legge qui e non arriva dai controller: così ogni punto
           di chiamata ha la personalizzazione senza doversene ricordare, e
           fuori da una richiesta web (code, comandi) il guard risponde `null`
           e non cambia niente. */
        $utente = $user ?? Auth::user();
        $utente = $utente instanceof User ? $utente : null;

        $prefer = null;

        if ($filters !== null && $filters->activeCount() > 0) {
            $prefer = app(EventFinder::class)->query($city, $filters)
                ->forEvents($plan->eventIds())->promotable()->eventIds();
        }

        $draw = $this->draw ?? ($placement === SponsorshipPlacement::HomeHero
            ? HashDraw::forSlot((int) $city->getKey(), $placement, $adesso, HashDraw::slotMinutes())
            : app(Draw::class));

        return $this->pick($plan, $city, $placement, $utente, [], $prefer, $draw, $adesso, $this->relations($placement));
    }

    /**
     * La campagna del foglio della mappa di un locale: solo fra gli eventi di
     * quel locale. Una pubblicità che si apre toccando un punto e parla
     * d'altro non è pubblicità, è un errore.
     *
     * Fra le unità di quel locale si sceglie a periodo (`asPeriod()`): fasce
     * e pesi restano, la riserva no. Il foglio si vende solo a periodo, e
     * un'unità a visualizzazioni rimasta da prima non lascia vuoto il foglio
     * del proprio locale per una quota calcolata sui fogli di tutta la città.
     */
    public function atVenue(City $city, SponsorshipPlacement $placement, int $venueId, ?CarbonImmutable $now = null, ?User $user = null): ?Sponsorship
    {
        $adesso = $now ?? CarbonImmutable::now('UTC');
        $plan = $this->plans->current($city, $placement, $adesso)
            ->restrict(static fn (array $row): bool => $row['venue'] === $venueId)
            ->asPeriod();

        if ($plan->isEmpty()) {
            return null;
        }

        $utente = $user ?? Auth::user();

        return $this->pick($plan, $city, $placement, $utente instanceof User ? $utente : null, [], null, $this->draw ?? app(Draw::class), $adesso, $this->relations($placement));
    }

    /**
     * Il banner: pesca fra le campagne di tutte le collocazioni, un evento una
     * volta sola, escluso quello che la pagina sta già mostrando.
     *
     * @param  array<string, mixed>  $context
     */
    public function banner(City $city, ?string $excludeEvent = null, ?User $user = null, array $context = []): ?Sponsorship
    {
        $adesso = CarbonImmutable::now('UTC');
        $plan = $this->plans->current($city, DeliveryPlanBuilder::BANNER, $adesso);

        if ($excludeEvent !== null && $excludeEvent !== '') {
            $plan = $plan->restrict(static fn (array $row): bool => $row['slug'] !== $excludeEvent);
        }

        if ($plan->isEmpty()) {
            return null;
        }

        $prefer = null;
        $filters = EventFilters::fromArray($context);

        if ($filters->activeCount() > 0) {
            $prefer = app(EventFinder::class)->query($city, $filters)
                ->forEvents($plan->eventIds())->promotable()->eventIds();
        }

        return $this->pick($plan, $city, null, $user, $context, $prefer, $this->draw ?? app(Draw::class), $adesso, ['event.venue', 'event.category', 'event.media', 'event.tags', 'grant']);
    }

    /**
     * L'estrazione, con ciò che riguarda chi guarda.
     *
     * Per un anonimo senza contesto non serve nessuna query: il piano basta.
     * Per chi è collegato (o con un contesto di pagina) si caricano le righe
     * del piano per l'affinità, che resta quella di `BannerAffinity`.
     *
     * @param  array<string, mixed>  $context
     * @param  list<int>|null  $prefer
     * @param  array<array-key, mixed>  $relations
     */
    private function pick(DeliveryPlan $plan, City $city, ?SponsorshipPlacement $placement, ?User $user, array $context, ?array $prefer, Draw $draw, CarbonImmutable $now, array $relations): ?Sponsorship
    {
        $loaded = null;
        $rowMultipliers = [];

        if ($user !== null) {
            $preferences = app(ContentPreferences::class);
            $hiddenVenues = $preferences->hiddenVenues($user);

            if ($hiddenVenues !== []) {
                $plan = $plan->restrict(static fn (array $row): bool => $row['venue'] === null || ! in_array($row['venue'], $hiddenVenues, true));
            }
        }

        if ($plan->isEmpty()) {
            return null;
        }

        if ($user !== null || array_filter($context, static fn (mixed $value): bool => filled($value)) !== []) {
            $loaded = Sponsorship::query()->whereKey($plan->rowIds())->with($relations)->get()->keyBy(fn (Sponsorship $row): int => (int) $row->getKey());

            /* Il peso 2 su tutte è il modo di leggere il moltiplicatore esatto
               di `BannerAffinity`: le moltiplica per due e poi, se sono tutte
               pari, le divide per due; con 2 in ingresso escono sempre 2 × m.
               Le campagne di una categoria nascosta non escono affatto. */
            $probes = $loaded->values()->map(function (Sponsorship $row): Sponsorship {
                $probe = clone $row;
                $probe->weight = 2;

                return $probe;
            });

            foreach (app(BannerAffinity::class)->apply($probes, $city, $user, $context) as $probe) {
                $rowMultipliers[(int) $probe->getKey()] = $probe->weight / 2;
            }

            $plan = $plan->restrict(static fn (array $row): bool => array_key_exists($row['id'], $rowMultipliers));
        }

        if ($plan->isEmpty()) {
            return null;
        }

        $multipliers = $plan->unitMultipliers($rowMultipliers);

        foreach ($this->viewerMultiplier->multipliers($plan, $user, $now) as $key => $factor) {
            $multipliers[$key] = ($multipliers[$key] ?? 1.0) * $factor;
        }

        $rowId = $plan->draw($draw, $multipliers, $prefer);

        if ($rowId === null) {
            return null;
        }

        $chosen = $loaded?->get($rowId);

        if ($chosen !== null && $placement !== null) {
            $chosen->loadMissing($this->relations($placement));
        }

        return $chosen ?? Sponsorship::query()->with($relations)->find($rowId);
    }

    /**
     * Ciò che la vista di ogni collocazione legge della campagna.
     *
     * @return array<array-key, mixed>
     */
    private function relations(SponsorshipPlacement $placement): array
    {
        $relations = ['event.venue', 'event.category', 'event.tags'];

        if (in_array($placement, [SponsorshipPlacement::HomeCard, SponsorshipPlacement::MapSheet], true)) {
            $relations['event.occurrences'] = static fn ($query) => $query
                ->withCount('interestedUsers as interested_count');
        }

        return $relations;
    }
}
