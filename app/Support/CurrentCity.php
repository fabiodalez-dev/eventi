<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\City;

/**
 * La città a cui si riferisce la richiesta in corso.
 *
 * Oggi il sito serve una città sola (Padova e provincia, D11) e questa classe
 * restituisce la prima città attiva. Quando `/{city}/eventi` diventerà una
 * rotta reale (§11.1) sarà un middleware a chiamare `set()`: tutto ciò che
 * legge la città — intestazione, formattazione delle date, motore temporale —
 * passa già da qui e non andrà toccato.
 *
 * La città si carica una volta sola per richiesta: il servizio è registrato
 * come `scoped` in `AppServiceProvider`.
 */
final class CurrentCity
{
    private ?City $city = null;

    private bool $resolved = false;

    public function set(City $city): void
    {
        $this->city = $city;
        $this->resolved = true;
    }

    /**
     * Può essere `null` finché nessuna città è stata accesa: `cities.is_active`
     * nasce falso di proposito (una città si pubblica quando è pronta).
     */
    public function get(): ?City
    {
        if (! $this->resolved) {
            $this->city = City::query()->active()->orderBy('id')->first();
            $this->resolved = true;
        }

        return $this->city;
    }

    public function timezone(): string
    {
        $city = $this->get();

        if ($city === null) {
            return config()->string('app.timezone');
        }

        return $city->timezone;
    }
}
