<?php

declare(strict_types=1);

use App\Models\City;
use App\Models\Venue;
use Illuminate\Testing\TestResponse;
use MatanYadaev\EloquentSpatial\Objects\Point;

/*
 * Aiuti dei test di flusso della scoperta. I nomi portano il prefisso
 * `scoperta` perché in un'esecuzione completa tutti i file di prova vivono
 * nello stesso processo: due funzioni globali con lo stesso nome lo
 * fermerebbero con un errore fatale (MapPageTest ha già un `mapVenue`).
 */

if (! function_exists('scopertaLocale')) {
    /**
     * Un locale approvato con coordinate coerenti fra colonne e punto spaziale:
     * i filtri per distanza e il rettangolo della mappa leggono l'uno o l'altro.
     *
     * @param  array<string, mixed>  $attributes
     */
    function scopertaLocale(City $city, array $attributes = []): Venue
    {
        $lat = (float) ($attributes['lat'] ?? 45.4064);
        $lng = (float) ($attributes['lng'] ?? 11.8768);

        return Venue::factory()->approved()->create([
            'city_id' => $city->getKey(),
            ...$attributes,
            'lat' => $lat,
            'lng' => $lng,
            'location' => new Point($lat, $lng, 0),
        ]);
    }

    /**
     * I titoli degli elementi di una risposta API, nell'ordine in cui arrivano.
     *
     * @param  array<int, array<string, mixed>>|null  $data
     * @return list<string>
     */
    function scopertaTitoli(?array $data): array
    {
        return array_values(array_map(static fn (array $item): string => (string) $item['title'], $data ?? []));
    }

    /**
     * L'indirizzo canonico dichiarato da una pagina, o `null` se non ne dichiara.
     */
    function scopertaCanonico(TestResponse $response): ?string
    {
        preg_match('/<link rel="canonical" href="([^"]+)">/', (string) $response->getContent(), $match);

        return isset($match[1]) ? html_entity_decode($match[1]) : null;
    }
}
