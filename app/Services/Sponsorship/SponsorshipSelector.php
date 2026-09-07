<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
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
 * campagne su categorie che l'utente ha salvato di recente passano davanti
 * alle altre (`CategoriePreferite`). Vale solo per gli autenticati perché le
 * loro pagine non passano dalla full-page cache (`CachePage` le esclude): per
 * gli anonimi la pagina è condivisa, e una scelta personalizzata finita in
 * cache verrebbe servita a tutti. Per loro non cambia niente.
 */
final class SponsorshipSelector
{
    /* Il default consente `new SponsorshipSelector` — come fanno i test e
       come potrebbe fare chiunque, dato che finora la classe non aveva
       dipendenze; `CategoriePreferite` non ha stato né costruttore, quindi
       il default e l'istanza del container sono equivalenti. */
    public function __construct(
        private readonly CategoriePreferite $preferenze = new CategoriePreferite,
    ) {}

    /**
     * Le campagne da mostrare in una collocazione, già ordinate.
     *
     * @return Collection<int, Sponsorship>
     */
    public function forPlacement(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null, ?User $user = null): Collection
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

        if ($placement === SponsorshipPlacement::HomeHero) {
            $eligibleIds = EventOccurrenceQuery::for($city)->promotable()->eventIds();
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
        $preferite = $utente instanceof User ? $this->preferenze->dellUtente($utente) : [];

        return $this->rotate($candidate, $placement->limit(), $adesso, $preferite);
    }

    /**
     * La prima campagna di una collocazione, o `null`.
     *
     * Le collocazioni con tetto uno — oggi tutte — la usano al posto di
     * `forPlacement()->first()`, che direbbe la stessa cosa in modo più
     * rumoroso.
     */
    public function first(City $city, SponsorshipPlacement $placement, ?CarbonImmutable $now = null, ?User $user = null): ?Sponsorship
    {
        return $this->forPlacement($city, $placement, $now, $user)->first();
    }

    /**
     * Ruota di un passo per minuto, dentro ogni gruppo di pari priorità.
     *
     * @param  Collection<int, Sponsorship>  $candidate
     * @param  list<int>  $preferite
     * @return Collection<int, Sponsorship>
     */
    private function rotate(Collection $candidate, int $limit, CarbonImmutable $now, array $preferite = []): Collection
    {
        /* Il passo cambia ogni minuto ed è lo stesso per tutti in quel minuto:
           è ciò che rende la rotazione compatibile con la pagina in cache. */
        $passo = (int) $now->format('YmdHi');

        return $candidate
            ->groupBy(fn (Sponsorship $sponsorship): int => (int) $sponsorship->priority)
            ->sortKeysDesc()
            ->flatMap(fn (Collection $gruppo): Collection => $this->ordinaGruppo($gruppo, $passo, $preferite))
            ->take($limit)
            ->values();
    }

    /**
     * L'ordine dentro un gruppo di pari priorità: prima l'affinità, poi il
     * peso.
     *
     * **Perché due ruote e non una ruota con i pesi gonfiati.** L'alternativa
     * ovvia — moltiplicare il peso delle campagne affini — mescolerebbe due
     * cose vendute separatamente: il peso è una quota concordata col cliente,
     * e gonfiarlo per alcuni utenti la falserebbe in modo diverso per ognuno,
     * cioè in modo che nessuno può più verificare. Spartendo invece il gruppo
     * in affini e non affini, e ruotando ciascuna metà con la sua ruota, le
     * proporzioni pattuite restano esatte *dentro* ogni metà; l'affinità
     * decide solo quale metà si guarda per prima.
     *
     * Non è un filtro: le non affini seguono, non spariscono. Se le affini
     * sono meno del tetto della collocazione — o zero — le altre riempiono lo
     * spazio come oggi: meglio una campagna non affine che uno spazio vuoto.
     *
     * @param  Collection<int, Sponsorship>  $gruppo
     * @param  list<int>  $preferite
     * @return Collection<int, Sponsorship>
     */
    private function ordinaGruppo(Collection $gruppo, int $passo, array $preferite): Collection
    {
        return $this->ruotaPerPeso($gruppo, $passo, $preferite);
    }

    /**
     * Quanto conta il peso di questa campagna per QUESTO utente.
     *
     * **Perché un moltiplicatore e non due gruppi separati.** La prima
     * versione metteva le campagne affini davanti a tutte le altre. Sembra
     * ovvio ed è la cosa sbagliata: con una sola campagna affine, quell'utente
     * la vede il cento per cento delle volte e le altre mai — cioè esattamente
     * il problema che il peso era appena nato per risolvere, reintrodotto per
     * utente invece che per tutti.
     *
     * I numeri lo dicono meglio. Peso 3 contro peso 1, per un utente affine
     * alla seconda:
     *
     * - a gruppi separati: 0% e 100% — chi ha comprato la quota grossa sparisce;
     * - con moltiplicatore ×2:  60% e 40% — l'affinità sposta la quota di un
     *   terzo e la lascia riconoscibile.
     *
     * L'affinità deve inclinare la bilancia, non ribaltarla: chi paga ha
     * comprato una quota e deve ritrovarla anche fra gli utenti a cui la sua
     * categoria interessa meno.
     *
     * @param  list<int>  $preferite
     */
    private function pesoPerUtente(Sponsorship $campagna, array $preferite): int
    {
        $peso = max(1, (int) $campagna->weight);

        if ($preferite === []) {
            return $peso;
        }

        $categoria = $campagna->event?->category_id;

        if ($categoria === null || ! in_array((int) $categoria, $preferite, strict: true)) {
            return $peso;
        }

        return $peso * max(1, config()->integer('eventi.sponsorship_affinity_multiplier'));
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
     * @param  list<int>  $preferite
     * @return Collection<int, Sponsorship>
     */
    private function ruotaPerPeso(Collection $gruppo, int $passo, array $preferite = []): Collection
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
            $peso = $this->pesoPerUtente($campagna, $preferite);

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
