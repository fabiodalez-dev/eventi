<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Category;
use App\Models\City;
use App\Models\Tag;
use App\Models\Venue;
use Illuminate\Support\Collection;

/**
 * Le voci che il pannello dei filtri offre: categorie attive, tag più usati,
 * comuni con almeno un locale, locali della città (§11.3).
 *
 * Sono tassonomie, cioè dati che cambiano di rado. §12.3 prevede di tenerle in
 * cache per 24 ore: la cache andrà messa **qui dentro**, dove ogni pagina che
 * disegna i filtri la eredita senza saperlo, e non nelle viste.
 */
final class FilterFacets
{
    private const TAG_LIMIT = 24;

    /**
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::query()->active()->ordered()->get();
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tags(): Collection
    {
        return Tag::query()
            ->approved()
            ->popular()
            ->orderBy('name')
            ->limit(self::TAG_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, string>
     */
    public function municipalities(City $city): Collection
    {
        return Venue::query()
            ->approved()
            ->inCity($city)
            ->distinct()
            ->orderBy('municipality')
            ->pluck('municipality');
    }

    /**
     * @return Collection<int, Venue>
     */
    public function venues(City $city): Collection
    {
        return Venue::query()
            ->approved()
            ->inCity($city)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'municipality']);
    }
}
