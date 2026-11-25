<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SocialPublicationStatus;
use App\Exceptions\SocialStudioException;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Models\User;
use App\Services\Social\SocialGraphic;
use App\Services\Social\SocialStudio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VenueIsolationScenario;

/*
 * P10 — le grafiche social dall'app (W-SO-01…09, la grafica di W-DT-12).
 * Le regole sono quelle della pagina «Social» del pannello, estratte in
 * `SocialStudioDesk`: un pacchetto riguarda un solo giorno, da 1 a 100
 * serate, solo del locale; il titolo scritto a mano solo con una serata. In
 * più la pulizia dei pacchetti vecchi (`SocialBatch::prunable`), che non deve
 * toccare quelli che una pubblicazione usa ancora.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    $this->s = VenueIsolationScenario::make();
    freezeLocal($this->s->city, '2026-09-10 12:00');
    $this->demo = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-20 21:00',
        event: ['is_demo' => true, 'title' => 'Serata dimostrativa del portico'], venue: $this->s->venueA);
    $this->url = '/api/v1/management/venues/'.$this->s->venueA->id.'/social';
});

afterEach(fn () => Carbon::setTestNow());

/** Un pacchetto preparato dall'app per una serata. */
function p10Batch(object $test, EventOccurrence $date, array $extra = []): string
{
    return (string) $test->postJson($test->url.'/batches', ['date' => $date->business_date->format('Y-m-d'), 'occurrences' => [$date->id], ...$extra])
        ->assertCreated()->json('data.id');
}

it('elenca a card le serate del locale nel periodo, con stato, locandina e dimostrativi', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $cancelled = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-22 21:00', occurrence: ['status' => OccurrenceStatus::Cancelled], venue: $this->s->venueA);

    $response = $this->getJson($this->url.'/catalog')->assertOk()
        ->assertJsonPath('meta.period', 'next30')
        ->assertJsonPath('meta.from', '2026-09-10')
        ->assertJsonPath('meta.until', '2026-10-09')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.page_size', 24);
    $cards = collect($response->json('data'))->keyBy('id');
    expect($cards->keys()->all())->toEqualCanonicalizing([$this->s->occurrenceA->id, $this->demo->id, $cancelled->id])
        ->and($cards[$this->demo->id]['demo'])->toBeTrue()
        ->and($cards[$this->demo->id]['selectable'])->toBeTrue()
        ->and($cards[$cancelled->id]['selectable'])->toBeFalse()
        ->and($cards[$cancelled->id]['status']['value'])->toBe('cancelled')
        ->and($cards[$this->s->occurrenceA->id])->toHaveKey('poster');

    $this->getJson($this->url.'/catalog?period=today')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson($this->url.'/catalog?search=dimostrativa')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->demo->id);
    $this->getJson($this->url.'/catalog?period=custom&from=2026-09-21&until=2026-09-11')->assertOk()
        ->assertJsonPath('meta.from', '2026-09-11')->assertJsonPath('meta.until', '2026-09-21')->assertJsonPath('meta.total', 2);
    $this->getJson($this->url.'/catalog?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
});

it('conta le serate del mese nel calendario e apre lo studio sul primo giorno utile', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $days = collect($this->getJson($this->url.'/calendar?month=2026-09')->assertOk()
        ->assertJsonPath('meta.previous', '2026-08')->assertJsonPath('meta.next', '2026-10')
        ->assertJsonCount(42, 'data')->json('data'))->keyBy('date');
    expect($days['2026-09-11']['count'])->toBe(1)
        ->and($days['2026-09-20']['count'])->toBe(1)
        ->and($days['2026-09-12']['count'])->toBe(0)
        ->and($days['2026-09-10']['today'])->toBeTrue();

    // Oggi non c'è niente: si apre sulla prima serata in arrivo, come la pagina.
    $this->getJson($this->url.'/day')->assertOk()
        ->assertJsonPath('meta.date', '2026-09-11')
        ->assertJsonPath('meta.max_selection', 100)
        ->assertJsonPath('data.0.id', $this->s->occurrenceA->id);
    $this->getJson($this->url.'/day?date=2026-09-20')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->demo->id);
});

it('si restringe a un evento del locale e parte dalla sua prossima data', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $event = $this->demo->event_id;

    $this->getJson($this->url.'/day?event='.$event)->assertOk()
        ->assertJsonPath('meta.date', '2026-09-20')
        ->assertJsonPath('meta.event.id', $event);
    $this->getJson($this->url.'/catalog?event='.$event)->assertOk()->assertJsonPath('meta.total', 1);
    // Un evento di un altro locale non si trova.
    $this->getJson($this->url.'/catalog?event='.$this->s->publishedEventB->id)->assertNotFound();
    // Il foglio «Scegli un evento» offre solo gli eventi del locale.
    $choices = collect($this->getJson($this->url.'/events')->assertOk()->json('data.upcoming'))->pluck('id');
    expect($choices)->toContain($event)->not->toContain($this->s->publishedEventB->id);
});

it('prepara il pacchetto di un giorno con le opzioni e lo riapre fra i recenti', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $batch = $this->postJson($this->url.'/batches', [
        'date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id], 'format' => 'square',
        'monochrome' => false, 'show_address' => false, 'show_price' => true, 'image_fit' => 'cover', 'title' => 'Stasera si balla',
    ])->assertCreated()
        ->assertJsonPath('data.date', '2026-09-11')
        ->assertJsonPath('data.format.value', 'square')
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.items.0.occurrence_id', $this->s->occurrenceA->id)
        ->json('data');
    expect($batch['items'][0]['url'])->toContain('/social/grafiche/'.$batch['id'].'/0.jpg')->toContain('signature=')
        ->and($batch['caption'])->toContain('Concerto di musica popolare');

    $saved = SocialBatch::query()->sole();
    expect($saved->venue_id)->toBe($this->s->venueA->id)
        ->and($saved->user_id)->toBe($this->s->editorA->id)
        ->and($saved->options)->toMatchArray(['monochrome' => false, 'show_address' => false, 'show_price' => true, 'image_fit' => 'cover', 'title' => 'Stasera si balla']);
    Storage::disk('local')->assertExists($saved->items[0]['path']);

    $this->getJson($this->url.'/batches')->assertOk()->assertJsonPath('data.0.id', $saved->id)->assertJsonPath('meta.keep', 12);
    $this->getJson($this->url.'/batches/'.$saved->id)->assertOk()->assertJsonPath('data.items.0.filename', $saved->items[0]['filename']);
    $image = $this->get($this->url.'/batches/'.$saved->id.'/images/0')->assertOk();
    expect($image->headers->get('Content-Type'))->toBe('image/jpeg');
    $this->get($this->url.'/batches/'.$saved->id.'/images/5')->assertNotFound();
    $this->get($this->url.'/batches/'.$saved->id.'/zip')->assertOk()->assertDownload('incitta-2026-09-11-square.zip');
});

it('tiene un solo giorno per pacchetto, al massimo cento serate, e il titolo solo con una serata', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $sameDay = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-11 22:30', venue: $this->s->venueA);

    // Una serata di un altro giorno non entra nel pacchetto del giorno scelto.
    $this->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id, $this->demo->id]])->assertForbidden();
    // Il titolo scritto a mano vale per una serata sola.
    $this->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id, $sameDay->id], 'title' => 'Due serate'])
        ->assertUnprocessable()->assertJsonValidationErrors('title', 'error.fields');
    // Il tetto di cento serate.
    $this->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => range(1, 101)])
        ->assertUnprocessable()->assertJsonValidationErrors('occurrences', 'error.fields');
    $this->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('occurrences', 'error.fields');
    expect(SocialBatch::query()->count())->toBe(0);

    $this->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id, $sameDay->id]])
        ->assertCreated()->assertJsonPath('data.count', 2);
});

it('respinge serate e pacchetti di un altro locale, e chi non è del locale', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $mine = p10Batch($this, $this->s->occurrenceA);
    $this->postJson($this->url.'/batches', ['date' => '2026-09-12', 'occurrences' => [$this->s->occurrenceB->id]])->assertForbidden();
    $cancelled = occurrenceAtLocal($this->s->city, $this->s->category, '2026-09-22 21:00', occurrence: ['status' => OccurrenceStatus::Cancelled], venue: $this->s->venueA);
    $this->postJson($this->url.'/batches', ['date' => '2026-09-22', 'occurrences' => [$cancelled->id]])->assertForbidden();

    // Il pacchetto del locale A non si apre dal locale B, nemmeno a chi è referente di B.
    Sanctum::actingAs($this->s->ownerB);
    $other = '/api/v1/management/venues/'.$this->s->venueB->id.'/social/batches/'.$mine;
    $this->getJson($other)->assertNotFound();
    $this->get($other.'/zip')->assertNotFound();
    $this->get($other.'/images/0')->assertNotFound();
    $this->getJson($this->url.'/catalog')->assertNotFound();

    Sanctum::actingAs($this->s->plainUser);
    $this->getJson($this->url.'/batches')->assertNotFound();
});

it('non esiste per l’organizzazione, che ha invece l’anteprima della grafica di ogni data', function (): void {
    $owner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    $event = Event::factory()->create([
        'city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => $this->s->venueB->id,
        'organizer_id' => $organizer->id, 'created_by' => $owner->id, 'title' => 'Rassegna dei Portici',
        'status' => EventStatus::Published, 'published_at' => CarbonImmutable::now('UTC'),
    ]);
    $date = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse('2026-09-20 18:00:00', 'UTC'), 'ends_at' => null, 'doors_at' => null]);
    $base = '/api/v1/management/organizers/'.$organizer->id;
    Sanctum::actingAs($owner);

    $this->getJson($base.'/social/catalog')->assertNotFound();
    $preview = $this->get($base.'/events/'.$event->id.'/dates/'.$date->id.'/social-preview')->assertOk();
    expect($preview->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and(substr((string) $preview->getContent(), 0, 3))->toBe("\xFF\xD8\xFF");
    $this->getJson($base.'/events/'.$event->id.'/dates')->assertOk()->assertJsonPath('data.0.social_preview', true);

    // Nel locale la riga della data non ha il pulsante (c'è lo studio), ma l'anteprima risponde.
    Sanctum::actingAs($this->s->ownerA);
    $venueEvent = '/api/v1/management/venues/'.$this->s->venueA->id.'/events/'.$this->s->publishedEventA->id.'/dates';
    $this->getJson($venueEvent)->assertOk()->assertJsonPath('data.0.social_preview', false);
    $this->get($venueEvent.'/'.$this->s->occurrenceA->id.'/social-preview')->assertOk();
    // Una data di un altro evento, o di un altro locale, no.
    $this->get($venueEvent.'/'.$this->s->occurrenceB->id.'/social-preview')->assertNotFound();
    $this->get('/api/v1/management/venues/'.$this->s->venueA->id.'/events/'.$this->s->publishedEventB->id.'/dates/'.$this->s->occurrenceB->id.'/social-preview')->assertNotFound();
});

it('accende «Grafiche social» nella scheda dell’evento del locale', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $this->getJson('/api/v1/management/venues/'.$this->s->venueA->id.'/events/'.$this->s->publishedEventA->id)
        ->assertOk()->assertJsonPath('data.links.social', true);
});

/** Un pacchetto scritto a mano, con un'immagine sul disco, creato `$minutesAgo` minuti fa. */
function p10StoredBatch(object $test, ?int $venueId, int $minutesAgo, string $date = '2026-09-11'): SocialBatch
{
    $id = (string) Str::uuid();
    $path = 'social/'.$id.'/01-serata.jpg';
    Storage::disk('local')->put($path, 'jpeg');
    $batch = new SocialBatch(['city_id' => $test->s->city->id, 'venue_id' => $venueId, 'date' => $date, 'format' => 'portrait', 'options' => [], 'items' => [['path' => $path, 'filename' => '01-serata.jpg', 'title' => 'Serata', 'occurrence_id' => 1, 'event_id' => 1]]]);
    $batch->id = $id;
    $batch->created_at = now()->subMinutes($minutesAgo);
    $batch->updated_at = $batch->created_at;
    $batch->save();

    return $batch;
}

it('la pulizia tiene i dodici pacchetti più recenti di ogni locale e quelli che una pubblicazione usa ancora', function (): void {
    $venueA = collect(range(1, 15))->map(fn (int $i): SocialBatch => p10StoredBatch($this, $this->s->venueA->id, $i));
    $venueB = collect(range(1, 3))->map(fn (int $i): SocialBatch => p10StoredBatch($this, $this->s->venueB->id, $i));
    // La redazione della città: dodici recenti, più tre vecchi con pubblicazioni diverse e uno vecchio senza.
    $editorial = collect(range(1, 12))->map(fn (int $i): SocialBatch => p10StoredBatch($this, null, $i));
    $connection = SocialConnection::create(['city_id' => $this->s->city->id, 'page_id' => '123', 'access_token' => 'secret', 'facebook_enabled' => true, 'automatic' => true, 'publish_time' => '09:00', 'caption' => ':data', 'verified_at' => now()]);
    $publication = fn (SocialBatch $batch, SocialPublicationStatus $status) => SocialPublication::create([
        'social_batch_id' => $batch->id, 'social_connection_id' => $connection->id, 'platform' => 'facebook', 'part' => 0,
        'dedupe_key' => $connection->id.':facebook:'.$batch->id.':0', 'status' => $status->value,
    ]);
    $scheduled = p10StoredBatch($this, null, 100, '2026-08-01');
    $publication($scheduled, SocialPublicationStatus::Scheduled);
    $failed = p10StoredBatch($this, null, 101, '2026-08-01');
    $publication($failed, SocialPublicationStatus::Failed);
    $publishedToday = p10StoredBatch($this, null, 102, '2026-09-10');
    $publication($publishedToday, SocialPublicationStatus::Published);
    $publishedLongAgo = p10StoredBatch($this, null, 103, '2026-08-01');
    $publication($publishedLongAgo, SocialPublicationStatus::Published);
    $plainOld = p10StoredBatch($this, null, 104, '2026-08-01');

    $this->artisan('model:prune', ['--model' => SocialBatch::class])->assertSuccessful();

    $kept = SocialBatch::query()->pluck('id')->all();
    $gone = [...$venueA->slice(12)->pluck('id')->all(), $publishedLongAgo->id, $plainOld->id];
    expect($kept)->toEqualCanonicalizing([
        ...$venueA->take(12)->pluck('id')->all(),
        ...$venueB->pluck('id')->all(),
        ...$editorial->pluck('id')->all(),
        $scheduled->id, $failed->id, $publishedToday->id,
    ]);
    foreach ($gone as $id) {
        Storage::disk('local')->assertMissing('social/'.$id.'/01-serata.jpg');
    }
    Storage::disk('local')->assertExists('social/'.$scheduled->id.'/01-serata.jpg');
    Storage::disk('local')->assertExists('social/'.$venueA->first()->id.'/01-serata.jpg');
    // La pubblicazione del giorno resta con il suo pacchetto; quella vecchia resta nello storico, senza pacchetto.
    expect(SocialPublication::query()->where('social_batch_id', $publishedToday->id)->exists())->toBeTrue()
        ->and(SocialPublication::query()->where('social_batch_id', $publishedLongAgo->id)->exists())->toBeFalse()
        ->and(SocialPublication::query()->where('dedupe_key', $connection->id.':facebook:'.$publishedLongAgo->id.':0')->value('social_batch_id'))->toBeNull()
        ->and(SocialPublication::query()->count())->toBe(4);
});

it('pianifica la pulizia dei pacchetti ogni notte', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'SocialBatch'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->getExpression())->toBe('40 4 * * *');
});

/**
 * Sostituisce il disegno delle grafiche (`SocialGraphic` è final, non si
 * simula con Mockery): `$render` riceve gli argomenti di `render`.
 */
function p10Graphic(Closure $render): void
{
    app()->instance(SocialGraphic::class, new class($render)
    {
        public function __construct(private readonly Closure $render) {}

        public function render(mixed ...$arguments): string
        {
            return ($this->render)(...$arguments);
        }
    });
}

/*
 * Il disco dell'hosting (10 GB): dopo ogni pacchetto di un locale la pulizia
 * gira subito per quel locale, e un locale prepara al massimo trenta
 * pacchetti nelle ultime 24 ore. Senza i due tetti, dieci pacchetti al
 * minuto da cento immagini restavano sul disco fino alle 04:40.
 */
it('pulisce subito i pacchetti del locale oltre i dodici e ferma il locale a trenta pacchetti in 24 ore', function (): void {
    p10Graphic(fn (): string => 'jpeg');
    $old = collect(range(1, 13))->map(fn (int $i): SocialBatch => p10StoredBatch($this, $this->s->venueA->id, 100 + $i));
    $other = p10StoredBatch($this, $this->s->venueB->id, 500);
    Sanctum::actingAs($this->s->editorA);

    $first = p10Batch($this, $this->s->occurrenceA);

    // 13 vecchi + 1 nuovo: i due più vecchi se ne vanno subito, con le immagini; l'altro locale non si tocca.
    expect(SocialBatch::query()->where('venue_id', $this->s->venueA->id)->count())->toBe(12)
        ->and(SocialBatch::query()->whereKey($first)->exists())->toBeTrue()
        ->and(SocialBatch::query()->whereKey($other->id)->exists())->toBeTrue();
    foreach ($old->slice(11) as $gone) {
        expect(SocialBatch::query()->whereKey($gone->id)->exists())->toBeFalse();
        Storage::disk('local')->assertMissing('social/'.$gone->id.'/01-serata.jpg');
    }
    Storage::disk('local')->assertExists('social/'.$old->first()->id.'/01-serata.jpg');

    // Fino a trenta pacchetti in 24 ore (dieci secondi fra uno e l'altro, sotto il limite di dieci al minuto).
    foreach (range(2, SocialStudio::DAILY_LIMIT_PER_VENUE) as $ignored) {
        $this->travel(10)->seconds();
        p10Batch($this, $this->s->occurrenceA);
    }
    $this->travel(10)->seconds();
    $this->postJson($this->url.'/batches', ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id]])
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertJsonPath('error.message', __('social.daily_limit', ['count' => 30]))
        ->assertJsonPath('error.fields.generate.0', __('social.daily_limit', ['count' => 30]));
    expect(SocialBatch::query()->where('venue_id', $this->s->venueA->id)->count())->toBe(12);

    // Il tetto è del locale: il locale B prepara ancora; e il giorno dopo anche il locale A.
    Sanctum::actingAs($this->s->ownerB);
    $this->postJson('/api/v1/management/venues/'.$this->s->venueB->id.'/social/batches', ['date' => '2026-09-12', 'occurrences' => [$this->s->occurrenceB->id]])->assertCreated();
    Sanctum::actingAs($this->s->editorA);
    $this->travel(1)->days();
    p10Batch($this, $this->s->occurrenceA);
});

/*
 * Solo gli errori previsti dello studio arrivano al client: un errore
 * qualunque (qui uno del database) va al gestore degli errori e diventa un
 * 500 senza messaggio, non un 422 con l'SQL dentro.
 */
it('restituisce come 422 solo gli errori previsti della grafica, il resto è un errore interno senza dettagli', function (): void {
    config(['app.debug' => false]);
    Sanctum::actingAs($this->s->editorA);
    $body = ['date' => '2026-09-11', 'occurrences' => [$this->s->occurrenceA->id]];
    $preview = '/api/v1/management/venues/'.$this->s->venueA->id.'/events/'.$this->s->publishedEventA->id.'/dates/'.$this->s->occurrenceA->id.'/social-preview';

    p10Graphic(fn () => throw SocialStudioException::textOverflow());
    $this->postJson($this->url.'/batches', $body)->assertUnprocessable()
        ->assertJsonPath('error.fields.generate.0', __('social.text_overflow'));
    $this->getJson($preview)->assertUnprocessable()->assertJsonPath('error.fields.preview.0', __('social.text_overflow'));

    p10Graphic(fn () => throw new RuntimeException('SQLSTATE[42S02]: select * from `segreta`'));
    Cache::flush();
    foreach ([$this->postJson($this->url.'/batches', $body), $this->getJson($preview)] as $response) {
        $response->assertServerError()->assertJsonPath('error.code', 'SERVER_ERROR');
        expect((string) $response->getContent())->not->toContain('SQLSTATE')->not->toContain('segreta');
    }
    expect(SocialBatch::query()->count())->toBe(0);
});
