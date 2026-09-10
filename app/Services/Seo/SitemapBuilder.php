<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\DTOs\EventFilters;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Page;
use App\Models\Tag;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Search\EventFinder;
use App\Support\ContentVersion;
use App\Support\EventUrl;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\SitemapIndex;
use Spatie\Sitemap\Tags\Url;

/**
 * La `sitemap.xml` **a indice** di §12.2: eventi, locali, categorie, giorni
 * futuri.
 *
 * A indice e non in un file solo per due ragioni. La prima è il protocollo:
 * oltre 50.000 indirizzi un file va spezzato comunque. La seconda conta di
 * più — un indice dice a un motore *quale parte* è cambiata. Gli eventi
 * cambiano ogni giorno, le categorie una volta l'anno: con un unico file la
 * data di modifica sarebbe sempre di oggi e ogni scansione riscaricherebbe
 * anche ciò che non si muove da mesi.
 *
 * Le mappe si **compongono a ogni richiesta e stanno in cache**, invece di
 * essere scritte su disco da un comando periodico. Un file scritto la notte
 * scorsa non conosce l'evento pubblicato stamattina, e su uno spazio condiviso
 * un `cron` in meno è un guasto in meno (D3). La chiave porta il numero di
 * versione della città: alla pubblicazione di un evento la mappa è già
 * un'altra.
 *
 * Le date si chiedono a `EventOccurrenceQuery` come ovunque (§8.1): quali
 * giorni meritino una pagina lo dice il motore, non un conto fatto qui.
 *
 * **In cache finiscono array, non oggetti.** `cache.serializable_classes` è
 * `false` — nessuna classe PHP viene ricostruita da ciò che sta in cache, per
 * non offrire una catena di deserializzazione a chi dovesse impadronirsi della
 * `APP_KEY`. Salvare qui gli oggetti `Url` sembrerebbe funzionare con il driver
 * `array` dei test, dove nulla viene serializzato, e romperebbe in produzione
 * con `file`: è esattamente il genere di guasto che i test non vedono.
 */
final class SitemapBuilder
{
    /**
     * Le sezioni dell'indice, nell'ordine in cui compaiono.
     */
    public const SECTIONS = ['pagine', 'eventi', 'locali', 'tassonomie', 'giorni'];

    /**
     * L'indice: una riga per ogni mappa, comprese le pagine successive delle
     * sezioni che non stanno in un file solo.
     */
    public function index(City $city): SitemapIndex
    {
        $index = SitemapIndex::create();
        if (! app(EditorialContent::class)->indexable($city)) {
            return $index;
        }

        foreach (self::SECTIONS as $section) {
            $pages = $this->pageCount($city, $section);

            for ($page = 1; $page <= $pages; $page++) {
                $index->add(route('sitemap.section', ['section' => $section, 'page' => $page]));
            }
        }

        return $index;
    }

    /**
     * Una sezione. Risponde `null` se la sezione non esiste o se quella pagina
     * è oltre la fine: un indirizzo che non porta a niente deve dare 404, non
     * una mappa vuota che il motore riproverebbe domani.
     */
    public function section(City $city, string $section, int $page = 1): ?Sitemap
    {
        if (! app(EditorialContent::class)->indexable($city)) {
            return null;
        }
        if (! in_array($section, self::SECTIONS, true) || $page < 1) {
            return null;
        }

        $urls = $this->urls($city, $section);
        $chunk = config()->integer('seo.sitemap.chunk');
        $slice = array_slice($urls, ($page - 1) * $chunk, $chunk);

        if ($slice === []) {
            return null;
        }

        $sitemap = Sitemap::create();

        foreach ($slice as $entry) {
            $sitemap->add($this->tag($entry));
        }

        return $sitemap;
    }

    /**
     * Quante pagine ha una sezione. Zero significa che la sezione oggi non ha
     * niente da dichiarare — un sito appena acceso non ha eventi — e allora
     * non compare nemmeno nell'indice.
     */
    public function pageCount(City $city, string $section): int
    {
        $total = count($this->urls($city, $section));

        return (int) ceil($total / config()->integer('seo.sitemap.chunk'));
    }

    /**
     * Ricostruisce il tag XML da ciò che sta in cache.
     *
     * @param  array{loc: string, lastmod?: string|null, changefreq: string, priority: float}  $entry
     */
    private function tag(array $entry): Url
    {
        $url = Url::create($entry['loc'])
            ->setChangeFrequency($entry['changefreq'])
            ->setPriority($entry['priority']);

        $lastmod = $entry['lastmod'] ?? null;

        if (is_string($lastmod) && $lastmod !== '') {
            $url->setLastModificationDate(CarbonImmutable::parse($lastmod));
        }

        return $url;
    }

    /**
     * @return list<array{loc: string, lastmod?: string|null, changefreq: string, priority: float}>
     */
    private function urls(City $city, string $section): array
    {
        $key = sprintf('sitemap:%d:%d:%s', (int) $city->getKey(), ContentVersion::for($city), $section);

        /** @var list<array{loc: string, lastmod?: string|null, changefreq: string, priority: float}> $urls */
        $urls = Cache::remember(
            $key,
            now()->addMinutes(config()->integer('seo.sitemap.ttl_minutes')),
            fn (): array => match ($section) {
                'pagine' => $this->staticPages($city),
                'eventi' => $this->events($city),
                'locali' => $this->venues($city),
                'tassonomie' => $this->taxonomies($city),
                'giorni' => $this->days($city),
                default => [],
            },
        );

        return $urls;
    }

    /**
     * Le pagine fisse di §11.1. Si dichiarano solo quelle registrate davvero:
     * una mappa che elenca un indirizzo inesistente insegna al motore che il
     * sito risponde 404, ed è la cosa peggiore da insegnargli.
     *
     * @return list<array{loc: string, lastmod?: string|null, changefreq: string, priority: float}>
     */
    private function staticPages(City $city): array
    {
        $routes = [
            'home' => 1.0,
            'events.index' => 0.9,
            'events.today' => 0.9,
            'events.tomorrow' => 0.7,
            'events.weekend' => 0.8,
            'events.free' => 0.7,
            'map.index' => 0.6,
            'calendar.index' => 0.6,
            'venues.index' => 0.7,
            'submissions.create' => 0.4,
            'venue-applications.create' => 0.4,
        ];

        $urls = [];

        foreach ($routes as $name => $priority) {
            if (! Route::has($name)) {
                continue;
            }

            $filter = match ($name) {
                'events.today' => ['date' => 'today'], 'events.tomorrow' => ['date' => 'tomorrow'],
                'events.weekend' => ['date' => 'weekend'], 'events.free' => ['price' => 'free'],
                default => null,
            };
            if ($filter !== null && ! $this->hasResults($city, $filter)) {
                continue;
            }

            $urls[] = [
                'loc' => route($name),
                'changefreq' => $priority >= 0.8 ? Url::CHANGE_FREQUENCY_DAILY : Url::CHANGE_FREQUENCY_WEEKLY,
                'priority' => $priority,
            ];
        }

        foreach (Page::query()->published()->get() as $page) {
            if (app(EditorialContent::class)->indexable($page)) {
                $urls[] = ['loc' => route('pages.show', ['slug' => $page->slug]),
                    'lastmod' => $this->lastModified($page->getAttribute('updated_at')),
                    'changefreq' => Url::CHANGE_FREQUENCY_MONTHLY, 'priority' => 0.4];
            }
        }

        return $urls;
    }

    /**
     * Ogni evento pubblicato è una pagina indicizzabile (§12.2), anche quello
     * le cui date sono passate: la scheda resta e mostra il proprio archivio,
     * ed è esattamente la pagina a cui portano i link condivisi mesi prima.
     *
     * @return list<array{loc: string, lastmod?: string|null, changefreq: string, priority: float}>
     */
    private function events(City $city): array
    {
        $urls = [];

        Event::query()
            ->inCity($city)
            ->readable()
            ->orderBy('id')
            ->select(['id', 'slug', 'updated_at', 'seo', 'is_demo', 'venue_id', 'organizer_id', 'content_details'])
            ->with(['occurrences.venue', 'occurrences.lineups', 'occurrences.ticketTiers', 'venue', 'organizer', 'media', 'ticketTiers'])
            ->withCount('recurrences')
            ->chunk(500, function ($events) use (&$urls): void {
                foreach ($events as $event) {
                    if (! app(EditorialContent::class)->indexable($event)) {
                        continue;
                    }
                    $shared = collect([$event->getAttribute('updated_at'), $event->venue?->getAttribute('updated_at'), $event->organizer?->updated_at])
                        ->merge($event->media->pluck('updated_at'))
                        ->merge($event->ticketTiers->pluck('updated_at'));
                    $registered = $event->content_details['organizer_venue_id'] ?? null;
                    if ($registered !== null) {
                        $shared->push(Venue::whereKey($registered)->value('updated_at'));
                    }
                    $dateEntries = [];
                    foreach ($event->occurrences as $date) {
                        $date->setRelation('event', $event);
                        $modified = $shared->merge([$date->getAttribute('updated_at'), $date->venue?->getAttribute('updated_at')])
                            ->merge($date->lineups->pluck('updated_at'))->merge($date->ticketTiers->pluck('updated_at'))
                            ->filter()->map(fn ($value) => CarbonImmutable::parse($value))->max();
                        $dateEntries[] = ['loc' => EventUrl::occurrence($date),
                            'lastmod' => $this->lastModified($modified),
                            'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY, 'priority' => 0.8];
                    }
                    if ($event->occurrences->count() > 1 || $event->recurrences_count > 0) {
                        $urls[] = ['loc' => route('events.show', $event),
                            'lastmod' => collect($dateEntries)->pluck('lastmod')->max() ?? $this->lastModified($event->getAttribute('updated_at')),
                            'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY, 'priority' => 0.8];
                    }
                    array_push($urls, ...$dateEntries);
                }
            });

        return $urls;
    }

    /**
     * @return list<array{loc: string, lastmod?: string|null, changefreq: string, priority: float}>
     */
    private function venues(City $city): array
    {
        $urls = [];
        foreach (Organizer::query()->where('is_active', true)->where('city_id', $city->id)->orderBy('id')->cursor() as $organizer) {
            $urls[] = ['loc' => route('organizers.show', $organizer), 'lastmod' => $this->lastModified($organizer->updated_at),
                'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY, 'priority' => 0.6];
        }

        Venue::query()
            ->approved()
            ->inCity($city)
            ->orderBy('id')
            ->select(['id', 'slug', 'updated_at', 'seo', 'is_demo'])
            ->chunk(500, function ($venues) use (&$urls): void {
                foreach ($venues as $venue) {
                    if (! app(EditorialContent::class)->indexable($venue)) {
                        continue;
                    }
                    $urls[] = [
                        'loc' => route('venues.show', $venue),
                        'lastmod' => $this->lastModified($venue->getAttribute('updated_at')),
                        'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY,
                        'priority' => 0.6,
                    ];
                }
            });

        return $urls;
    }

    /**
     * @return list<array{loc: string, lastmod?: string|null, changefreq: string, priority: float}>
     */
    private function taxonomies(City $city): array
    {
        $urls = [];

        foreach (Category::query()->active()->ordered()->get() as $category) {
            if (! app(EditorialContent::class)->taxonomyIndexable($category, $city) || ! $this->hasResults($city, ['category' => $category->slug])) {
                continue;
            }
            $urls[] = [
                'loc' => route('events.category', $category),
                'changefreq' => Url::CHANGE_FREQUENCY_DAILY,
                'priority' => 0.7,
            ];
        }

        foreach (Tag::query()->approved()->popular()->orderBy('name')->get() as $tag) {
            if (! app(EditorialContent::class)->taxonomyIndexable($tag, $city) || ! $this->hasResults($city, ['tag' => $tag->slug])) {
                continue;
            }
            $urls[] = [
                'loc' => route('events.tag', $tag),
                'changefreq' => Url::CHANGE_FREQUENCY_WEEKLY,
                'priority' => 0.5,
            ];
        }

        return $urls;
    }

    /**
     * I giorni futuri **che hanno davvero qualcosa**: `/eventi/2026-09-12` è
     * una pagina utile solo se quel giorno esiste un evento (§8.6). Il
     * conteggio per giornata è quello del motore temporale, in una sola
     * interrogazione aggregata.
     *
     * @return list<array{loc: string, lastmod?: string|null, changefreq: string, priority: float}>
     */
    private function days(City $city): array
    {
        $counts = EventOccurrenceQuery::for($city)
            ->nextDays(config()->integer('seo.sitemap.days_ahead'))
            ->countsByBusinessDate();

        $urls = [];

        foreach ($counts as $date => $count) {
            if ($count < 1) {
                continue;
            }

            $urls[] = [
                'loc' => route('events.date', ['date' => $date]),
                'changefreq' => Url::CHANGE_FREQUENCY_DAILY,
                'priority' => 0.6,
            ];
        }

        return $urls;
    }

    /** @param array<string, string> $parameters */
    private function hasResults(City $city, array $parameters): bool
    {
        return app(EventFinder::class)->query($city, EventFilters::fromArray($parameters))->count() > 0;
    }

    private function lastModified(mixed $value): string
    {
        return ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::now())
            ->toAtomString();
    }
}
