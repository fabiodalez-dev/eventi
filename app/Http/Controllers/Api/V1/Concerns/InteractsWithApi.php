<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\City;
use App\Models\User;
use App\Support\CurrentCity;
use Illuminate\Http\Request;

/**
 * Le due domande che ogni controller dell'API si pone: di quale città parla
 * questa richiesta, e chi la sta facendo.
 *
 * La città la sceglie `ResolveApiCity` a partire dal parametro `city`; qui si
 * legge soltanto, e la sua assenza è un 404 — un catalogo senza città accesa
 * non esiste (`cities.is_active` nasce falso di proposito).
 */
trait InteractsWithApi
{
    protected function city(): City
    {
        $city = app(CurrentCity::class)->get();

        if (! $city instanceof City) {
            throw new ApiException(ApiErrorCode::CityNotFound);
        }

        return $city;
    }

    /**
     * L'utente del token, se ce n'è uno.
     *
     * Le rotte pubbliche non pretendono autenticazione ma la **riconoscono**:
     * è ciò che permette a `is_saved` di comparire per chi è entrato e di
     * restare assente per tutti gli altri (§15.8). Un token invalido non è un
     * errore su una rotta pubblica: è un chiamante anonimo.
     */
    protected function currentUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }
}
