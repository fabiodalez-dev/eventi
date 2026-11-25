<?php

declare(strict_types=1);

use App\Enums\ImportSourceType;
use App\Filament\Venue\Pages\CalendarImport;
use App\Models\Event;
use App\Models\ImportSource;
use App\Models\Organizer;
use App\Services\Import\CsvImportDriver;
use App\Services\Import\HostResolver;
use App\Services\Import\VenueCalendarImport;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\IcsFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * P9 — il calendario collegato dall'app (W-IM-01…07). Le regole sono quelle
 * della pagina «Calendario collegato», estratte in `VenueCalendarImport`:
 * qui si prova che l'API le segue (anteprima prima di collegare, indirizzi
 * interni respinti, solo il referente) e che il CSV caricato dall'app non
 * lascia file in giro sul server.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->s = VenueIsolationScenario::make();
    $this->venue = $this->s->venueA;
    $this->url = '/api/v1/management/venues/'.$this->venue->id.'/import';
    $this->folder = CsvImportDriver::UPLOAD_DIRECTORY.'/'.$this->venue->id;
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

function p9Csv(string $title = 'Jazz in cortile'): UploadedFile
{
    $day = now()->addMonth()->format('Y-m-d');

    return UploadedFile::fake()->createWithContent('stagione.csv', "titolo,inizio,fine,id\n{$title},{$day} 21:00,{$day} 23:00,J1\nRiunione staff,{$day} 10:00,,R1\n");
}

/** Un'anteprima ICS riuscita: il segno che serve per collegare. */
function p9Preview(object $test, string $url = IcsFixtures::URL): string
{
    return (string) $test->postJson($test->url.'/preview', ['type' => 'ics', 'url' => $url, 'exclude_keywords' => ['riunione', 'chiuso']])
        ->assertOk()->json('data.preview_token');
}

it('mostra al referente il modulo vuoto, con le parole escluse predefinite e i limiti', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.source', null)
        ->assertJsonPath('data.form.type', 'ics')
        ->assertJsonPath('data.form.url', null)
        ->assertJsonPath('data.form.exclude_keywords', config('import.exclude_keywords'))
        ->assertJsonPath('data.limits.max_keywords', 100)
        ->assertJsonPath('data.limits.max_file_bytes', config('import.max_bytes'));
});

it('non apre il calendario al collaboratore, a chi non è del locale né all’organizzazione', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url.'/preview', ['type' => 'ics', 'url' => IcsFixtures::URL])->assertForbidden();

    Sanctum::actingAs($this->s->ownerB);
    $this->getJson($this->url)->assertNotFound();

    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $this->s->ownerA->id, 'is_active' => true]);
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson('/api/v1/management/organizers/'.$organizer->id.'/import')->assertNotFound();
})->group('security');

it('mostra l’anteprima senza scrivere niente e tiene fuori le voci escluse', function (): void {
    IcsFixtures::fake('internal-entries');
    Sanctum::actingAs($this->s->ownerA);

    $response = $this->postJson($this->url.'/preview', ['type' => 'ics', 'url' => IcsFixtures::URL, 'exclude_keywords' => ['riunione', 'chiuso', 'manutenzione', 'privato']])
        ->assertOk()
        ->assertJsonPath('data.error', null);

    $titles = collect($response->json('data.rows'))->pluck('title');
    expect($titles)->toContain("Concerto del quartetto d'archi")
        ->and($titles->map(fn (string $title): string => mb_strtolower($title))->filter(fn (string $title): bool => str_contains($title, 'riunione') || str_contains($title, 'chiuso')))->toBeEmpty()
        ->and($response->json('data.rows.0.when'))->toBeString()
        ->and($response->json('data.preview_token'))->toBeString()
        ->and(ImportSource::query()->count())->toBe(0);
});

it('rifiuta un indirizzo che punta alla rete interna', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->url.'/preview', ['type' => 'ics', 'url' => 'http://127.0.0.1:8000/calendario.ics'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['url']]]);

    expect(ImportSource::query()->count())->toBe(0);
})->group('security');

it('non collega senza l’anteprima, né con l’anteprima di un altro indirizzo o di un’altra persona', function (): void {
    IcsFixtures::fake('google-calendar');
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['preview_token']]]);

    $other = p9Preview($this, IcsFixtures::URL.'?altro');
    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'preview_token' => $other])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['preview_token']]]);

    // Lo stesso indirizzo come CSV è un'altra cosa: l'anteprima era di un calendario.
    $token = p9Preview($this);
    $this->postJson($this->url, ['type' => 'csv', 'url' => IcsFixtures::URL, 'preview_token' => $token])->assertUnprocessable();

    // Il segno vale solo per chi ha guardato l'anteprima, e solo per un'ora.
    $foreign = app(VenueCalendarImport::class)->previewToken($this->venue, $this->s->ownerB, ImportSourceType::Ics, 'url:'.IcsFixtures::URL);
    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'preview_token' => $foreign])->assertUnprocessable();
    $this->travel(61)->minutes();
    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'preview_token' => $token])->assertUnprocessable();

    expect(ImportSource::query()->count())->toBe(0);
})->group('security');

it('collega dopo l’anteprima, legge subito il calendario e poi aggiorna il collegamento', function (): void {
    IcsFixtures::fake('google-calendar');
    Sanctum::actingAs($this->s->ownerA);

    $token = p9Preview($this);
    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'exclude_keywords' => ['riunione'], 'preview_token' => $token])
        ->assertCreated()
        ->assertJsonPath('data.source.type', 'ics')
        ->assertJsonPath('data.source.is_active', true)
        ->assertJsonPath('data.source.url', IcsFixtures::URL)
        ->assertJsonPath('data.form.exclude_keywords', ['riunione'])
        ->assertJsonPath('message', __('manage.calendar.notifications.connected').' '.__('manage.calendar.notifications.connected_body'));

    $source = ImportSource::query()->where('venue_id', $this->venue->id)->sole();
    expect($source->last_run_at)->not->toBeNull()
        ->and(Event::query()->where('venue_id', $this->venue->id)->where('source', ImportSourceType::Ics->eventSource())->exists())->toBeTrue();

    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'exclude_keywords' => [], 'preview_token' => $token])
        ->assertOk()->assertJsonPath('data.form.exclude_keywords', []);
    expect(ImportSource::query()->where('venue_id', $this->venue->id)->count())->toBe(1);
});

it('legge adesso, sospende, riattiva e scollega lasciando le date pubblicate', function (): void {
    IcsFixtures::fake('google-calendar');
    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'preview_token' => p9Preview($this)])->assertCreated();
    $events = Event::query()->where('venue_id', $this->venue->id)->count();

    $this->postJson($this->url.'/run')->assertOk()
        ->assertJsonPath('data.outcome', 'success')
        ->assertJsonPath('data.title', __('manage.calendar.notifications.run_done'))
        ->assertJsonPath('data.counters.created', 0)
        ->assertJsonPath('data.state.source.last_status', 'success');

    $this->postJson($this->url.'/pause')->assertOk()
        ->assertJsonPath('data.source.is_active', false)
        ->assertJsonPath('message', __('manage.calendar.notifications.suspended'));
    $this->postJson($this->url.'/resume')->assertOk()->assertJsonPath('data.source.is_active', true);

    $this->deleteJson($this->url)->assertOk()
        ->assertJsonPath('data.source', null)
        ->assertJsonPath('message', __('manage.calendar.notifications.disconnected'));
    expect(ImportSource::query()->where('venue_id', $this->venue->id)->exists())->toBeFalse()
        ->and(Event::query()->where('venue_id', $this->venue->id)->count())->toBe($events);

    $this->postJson($this->url.'/run')->assertNotFound();
    $this->deleteJson($this->url)->assertNotFound();
});

it('dice che la lettura non è riuscita quando il calendario non risponde', function (): void {
    IcsFixtures::fake('google-calendar');
    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'preview_token' => p9Preview($this)])->assertCreated();

    IcsFixtures::fake('google-calendar', 500);
    $this->postJson($this->url.'/run')->assertOk()
        ->assertJsonPath('data.outcome', 'failed')
        ->assertJsonPath('data.state.source.last_status', 'failed')
        ->assertJsonPath('data.counters', null);
    expect($this->getJson($this->url)->json('data.source.last_error'))->toBeString()->not->toBe('');
});

it('non lascia sul server il CSV dell’anteprima e salva una volta sola quello collegato', function (): void {
    app()->bind(HostResolver::class, fn () => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Sanctum::actingAs($this->s->ownerA);

    $preview = $this->post($this->url.'/preview', ['type' => 'csv', 'file' => p9Csv(), 'exclude_keywords' => ['riunione']], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.error', null);
    expect(collect($preview->json('data.rows'))->pluck('title')->all())->toBe(['Jazz in cortile'])
        ->and(Storage::disk('local')->allFiles($this->folder))->toBe([]);

    // Senza il file l'anteprima non vale: il segno è del contenuto, non del nome.
    $token = (string) $preview->json('data.preview_token');
    $this->post($this->url, ['type' => 'csv', 'file' => p9Csv('Altro titolo'), 'preview_token' => $token], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    $this->post($this->url, ['type' => 'csv', 'file' => p9Csv(), 'exclude_keywords' => ['riunione'], 'preview_token' => $token], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.source.type', 'csv')
        ->assertJsonPath('data.source.is_upload', true)
        ->assertJsonPath('data.source.url', null)
        ->assertJsonPath('data.form.url', null);
    $first = Storage::disk('local')->allFiles($this->folder);
    expect($first)->toHaveCount(1)
        ->and(Event::query()->where('venue_id', $this->venue->id)->where('title', 'Jazz in cortile')->exists())->toBeTrue();

    // Il foglio rinnovato prende il posto di quello di prima, che si cancella.
    $second = $this->post($this->url.'/preview', ['type' => 'csv', 'file' => p9Csv('Jazz in giardino')], ['Accept' => 'application/json'])->json('data.preview_token');
    $this->post($this->url, ['type' => 'csv', 'file' => p9Csv('Jazz in giardino'), 'preview_token' => $second], ['Accept' => 'application/json'])->assertOk();
    $now = Storage::disk('local')->allFiles($this->folder);
    expect($now)->toHaveCount(1)->and($now)->not->toBe($first);

    // Scollegato, il file non serve più.
    $this->deleteJson($this->url)->assertOk();
    expect(Storage::disk('local')->allFiles($this->folder))->toBe([]);
});

it('toglie i file di anteprime della pagina mai collegate quando si collega, solo se hanno più di un giorno', function (): void {
    IcsFixtures::fake('google-calendar');
    Sanctum::actingAs($this->s->ownerA);
    $disk = Storage::disk('local');
    $disk->put($this->folder.'/vecchio.csv', "titolo,inizio\n");
    touch($disk->path($this->folder.'/vecchio.csv'), now()->subDays(2)->getTimestamp());
    $disk->put($this->folder.'/recente.csv', "titolo,inizio\n");
    $disk->put(CsvImportDriver::UPLOAD_DIRECTORY.'/'.$this->s->venueB->id.'/altrui.csv', "titolo,inizio\n");
    touch($disk->path(CsvImportDriver::UPLOAD_DIRECTORY.'/'.$this->s->venueB->id.'/altrui.csv'), now()->subDays(2)->getTimestamp());

    $this->postJson($this->url, ['type' => 'ics', 'url' => IcsFixtures::URL, 'preview_token' => p9Preview($this)])->assertCreated();

    expect($disk->exists($this->folder.'/vecchio.csv'))->toBeFalse()
        ->and($disk->exists($this->folder.'/recente.csv'))->toBeTrue()
        ->and($disk->exists(CsvImportDriver::UPLOAD_DIRECTORY.'/'.$this->s->venueB->id.'/altrui.csv'))->toBeTrue();
});

it('vede dall’app il calendario collegato dalla pagina del pannello, e viceversa', function (): void {
    IcsFixtures::fake('google-calendar');
    $this->actingAs($this->s->ownerA);
    $this->get('/gestione/'.$this->venue->getRouteKey().'/calendario')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(CalendarImport::class)->fillForm(['url' => IcsFixtures::URL])->call('preview')->call('connect');

    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.source.url', IcsFixtures::URL)
        ->assertJsonPath('data.source.is_active', true)
        ->assertJsonPath('data.source.can_run', true);

    $this->postJson($this->url.'/pause')->assertOk();
    Livewire::test(CalendarImport::class)->assertSee(__('manage.calendar.status.suspended'));
});
