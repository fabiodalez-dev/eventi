<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use InvalidArgumentException;

/**
 * Il piano di consegna di una città e di una collocazione: chi può comparire
 * adesso e con quale quota.
 *
 * Lo costruisce `DeliveryPlanBuilder` (che legge il database) una volta al
 * minuto e lo mette in cache. Qui c'è solo l'estrazione, che è aritmetica:
 * niente database, niente orologio, niente caso proprio. Per questo la pagina
 * servita dalla cache può chiedere una sponsorizzazione a ogni visita senza
 * fare nemmeno una query.
 *
 * **Due passi, sempre.** Prima l'unità di consegna (un'abilitazione `g:12`
 * oppure una campagna singola `s:45`) con le quote di `QuotaAllocator`, poi
 * l'evento dentro l'unità con il peso della vicinanza della data. Così un
 * locale con quaranta eventi e uno con un evento solo, a parità di acquisto,
 * ricevono lo stesso spazio: prima, con una riga per evento sulla ruota, il
 * primo ne prendeva quaranta volte tanto.
 *
 * Il piano è un valore: non cambia dopo la costruzione, e ogni restrizione
 * (filtri della pagina, preferenze di chi guarda) ne restituisce un altro con
 * le quote ricalcolate sulle sole unità rimaste.
 *
 * @phpstan-type PlanRow array{id: int, event: int, slug: string, venue: int|null, category: int|null, weight: float}
 * @phpstan-type PlanUnit array{key: string, priority: int, weight: int, quota: float|null, rows: list<PlanRow>}
 */
final class DeliveryPlan
{
    /** La forma salvata in cache. Cambia se cambia la forma dell'array. */
    public const FORMAT = 1;

    /** @var array<string, float> */
    private readonly array $shares;

    private readonly float $reserve;

    /** @var list<string> */
    private readonly array $atRisk;

    /**
     * @param  array<string, PlanUnit>  $units  per chiave dell'unità
     */
    public function __construct(
        private readonly int $cityId,
        private readonly string $placement,
        private readonly int $builtAt,
        private readonly array $units,
        private readonly float $quotaMax = Pacer::DEFAULT_QUOTA_MAX,
    ) {
        foreach ($units as $key => $unit) {
            if ($key !== $unit['key']) {
                throw new InvalidArgumentException('A plan unit is keyed by its own key.');
            }
        }

        $allocation = (new QuotaAllocator($quotaMax))->allocate(array_map(static fn (array $unit): array => [
            'key' => $unit['key'],
            'priority' => $unit['priority'],
            'weight' => $unit['weight'],
            'quota' => $unit['quota'],
        ], array_values($units)));

        $this->shares = $allocation['shares'];
        $this->reserve = $allocation['reserve'];
        $this->atRisk = $allocation['at_risk'];
    }

    /** Un piano senza nessuno: tutto lo spazio va al contenuto redazionale. */
    public static function empty(int $cityId, string $placement, int $builtAt, float $quotaMax = Pacer::DEFAULT_QUOTA_MAX): self
    {
        return new self($cityId, $placement, $builtAt, [], $quotaMax);
    }

    public function cityId(): int
    {
        return $this->cityId;
    }

    public function placement(): string
    {
        return $this->placement;
    }

    /** L'istante Unix in cui il piano è stato costruito. */
    public function builtAt(): int
    {
        return $this->builtAt;
    }

    /**
     * Il piano è ancora buono a `$now` (istante Unix)? Vale anche all'indietro:
     * un piano costruito «nel futuro» rispetto a chi chiede (orologi diversi,
     * test che tornano indietro nel tempo) non si usa.
     */
    public function isFresh(int $now, int $ttlSeconds): bool
    {
        return abs($now - $this->builtAt) <= max(0, $ttlSeconds);
    }

    public function isEmpty(): bool
    {
        return $this->units === [];
    }

    /** @return array<string, PlanUnit> */
    public function units(): array
    {
        return $this->units;
    }

    /** @return array<string, float> le quote di base, per unità */
    public function shares(): array
    {
        return $this->shares;
    }

    public function reserve(): float
    {
        return $this->reserve;
    }

    /** @return list<string> le unità che chiedono più di quanto lo spazio può dare */
    public function atRisk(): array
    {
        return $this->atRisk;
    }

    /** @return list<int> gli id delle righe `sponsorships`, nell'ordine delle unità */
    public function rowIds(): array
    {
        return array_map(static fn (array $row): int => $row['id'], $this->rows());
    }

    /** @return list<int> gli eventi del piano, senza doppioni */
    public function eventIds(): array
    {
        return array_values(array_unique(array_map(static fn (array $row): int => $row['event'], $this->rows())));
    }

    /** @return list<PlanRow> */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->units as $unit) {
            foreach ($unit['rows'] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return PlanRow|null */
    public function row(int $id): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /** La chiave dell'unità a cui appartiene la riga, o `null`. */
    public function unitOf(int $rowId): ?string
    {
        foreach ($this->units as $key => $unit) {
            foreach ($unit['rows'] as $row) {
                if ($row['id'] === $rowId) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Un piano con le sole righe che `$keep` accetta. Le unità rimaste senza
     * righe escono, e le quote si ricalcolano sulle unità che restano: è ciò
     * che fa salire una fascia più bassa quando quella sopra non ha niente da
     * mostrare in questa pagina.
     *
     * @param  callable(PlanRow): bool  $keep
     */
    public function restrict(callable $keep): self
    {
        $units = [];
        $changed = false;

        foreach ($this->units as $key => $unit) {
            $rows = array_values(array_filter($unit['rows'], $keep));

            if (count($rows) !== count($unit['rows'])) {
                $changed = true;
            }

            if ($rows !== []) {
                $unit['rows'] = $rows;
                $units[$key] = $unit;
            }
        }

        return $changed ? new self($this->cityId, $this->placement, $this->builtAt, $units, $this->quotaMax) : $this;
    }

    /**
     * Lo stesso piano con tutte le unità a periodo: nessuna quota, quindi
     * nessuna riserva quando c'è almeno un'unità, e le fasce di priorità
     * restano. Serve a uno spazio che mostra solo le unità di un locale (il
     * foglio della mappa): la quota di un'unità a visualizzazioni è calcolata
     * sul traffico di tutta la città, e applicata al solo foglio del suo
     * locale lascerebbe vuoto, senza concorrenti, lo spazio che ha comprato.
     */
    public function asPeriod(): self
    {
        $units = [];
        $changed = false;

        foreach ($this->units as $key => $unit) {
            $changed = $changed || $unit['quota'] !== null;
            $unit['quota'] = null;
            $units[$key] = $unit;
        }

        return $changed ? new self($this->cityId, $this->placement, $this->builtAt, $units, $this->quotaMax) : $this;
    }

    /**
     * Le unità che hanno almeno un evento fra `$eventIds`, con i soli eventi
     * che ci stanno. Se nessuna unità ne ha, il piano intero: chi guarda un
     * elenco filtrato vede prima ciò che rispetta i suoi filtri, e solo se non
     * c'è niente il resto (come faceva `forPlacement()`).
     *
     * @param  list<int>  $eventIds
     */
    public function preferring(array $eventIds): self
    {
        $wanted = array_flip($eventIds);
        $preferred = $this->restrict(static fn (array $row): bool => isset($wanted[$row['event']]));

        return $preferred->isEmpty() ? $this : $preferred;
    }

    /**
     * L'affinità di chi guarda portata dagli eventi all'unità: la media dei
     * moltiplicatori delle righe pesata sulle quote degli eventi (`a_i` del
     * piano). Una riga senza moltiplicatore vale 1.
     *
     * @param  array<int, int|float>  $rowMultipliers  per id della riga
     * @return array<string, float>
     */
    public function unitMultipliers(array $rowMultipliers): array
    {
        $multipliers = [];

        foreach ($this->units as $key => $unit) {
            $multipliers[$key] = EventWeights::weightedMean($this->eventShares($unit), $rowMultipliers);
        }

        return $multipliers;
    }

    /**
     * Le probabilità di ogni unità e della riserva per chi ha questi
     * moltiplicatori. Sommano a 1.
     *
     * @param  array<string, int|float>  $multipliers
     * @return array<string, float>
     */
    public function probabilities(array $multipliers = []): array
    {
        return QuotaAllocator::modulate($this->shares, $this->reserve, $multipliers);
    }

    /**
     * L'estrazione: l'id della riga `sponsorships` da mostrare, oppure `null`
     * quando tocca al contenuto redazionale (o non c'è nessuno).
     *
     * Il secondo numero si chiede solo se l'unità ha più di un evento: con un
     * evento per unità la sequenza dei numeri è quella della simulazione del
     * piano, e i suoi conteggi si ritrovano identici.
     *
     * @param  array<string, int|float>  $multipliers  per unità
     * @param  list<int>|null  $preferEvents
     */
    public function draw(Draw $draw, array $multipliers = [], ?array $preferEvents = null): ?int
    {
        $plan = $preferEvents === null ? $this : $this->preferring($preferEvents);

        if ($plan->isEmpty()) {
            return null;
        }

        $key = QuotaAllocator::pick($plan->probabilities($multipliers), $draw->fraction());

        if ($key === null || ! isset($plan->units[$key])) {
            return null;
        }

        $unit = $plan->units[$key];

        if (count($unit['rows']) === 1) {
            return $unit['rows'][0]['id'];
        }

        $chosen = QuotaAllocator::pick(array_combine(
            array_map(static fn (int $id): string => 'r'.$id, array_keys($plan->eventShares($unit))),
            array_values($plan->eventShares($unit)),
        ), $draw->fraction());

        return $chosen === null ? null : (int) substr($chosen, 1);
    }

    /**
     * Le quote degli eventi dentro un'unità: i loro pesi divisi per la somma.
     *
     * @param  PlanUnit  $unit
     * @return array<int, float> per id della riga
     */
    public function eventShares(array $unit): array
    {
        $weights = [];

        foreach ($unit['rows'] as $row) {
            $weights[$row['id']] = max(0.0, $row['weight']);
        }

        $shares = EventWeights::normalise($weights);

        /* Tutti a zero non vuol dire «nessuno»: l'unità è stata scelta, e i
           suoi eventi si dividono lo spazio in parti uguali. */
        if ($shares === [] && $weights !== []) {
            return array_fill_keys(array_keys($weights), 1 / count($weights));
        }

        return $shares;
    }

    /**
     * @return array{format: int, city: int, placement: string, built_at: int, quota_max: float, units: array<string, PlanUnit>}
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'city' => $this->cityId,
            'placement' => $this->placement,
            'built_at' => $this->builtAt,
            'quota_max' => $this->quotaMax,
            'units' => $this->units,
        ];
    }

    /**
     * Il piano letto dalla cache, oppure `null` se la forma non è quella di
     * adesso (un piano salvato prima di un rilascio): chi chiama lo ricostruisce.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT
            || ! is_int($data['city'] ?? null) || ! is_string($data['placement'] ?? null)
            || ! is_int($data['built_at'] ?? null) || ! is_array($data['units'] ?? null)) {
            return null;
        }

        $quotaMax = $data['quota_max'] ?? Pacer::DEFAULT_QUOTA_MAX;

        try {
            /** @var array<string, PlanUnit> $units */
            $units = $data['units'];

            return new self($data['city'], $data['placement'], $data['built_at'], $units, is_float($quotaMax) || is_int($quotaMax) ? (float) $quotaMax : Pacer::DEFAULT_QUOTA_MAX);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
