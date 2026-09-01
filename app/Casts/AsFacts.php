<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\FactList;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Il cast delle schede tecniche: `events.facts` e `venues.info`, dal JSON
 * della colonna a `App\DTOs\FactList` e ritorno.
 *
 * Come per `AsExternalLinks`, in colonna finisce `null` e non `[]` quando non
 * ci sono righe: una sezione senza contenuto non si disegna (§8.6), e un
 * elenco vuoto serializzato sarebbe un dato presente che significa "assente".
 *
 * @implements CastsAttributes<FactList, FactList|iterable<mixed>|string|null>
 */
final class AsFacts implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): FactList
    {
        return FactList::fromMixed($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $facts = FactList::fromMixed($value);

        return [$key => $facts->isEmpty() ? null : json_encode($facts->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
}
