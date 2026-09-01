<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\DTOs\EventFilters;
use App\Models\Category;
use App\Models\City;
use App\Services\Search\EventFinder;

/**
 * Il carico che la mappa scarica a ogni spostamento (§11.6).
 *
 * **Non si serializza l'evento intero.** Una città piena produce centinaia di
 * punti e la mappa ne ha bisogno di quattro cose: dove sta il locale, di che
 * colore è il marcatore, quante date vi cadono dentro e come chiedere il
 * resto. Il titolo, la locandina, il prezzo e l'orario arrivano dopo, per il
 * solo marcatore che qualcuno tocca, e arrivano come card già disegnata — la
 * stessa `<x-event-card>` del resto del sito, non una sua copia in JavaScript.
 *
 * La forma è volutamente compatta: i marcatori sono liste posizionali e le
 * categorie stanno in una tabella a parte, citata per indice. Ripetere
 * `"category": "musica-dal-vivo"` cinquecento volte costa più dei dati.
 */
final class MapPayload
{
    public function __construct(private readonly EventFinder $finder) {}

    /**
     * @param  array{min_lng: float, min_lat: float, max_lng: float, max_lat: float}|null  $bounds
     * @return array{categories: list<array{slug: string, name: string, color: string}>, markers: list<array{0: int, 1: float, 2: float, 3: int, 4: int, 5: string}>, truncated: bool}
     */
    public function build(City $city, EventFilters $filters, ?array $bounds): array
    {
        $query = $this->finder->query($city, $filters);

        if ($bounds !== null) {
            $query->withinBounds($bounds['min_lng'], $bounds['min_lat'], $bounds['max_lng'], $bounds['max_lat']);
        }

        $limit = config()->integer('map.max_markers');
        $rows = $query->venueMarkers($limit + 1);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $categories = $this->categories($rows);
        $index = [];

        foreach ($categories as $position => $category) {
            $index[$category['id']] = $position;
        }

        $markers = [];

        foreach ($rows as $row) {
            /* Liste posizionali e non oggetti: ripetere sei nomi di campo per
               cinquecento marcatori costa piu' dei dati stessi. L'ordine e'
               documentato qui e letto in un punto solo, in `map.js`. */
            $markers[] = [
                $row['venue_id'],
                round($row['lng'], 6),
                round($row['lat'], 6),
                $index[$row['category_id']] ?? 0,
                $row['count'],
                $row['venue_name'],
            ];
        }

        return [
            'categories' => array_map(
                static fn (array $category): array => [
                    'slug' => $category['slug'],
                    'name' => $category['name'],
                    'color' => $category['color'],
                ],
                $categories,
            ),
            'markers' => $markers,
            'truncated' => $truncated,
        ];
    }

    /**
     * Le sole categorie che compaiono davvero fra i marcatori: la legenda della
     * mappa non elenca colori che nessun punto porta.
     *
     * @param  list<array{venue_id: int, lat: float, lng: float, category_id: int, occurrence_id: int, count: int}>  $rows
     * @return list<array{id: int, slug: string, name: string, color: string}>
     */
    private function categories(array $rows): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => $row['category_id'],
            $rows,
        )));

        if ($ids === []) {
            return [];
        }

        $fallback = config()->string('map.fallback_color');

        return Category::query()
            ->whereKey($ids)
            ->ordered()
            ->get()
            ->map(static fn (Category $category): array => [
                'id' => (int) $category->getKey(),
                'slug' => (string) $category->slug,
                'name' => (string) $category->name,
                'color' => filled($category->color) ? (string) $category->color : $fallback,
            ])
            ->values()
            ->all();
    }
}
