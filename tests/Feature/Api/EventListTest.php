<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Models\Event;
use App\Models\Lineup;
use App\Models\SavedEvent;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
 * §13.2: «Restituisce occorrenze, non eventi». Un evento con tre date è tre
 * elementi, perché è la data che si mette in agenda.
 */
it('restituisce occorrenze e non eventi', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    $first = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Rassegna'], venue: $venue);

    $first->event->occurrences()->create([
        'starts_at' => localInstant($city, '2026-09-07 21:00:00')->utc(),
        'ends_at' => null,
        'is_all_day' => false,
    ]);

    $data = $this->getJson('/api/v1/events')->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and(array_unique(array_column($data, 'event_id')))->toHaveCount(1)
        ->and(array_column($data, 'occurrence_id'))->toHaveCount(2);
});

it('porta in ogni elemento i campi che §13.2 elenca', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', '2026-09-06 23:30:00', event: [
        'title' => 'Concerto in cortile',
        'price_type' => PriceType::Ticket,
        'price_min' => 8,
    ]);

    $item = $this->getJson('/api/v1/events')->assertOk()->json('data.0');

    expect($item)->toHaveKeys([
        'occurrence_id', 'event_id', 'starts_at', 'effective_ends_at', 'ends_at_estimated',
        'business_date', 'status', 'title', 'poster', 'venue', 'category', 'price', 'updated_at',
    ])
        ->and($item['starts_at'])->toBe('2026-09-06T21:00:00+02:00')
        ->and($item['business_date'])->toBe('2026-09-06')
        ->and($item['ends_at_estimated'])->toBeFalse()
        ->and($item['price']['type'])->toBe('ticket')
        ->and((float) $item['price']['min'])->toBe(8.0)
        ->and($item['venue'])->toHaveKeys(['id', 'slug', 'name', 'municipality', 'lat', 'lng']);
});

/*
 * §8.3: senza `ends_at` la fine è dedotta dalla durata della categoria. Il
 * client deve poterlo dire: «finisce alle 23» e «finirà verso le 23» non sono
 * la stessa informazione.
 */
it('dichiara quando la fine è stimata', function (): void {
    $city = testCity();
    $category = testCategory(['default_duration_minutes' => 120]);

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $item = $this->getJson('/api/v1/events')->assertOk()->json('data.0');

    expect($item['ends_at'])->toBeNull()
        ->and($item['ends_at_estimated'])->toBeTrue()
        ->and($item['effective_ends_at'])->toBe('2026-09-06T23:00:00+02:00');
});

/*
 * §13.2 non elenca `editorial_score` fra i campi, e non è una dimenticanza:
 * è il criterio con cui la redazione ordina, e pubblicarlo insegnerebbe a
 * chiunque come farsi spingere in alto.
 */
it('non espone mai il punteggio redazionale', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['editorial_score' => 99]);

    $response = $this->getJson('/api/v1/events?sort=relevance')->assertOk();

    /*
     * Si guardano le **chiavi**, ricorsivamente, e non il testo della
     * risposta: cercare il valore "99" come sottostringa faceva fallire il
     * test ogni volta che un identificativo o una coordinata contenevano
     * quelle due cifre, cioè per motivi che non hanno niente a che vedere con
     * ciò che il test difende.
     */
    $keys = static function (mixed $value) use (&$keys): array {
        if (! is_array($value)) {
            return [];
        }

        $found = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $found[] = $key;
            }

            $found = [...$found, ...$keys($item)];
        }

        return $found;
    };

    expect($keys($response->json()))->not->toContain('editorial_score');

    // E il punteggio c'è davvero, altrimenti il test non difenderebbe niente.
    expect(Event::query()->value('editorial_score'))->toBe(99);
});

/*
 * §15.8: senza autenticazione `is_saved` è **assente**, non `false`. Un
 * client che leggesse `false` mostrerebbe "non salvato" là dove la verità è
 * che il server non lo sa.
 */
it('omette is_saved a chi non è autenticato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');

    $item = $this->getJson('/api/v1/events')->assertOk()->json('data.0');

    expect($item)->not->toHaveKey('is_saved');
});

it('dice a chi è autenticato che cosa ha salvato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $salvata = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Salvata']);
    occurrenceAtLocal($city, $category, '2026-09-07 21:00:00', event: ['title' => 'Non salvata']);

    $user = User::factory()->create();
    SavedEvent::create(['user_id' => $user->getKey(), 'occurrence_id' => $salvata->getKey()]);

    $data = $this->actingAs($user, 'sanctum')->getJson('/api/v1/events')->assertOk()->json('data');

    $saved = array_column($data, 'is_saved', 'title');

    expect($saved)->toBe(['Salvata' => true, 'Non salvata' => false]);
});

/*
 * §13.2: «`preset=starting_soon` e `preset=ongoing` devono esistere in API».
 * Il test non verifica che rispondano: verifica che rispondano **la stessa
 * cosa** del motore temporale, che è l'unico posto in cui quelle due finestre
 * sono definite (§8.4).
 */
it('risponde a preset=ongoing con le stesse date del motore temporale', function (): void {
    $city = testCity();
    $category = testCategory(['default_duration_minutes' => 180]);
    $mostra = testCategory(['name' => 'Arte e mostre', 'supports_ongoing' => false, 'default_duration_minutes' => 600]);

    freezeLocal($city, '2026-09-05 22:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', event: ['title' => 'Concerto iniziato']);
    occurrenceAtLocal($city, $category, '2026-09-05 23:30:00', event: ['title' => 'Concerto non iniziato']);
    occurrenceAtLocal($city, $mostra, '2026-09-05 10:00:00', '2026-09-05 23:00:00', event: ['title' => 'Mostra aperta']);

    $attese = idsOf(EventOccurrenceQuery::for($city)->ongoing()->get());

    $data = $this->getJson('/api/v1/events?preset=ongoing')->assertOk()->json('data');

    expect(array_column($data, 'occurrence_id'))->toBe($attese)
        ->and(array_column($data, 'title'))->toBe(['Concerto iniziato']);
});

it('risponde a preset=starting_soon con le stesse date del motore temporale', function (): void {
    $city = testCity(['starting_soon_minutes' => 180]);
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 21:00:00', event: ['title' => 'Fra un\'ora']);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Domani']);

    $attese = idsOf(EventOccurrenceQuery::for($city)->startingSoon()->get());

    $data = $this->getJson('/api/v1/events?preset=starting_soon')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and(array_column($data, 'occurrence_id'))->toBe($attese)
        ->and($data[0]['title'])->toBe('Fra un\'ora');
});

it('risponde agli altri preset con le stesse date del sito', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 15:00:00', event: ['title' => 'Oggi pomeriggio']);
    occurrenceAtLocal($city, $category, '2026-09-05 21:30:00', event: ['title' => 'Stasera']);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Domani']);

    foreach (['today' => 2, 'tonight' => 1, 'tomorrow' => 1, 'week' => 3] as $preset => $quanti) {
        $data = $this->getJson('/api/v1/events?preset='.$preset)->assertOk()->json('data');

        expect($data)->toHaveCount($quanti, "preset={$preset}");
    }
});

it('filtra per categoria, tag, prezzo e fascia oraria', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-05 09:00:00');

    $gratis = occurrenceAtLocal($city, $musica, '2026-09-06 21:00:00', event: [
        'title' => 'Concerto gratuito',
        'price_type' => PriceType::Free,
        'price_min' => null,
    ]);

    occurrenceAtLocal($city, $teatro, '2026-09-06 11:00:00', event: [
        'title' => 'Matinée a pagamento',
        'price_type' => PriceType::Ticket,
        'price_min' => 25,
    ]);

    $tag = Tag::factory()->create(['name' => 'cantautorato', 'is_approved' => true]);
    $gratis->event->tags()->attach($tag);

    expect(array_column($this->getJson('/api/v1/events?categories[]=musica-dal-vivo')->json('data'), 'title'))
        ->toBe(['Concerto gratuito']);

    expect(array_column($this->getJson('/api/v1/events?price=free')->json('data'), 'title'))
        ->toBe(['Concerto gratuito']);

    expect(array_column($this->getJson('/api/v1/events?price=paid')->json('data'), 'title'))
        ->toBe(['Matinée a pagamento']);

    expect(array_column($this->getJson('/api/v1/events?price=max:10')->json('data'), 'title'))
        ->toBe(['Concerto gratuito']);

    expect(array_column($this->getJson('/api/v1/events?tags[]=cantautorato')->json('data'), 'title'))
        ->toBe(['Concerto gratuito']);

    expect(array_column($this->getJson('/api/v1/events?time_of_day=day')->json('data'), 'title'))
        ->toBe(['Matinée a pagamento']);
});

it('accetta l\'intervallo di date e la data singola', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Sei']);
    occurrenceAtLocal($city, $category, '2026-09-08 21:00:00', event: ['title' => 'Otto']);
    occurrenceAtLocal($city, $category, '2026-09-20 21:00:00', event: ['title' => 'Venti']);

    expect(array_column($this->getJson('/api/v1/events?date=2026-09-08')->json('data'), 'title'))->toBe(['Otto']);

    expect(array_column($this->getJson('/api/v1/events?from=2026-09-06&to=2026-09-08')->json('data'), 'title'))
        ->toBe(['Sei', 'Otto']);
});

it('misura la distanza quando arriva una posizione', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $vicino = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'lat' => 45.4064,
        'lng' => 11.8768,
    ]);

    $lontano = Venue::factory()->approved()->create([
        'city_id' => $city->getKey(),
        'lat' => 45.5500,
        'lng' => 11.5500,
    ]);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Vicino'], venue: $vicino);
    occurrenceAtLocal($city, $category, '2026-09-06 20:00:00', event: ['title' => 'Lontano'], venue: $lontano);

    $data = $this->getJson('/api/v1/events?near=45.4064,11.8768&radius_km=5')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['title'])->toBe('Vicino')
        ->and($data[0]['distance_m'])->toBeLessThan(100);

    $ordinati = $this->getJson('/api/v1/events?near=45.4064,11.8768&radius_km=50&sort=distance')->assertOk()->json('data');

    expect(array_column($ordinati, 'title'))->toBe(['Vicino', 'Lontano']);
});

it('restringe al rettangolo inquadrato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $dentro = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'lat' => 45.4064, 'lng' => 11.8768]);
    $fuori = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'lat' => 46.0, 'lng' => 12.5]);

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Dentro'], venue: $dentro);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Fuori'], venue: $fuori);

    $data = $this->getJson('/api/v1/events?bbox=11.80,45.35,11.95,45.45')->assertOk()->json('data');

    expect(array_column($data, 'title'))->toBe(['Dentro']);
});

it('cerca nel testo e aggiunge le inclusioni chieste', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Concerto per arpa']);
    occurrenceAtLocal($city, $category, '2026-09-06 22:00:00', event: ['title' => 'Proiezione muta']);

    $tag = Tag::factory()->create(['name' => 'arpa', 'is_approved' => true]);
    $occorrenza->event->tags()->attach($tag);

    Lineup::create([
        'occurrence_id' => $occorrenza->getKey(),
        'name' => 'Duo d\'arpa',
        'sort_order' => 0,
    ]);

    $data = $this->getJson('/api/v1/events?q=arpa&include=tags,lineup')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['title'])->toBe('Concerto per arpa')
        ->and(array_column($data[0]['tags'], 'slug'))->toBe(['arpa'])
        ->and(array_column($data[0]['lineup'], 'name'))->toBe(['Duo d\'arpa']);
});

it('restituisce solo ciò che è cambiato dopo updated_since', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $vecchia = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Vecchia']);
    $vecchia->forceFill(['updated_at' => Carbon::parse('2026-09-01 08:00:00', 'UTC')])->saveQuietly();
    $vecchia->event->forceFill(['updated_at' => Carbon::parse('2026-09-01 08:00:00', 'UTC')])->saveQuietly();

    occurrenceAtLocal($city, $category, '2026-09-07 21:00:00', event: ['title' => 'Aggiornata']);

    $data = $this->getJson('/api/v1/events?updated_since=2026-09-03T00:00:00%2B02:00')->assertOk()->json('data');

    expect(array_column($data, 'title'))->toBe(['Aggiornata']);
});

it('non mostra bozze, eventi cestinati né città altrui', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Pubblicato']);
    occurrenceAtLocal($city, $category, '2026-09-06 22:00:00', event: [
        'title' => 'Bozza',
        'status' => EventStatus::Draft,
    ]);

    $cestinata = occurrenceAtLocal($city, $category, '2026-09-06 23:00:00', event: ['title' => 'Cestinato']);
    $cestinata->event->delete();

    $data = $this->getJson('/api/v1/events')->assertOk()->json('data');

    expect(array_column($data, 'title'))->toBe(['Pubblicato']);
});
