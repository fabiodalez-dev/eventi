<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Page;
use App\Models\Tag;
use App\Models\Venue;
use Illuminate\Support\Facades\Route;

/**
 * Gli endpoint che permettono a un'applicazione di costruire ogni schermata
 * senza mai leggere dal sito, e la garanzia che restino di sola lettura.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

// ------------------------------------------------------------- tassonomie

it('elenca le categorie con i tre flag che governano il motore', function (): void {
    $payload = $this->getJson('/api/v1/categories')->assertOk()->json('data');

    expect($payload)->not->toBeEmpty();

    /*
     * I tre flag di §7.4 non sono decorazione: senza `supports_ongoing` un
     * client non sa che una mostra non compare mai in "in corso adesso", e
     * finirebbe per dedurselo da solo — che e' il modo in cui nascono due
     * definizioni divergenti della stessa finestra temporale (§2.5).
     */
    foreach ($payload as $categoria) {
        expect($categoria)->toHaveKeys([
            'slug', 'name', 'supports_ongoing', 'is_nightlife',
            'default_duration_minutes', 'upcoming_count',
        ]);
    }
});

it('conta per citta le date sotto una categoria', function (): void {
    $slug = Category::query()->where('is_active', true)->value('slug');

    $payload = $this->getJson('/api/v1/categories/'.$slug)->assertOk()->json('data');

    expect($payload['slug'])->toBe($slug)
        ->and($payload['upcoming_count'])->toBeInt();
});

it('risponde 404 su una categoria che non esiste', function (): void {
    $this->getJson('/api/v1/categories/categoria-inventata')->assertNotFound();
});

it('elenca i tag con quante volte sono usati', function (): void {
    Tag::factory()->create(['is_approved' => true, 'usage_count' => 7]);

    $payload = $this->getJson('/api/v1/tags')->assertOk()->json('data');

    expect($payload)->not->toBeEmpty()
        ->and($payload[0])->toHaveKeys(['slug', 'name', 'usage_count']);
});

it('non elenca i tag non approvati', function (): void {
    $nascosto = Tag::factory()->create(['is_approved' => false]);

    $slugs = collect($this->getJson('/api/v1/tags')->assertOk()->json('data'))->pluck('slug');

    expect($slugs)->not->toContain($nascosto->slug);
});

// ----------------------------------------------------------- pagine legali

it('espone le pagine legali dentro l applicazione', function (): void {
    Page::factory()->create(['slug' => 'privacy', 'is_published' => true]);

    $elenco = $this->getJson('/api/v1/pages')->assertOk()->json('data');

    expect(collect($elenco)->pluck('slug'))->toContain('privacy');
});

it('restituisce il corpo sia grezzo sia reso', function (): void {
    Page::factory()->create([
        'slug' => 'privacy',
        'is_published' => true,
        'body' => "# Informativa\n\nNessuna coordinata viene salvata.",
    ]);

    $pagina = $this->getJson('/api/v1/pages/privacy')->assertOk()->json('data');

    expect($pagina['body'])->toContain('# Informativa')
        ->and($pagina['body_html'])->toContain('<h1')
        ->and($pagina['body_html'])->toContain('Nessuna coordinata');
});

it('non espone una pagina non pubblicata', function (): void {
    Page::factory()->create(['slug' => 'bozza', 'is_published' => false]);

    $this->getJson('/api/v1/pages/bozza')->assertNotFound();
});

// -------------------------------------------------------- scoperta e misure

it('elenca i comuni dove c e davvero qualcosa in programma', function (): void {
    $payload = $this->getJson('/api/v1/areas')->assertOk()->json('data');

    foreach ($payload as $area) {
        expect($area)->toHaveKeys(['name', 'upcoming_count'])
            /* Un comune con locali ma senza date non serve a nessun filtro:
               si contano le occorrenze, non le sale. */
            ->and($area['upcoming_count'])->toBeGreaterThan(0);
    }
});

it('riporta le misure con cui si giudica il catalogo', function (): void {
    $stats = $this->getJson('/api/v1/stats')->assertOk()->json('data');

    expect($stats)->toHaveKeys([
        'city', 'upcoming_occurrences', 'today', 'tonight', 'weekend',
        'covered_days_next_14', 'active_venues',
    ])->and($stats['covered_days_next_14'])->toBeLessThanOrEqual(14);
});

// ------------------------------------------------------- date e archivio

it('elenca tutte le date future di un evento, non solo la prima', function (): void {
    $evento = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'status' => EventStatus::Published,
    ]);

    // Tre repliche dello STESSO evento: e' il caso in cui §15.3 apre il
    // selettore delle date invece di salvare in silenzio.
    foreach ([3, 10, 17] as $fraQuantiGiorni) {
        EventOccurrence::factory()->for($evento)->create([
            'starts_at' => now()->addDays($fraQuantiGiorni)->setTime(21, 0),
        ]);
    }

    $risposta = $this->getJson('/api/v1/events/'.$evento->slug.'/occurrences')->assertOk();

    $risposta->assertJsonStructure(['data', 'meta' => ['next_cursor', 'has_more']]);

    expect($risposta->json('data'))->toHaveCount(3);
});

it('espone l archivio delle date passate di un locale', function (): void {
    $locale = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $evento = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'venue_id' => $locale->getKey(),
        'category_id' => $this->category->getKey(),
        'status' => EventStatus::Published,
    ]);

    EventOccurrence::factory()->for($evento)->create(['starts_at' => now()->subDays(20)->setTime(21, 0)]);
    EventOccurrence::factory()->for($evento)->create(['starts_at' => now()->addDays(5)->setTime(21, 0)]);

    $risposta = $this->getJson('/api/v1/venues/'.$locale->slug.'/past')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);

    // Solo la data passata: l'archivio guarda indietro, non avanti.
    expect($risposta->json('data'))->toHaveCount(1);
});

// ------------------------------------------------------- le due garanzie

it('non espone mai il punteggio redazionale', function (): void {
    foreach (['/api/v1/categories', '/api/v1/tags', '/api/v1/areas', '/api/v1/stats', '/api/v1/pages'] as $url) {
        $corpo = $this->getJson($url)->assertOk()->getContent();

        expect($corpo)->not->toContain('editorial_score');
    }
});

/*
 * L'API resta di SOLA LETTURA: e' una decisione del committente, non un
 * limite temporaneo dell'implementazione.
 *
 * Questo test enumera le rotte davvero registrate e verifica che i verbi che
 * scrivono esistano solo dove esistevano gia: autenticazione, area personale,
 * proposte di eventi e segnalazioni. Se qualcuno aggiunge un POST /v1/events
 * per comodita, qui diventa rosso — e la conversazione avviene prima che
 * l'endpoint sia pubblico, non dopo.
 */
it('non ha aperto nessuna rotta di scrittura', function (): void {
    $consentiti = ['auth', 'me', 'submissions', 'reports'];

    $scritture = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->uri(), 'api/v1/'))
        ->filter(fn ($route): bool => (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']))
        ->map(fn ($route): string => (string) $route->uri())
        ->reject(function (string $uri) use ($consentiti): bool {
            $segmento = explode('/', $uri)[2] ?? '';

            return in_array($segmento, $consentiti, strict: true);
        })
        ->values()
        ->all();

    expect($scritture)->toBe([]);
});
