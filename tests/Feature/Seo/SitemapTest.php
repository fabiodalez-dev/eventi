<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Venue;

/**
 * `sitemap.xml` a indice e `robots.txt` (§12.2).
 *
 * L'XML si rilegge davvero con `simplexml`: una mappa che il parser rifiuta è
 * una mappa che nessun motore leggerà, e a occhio non si distingue da una
 * buona.
 */

/**
 * @return list<string>
 */
function sitemapUrls(string $xml): array
{
    $document = simplexml_load_string($xml);

    expect($document)->not->toBeFalse('XML non valido');

    $urls = [];

    foreach ($document->url as $node) {
        $urls[] = (string) $node->loc;
    }

    foreach ($document->sitemap as $node) {
        $urls[] = (string) $node->loc;
    }

    return $urls;
}

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    freezeLocal($this->city, '2026-09-01 12:00');
});

it('serve un indice che rimanda a una mappa per sezione', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    $risposta = $this->get('/sitemap.xml')->assertOk();

    expect($risposta->headers->get('Content-Type'))->toContain('application/xml');

    $sezioni = sitemapUrls($risposta->getContent());

    expect($sezioni)->not->toBeEmpty();

    foreach ($sezioni as $url) {
        expect($url)->toMatch('#/sitemap-[a-z]+-\d+\.xml$#');
    }

    // Ogni riga dell'indice porta a una mappa che esiste davvero.
    foreach ($sezioni as $url) {
        $this->get($url)->assertOk();
    }
});

it('elenca gli eventi pubblicati e non le bozze', function (): void {
    $pubblicato = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30')->event;

    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:30', event: [
        'status' => EventStatus::Draft,
        'published_at' => null,
    ])->event;

    $urls = sitemapUrls($this->get('/sitemap-eventi-1.xml')->assertOk()->getContent());

    expect($urls)->toContain(route('events.occurrence', ['slug' => $pubblicato->slug, 'occurrence' => $pubblicato->occurrences()->first()->id]))
        ->not->toContain(route('events.show', $bozza));
});

it('elenca i locali approvati', function (): void {
    $approvato = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30')->event->venue;
    $inAttesa = Venue::factory()->create([
        'city_id' => $this->city->getKey(),
        'status' => VenueStatus::Pending,
    ]);

    $urls = sitemapUrls($this->get('/sitemap-locali-1.xml')->assertOk()->getContent());

    expect($urls)->toContain(route('venues.show', $approvato))
        ->not->toContain(route('venues.show', $inAttesa));
});

it('elenca categorie e tag', function (): void {
    $categoria = Category::factory()->create(['name' => 'Teatro', 'slug' => 'teatro']);
    $tag = Tag::factory()->approved()->create(['name' => 'Jazz', 'slug' => 'jazz', 'usage_count' => 3]);
    occurrenceAtLocal($this->city, $categoria, '2026-09-12 21:30')->event->tags()->attach($tag);

    $urls = sitemapUrls($this->get('/sitemap-tassonomie-1.xml')->assertOk()->getContent());

    expect($urls)->toContain(route('events.category', $categoria))
        ->toContain(route('events.tag', $tag));
});

it('elenca solo i giorni futuri che hanno davvero qualcosa', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    $urls = sitemapUrls($this->get('/sitemap-giorni-1.xml')->assertOk()->getContent());

    expect($urls)->toContain(route('events.date', ['date' => '2026-09-12']))
        ->not->toContain(route('events.date', ['date' => '2026-09-13']));
});

it('risponde 404 a una sezione inesistente o a una pagina oltre la fine', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:30');

    $this->get('/sitemap-inventata-1.xml')->assertNotFound();
    $this->get('/sitemap-eventi-9.xml')->assertNotFound();
});

it('spezza una sezione in piu mappe quando supera la soglia', function (): void {
    config()->set('seo.sitemap.chunk', 2);

    foreach (range(1, 5) as $giorno) {
        occurrenceAtLocal($this->city, $this->category, sprintf('2026-09-%02d 21:30', 10 + $giorno));
    }

    $sezioni = sitemapUrls($this->get('/sitemap.xml')->assertOk()->getContent());
    $eventi = array_values(array_filter($sezioni, static fn (string $url): bool => str_contains($url, 'sitemap-eventi-')));

    expect($eventi)->toHaveCount(3);

    expect(sitemapUrls($this->get('/sitemap-eventi-1.xml')->getContent()))->toHaveCount(2)
        ->and(sitemapUrls($this->get('/sitemap-eventi-3.xml')->getContent()))->toHaveCount(1);
});

it('serve un robots.txt che dichiara la mappa e chiude i pannelli', function (): void {
    $risposta = $this->get('/robots.txt')->assertOk();

    expect($risposta->headers->get('Content-Type'))->toContain('text/plain');

    $corpo = $risposta->getContent();

    expect($corpo)->toContain('User-agent: *')
        ->toContain('Disallow: /admin')
        ->toContain('Disallow: /gestione')
        ->toContain('Sitemap: '.route('sitemap.index'));
});
