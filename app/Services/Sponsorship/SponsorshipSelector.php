<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Sponsorship;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

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
 */
final class SponsorshipSelector
{
    /**
     * Le campagne da mostrare in una collocazione, già ordinate.
     *
     * @return Collection<int, Sponsorship>
     */
    public function forPlacement(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null): Collection
    {
        $adesso = $now ?? CarbonImmutable::now('UTC');

        /** @var Collection<int, Sponsorship> $candidate */
        $candidate = Sponsorship::query()
            ->visible($adesso)
            ->where('city_id', $city->getKey())
            ->where('placement', $placement)
            ->with(['event.venue', 'event.category'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        if ($candidate->isEmpty()) {
            return $candidate;
        }

        return $this->rotate($candidate, $placement->limit(), $adesso);
    }

    /**
     * La prima campagna di una collocazione, o `null`.
     *
     * Le collocazioni con tetto uno — oggi tutte — la usano al posto di
     * `forPlacement()->first()`, che direbbe la stessa cosa in modo più
     * rumoroso.
     */
    public function first(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null): ?Sponsorship
    {
        return $this->forPlacement($city, $placement, $now)->first();
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
            ->flatMap(function (Collection $gruppo) use ($passo): Collection {
                $quante = $gruppo->count();

                if ($quante < 2) {
                    return $gruppo->values();
                }

                /* Uno scorrimento circolare: al minuto N parte dalla campagna
                   N-esima del gruppo e prosegue in cerchio. Tutte compaiono, e
                   nessuna sta davanti alle altre per sempre. */
                $inizio = $passo % $quante;

                return $gruppo->values()->slice($inizio)->concat($gruppo->values()->slice(0, $inizio))->values();
            })
            ->take($limit)
            ->values();
    }
}
