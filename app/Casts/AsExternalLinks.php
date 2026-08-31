<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\ExternalLinkList;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Il cast di `events.external_links`: dal JSON della colonna a
 * `App\DTOs\ExternalLinkList`, e ritorno.
 *
 * Un `'array'` avrebbe consegnato a ogni lettore — vista, risorsa API, dati
 * strutturati — una struttura da controllare da capo, e ognuno l'avrebbe
 * controllata un po' diversamente. Qui la forma si stabilisce una volta
 * all'ingresso e una volta all'uscita.
 *
 * **In colonna finisce `null`, non `[]`, quando non ci sono link.** La scheda
 * pubblica non deve rendere una sezione vuota (§8.6), e un elenco vuoto
 * serializzato sarebbe un dato presente che significa "assente": due modi di
 * dire la stessa cosa sono due modi di sbagliarla.
 *
 * @implements CastsAttributes<ExternalLinkList, ExternalLinkList|iterable<mixed>|string|null>
 */
final class AsExternalLinks implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ExternalLinkList
    {
        return ExternalLinkList::fromMixed($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $links = ExternalLinkList::fromMixed($value);

        return [$key => $links->isEmpty() ? null : json_encode($links->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
}
