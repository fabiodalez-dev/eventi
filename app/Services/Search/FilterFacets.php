<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Category;
use App\Models\City;
use App\Models\Tag;
use App\Models\Venue;
use App\Support\ContentVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Le voci che il pannello dei filtri offre: categorie attive, tag più usati,
 * comuni con almeno un locale, locali della città (§11.3).
 *
 * Sono tassonomie, cioè dati che cambiano di rado: §12.3 le tiene in cache per
 * **ventiquattro ore**. La cache sta qui dentro, dove ogni pagina che disegna i
 * filtri la eredita senza saperlo, e non nelle viste — che altrimenti
 * dovrebbero ricordarsene una per una.
 *
 * Le quattro voci hanno cache separate perché hanno soggetti diversi:
 * categorie e tag valgono per tutto il sistema, comuni e locali per una città
 * sola. La chiave dei due elenchi che dipendono dalla città porta il numero di
 * versione dei contenuti (`App\Support\ContentVersion`): un locale approvato
 * oggi deve comparire nei filtri oggi, non domani a quest'ora.
 *
 * **In cache finiscono righe, non modelli.** `cache.serializable_classes` è
 * `false`: nessuna classe PHP viene ricostruita da ciò che sta in cache, per
 * non offrire una catena di deserializzazione a chi dovesse impadronirsi della
 * `APP_KEY`. Si salvano quindi gli attributi e si rifanno i modelli con
 * `hydrate()`, che non interroga il database. Salvare i modelli sembrerebbe
 * funzionare con il driver `array` dei test — dove nulla viene serializzato — e
 * romperebbe in produzione con `file`.
 */
final class FilterFacets
{
    private const TAG_LIMIT = 24;

    /**
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::hydrate($this->remember(
            'categorie',
            fn (): array => Category::query()->active()->ordered()->get()->toArray(),
        ));
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tags(): Collection
    {
        return Tag::hydrate($this->remember('tag', fn (): array => Tag::query()
            ->approved()
            ->popular()
            ->orderBy('name')
            ->limit(self::TAG_LIMIT)
            ->get()
            ->toArray()));
    }

    /**
     * @return Collection<int, string>
     */
    public function municipalities(City $city): Collection
    {
        /** @var Collection<int, string> $municipalities */
        $municipalities = new Collection($this->remember(
            'comuni:'.$city->getKey().':'.ContentVersion::for($city),
            fn (): array => Venue::query()
                ->approved()
                ->inCity($city)
                ->distinct()
                ->orderBy('municipality')
                ->pluck('municipality')
                ->all(),
        ));

        return $municipalities;
    }

    /**
     * I quartieri con almeno un locale approvato. Vuoto finché nessuno li ha
     * compilati, e in quel caso il filtro non si disegna affatto (§8.6): un
     * menu a tendina con la sola voce «tutti» è un contenitore vuoto.
     *
     * @return Collection<int, string>
     */
    public function zones(City $city): Collection
    {
        /** @var Collection<int, string> $zones */
        $zones = new Collection($this->remember(
            'quartieri:'.$city->getKey().':'.ContentVersion::for($city),
            fn (): array => Venue::query()
                ->approved()
                ->inCity($city)
                ->whereNotNull('zone')
                ->where('zone', '!=', '')
                ->distinct()
                ->orderBy('zone')
                ->pluck('zone')
                ->all(),
        ));

        return $zones;
    }

    /**
     * @return Collection<int, Venue>
     */
    public function venues(City $city): Collection
    {
        return Venue::hydrate($this->remember(
            'locali:'.$city->getKey().':'.ContentVersion::for($city),
            fn (): array => Venue::query()
                ->approved()
                ->inCity($city)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'municipality'])
                ->toArray(),
        ));
    }

    /**
     * @param  \Closure(): array<int, mixed>  $resolve
     * @return array<int, mixed>
     */
    private function remember(string $key, \Closure $resolve): array
    {
        /** @var array<int, mixed> $value */
        $value = Cache::remember(
            'tassonomie:'.$key,
            now()->addHours(config()->integer('page_cache.taxonomy_ttl_hours')),
            $resolve,
        );

        return $value;
    }
}
