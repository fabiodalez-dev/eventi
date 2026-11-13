<?php

declare(strict_types=1);

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use App\Filament\Admin\Resources\ImportSources\Pages\CreateImportSource;
use App\Filament\Admin\Resources\ImportSources\Pages\ListImportSources;
use App\Filament\Venue\Pages\CalendarImport;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportRun;
use App\Models\ImportSource;
use App\Models\User;
use App\Models\Venue;
use App\Services\Import\HostResolver;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\IcsFixtures;

/**
 * Flusso: un calendario esterno che diventa eventi. La redazione collega un
 * ICS dal pannello, guarda l'anteprima, lo esegue, e il comando orario lo
 * rilegge senza raddoppiare niente. Chi gestisce un locale collega un foglio
 * CSV dal proprio pannello e lo riesegue a mano: anche lì, nessun doppione.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

const GST_FOGLIO_URL = 'https://fogli.example.test/stagione-flussi.csv';

function gstServiFoglio(string $body): void
{
    app()->bind(HostResolver::class, fn () => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    // Il foglio servito si legge al momento della richiesta: `Http::fake()`
    // aggiunge regole in coda e vince la prima, quindi una seconda chiamata
    // con un corpo nuovo non cambierebbe niente.
    app()->instance('gst.foglio', $body);
    if (! app()->bound('gst.foglio.registrato')) {
        app()->instance('gst.foglio.registrato', true);
        Http::fake([GST_FOGLIO_URL => fn () => Http::response(app('gst.foglio'), 200, ['Content-Type' => 'text/csv'])]);
    }
}

it('collega un calendario ICS dal pannello di redazione, lo esegue e lo rilegge senza doppioni', function (): void {
    Carbon::setTestNow(localInstant($this->city, '2026-08-20 12:00:00'));
    IcsFixtures::fake('three-date-forms');

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);

    // 1. La sorgente nasce dal modulo del pannello.
    $this->actingAs($admin)->get('/admin/import-sources/create')->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(CreateImportSource::class)
        ->fillForm([
            'city_id' => $this->city->id,
            'type' => ImportSourceType::Ics->value,
            'default_category_id' => $this->category->id,
            'url' => IcsFixtures::URL,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    $source = ImportSource::query()->sole();
    expect($source->type)->toBe(ImportSourceType::Ics)->and($source->venue_id)->toBeNull();

    // 2. L'anteprima mostra cosa entrerebbe, senza scrivere niente.
    Livewire::test(ListImportSources::class)
        ->mountTableAction('preview', $source)
        ->assertMountedActionModalSee(['Data con TZID', 'Data fluttuante']);
    expect(Event::query()->count())->toBe(0);

    // 3. L'esecuzione dalla tabella (coda sincrona nei test) importa i tre eventi.
    Livewire::test(ListImportSources::class)
        ->callTableAction('run', $source)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('admin.notifications.import_queued'));

    expect(Event::query()->count())->toBe(3)
        ->and(EventOccurrence::query()->count())->toBe(3)
        ->and($source->fresh()->last_status)->toBe(ImportRunStatus::Success->value);
    $event = Event::query()->where('title', 'Data con TZID')->sole();
    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->source)->toBe(EventSource::ImportIcs)
        ->and($event->verification_status)->toBe(VerificationStatus::Unverified)
        ->and($event->category_id)->toBe($this->category->id);
    expect(ImportRun::query()->sole()->created_count)->toBe(3);

    // 4. Il comando orario rilegge lo stesso calendario: niente di nuovo.
    $this->artisan('import:run', ['--sync' => true])
        ->expectsOutputToContain(__('console.import_run.done', [
            'sources' => 1, 'created' => 0, 'updated' => 0, 'unchanged' => 3,
            'excluded' => 0, 'cancelled' => 0, 'errors' => 0,
        ]))
        ->assertSuccessful();
    expect(Event::query()->count())->toBe(3)->and(EventOccurrence::query()->count())->toBe(3);

    // 5. A monte cambia un titolo: lo stesso evento si aggiorna.
    IcsFixtures::fake('three-date-forms-renamed');
    $this->artisan('import:run', ['--sync' => true])->assertSuccessful();
    expect(Event::query()->count())->toBe(3)
        ->and($event->fresh()->title)->toBe('Data con TZID, titolo corretto')
        ->and(ImportRun::query()->count())->toBe(3)
        ->and(ImportRun::query()->latest('id')->first()->updated_count)->toBe(1);

    // La moderazione non governa le sorgenti.
    $moderator = User::factory()->create();
    $moderator->assignRole(UserRole::Moderator->value);
    $this->actingAs($moderator)->get('/admin/import-sources')->assertForbidden();
});

it('lascia al gestore collegare un foglio CSV dal suo pannello, rieseguirlo senza doppioni, sospenderlo e scollegarlo', function (): void {
    freezeLocal($this->city, '2026-10-23 12:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro dei Flussi']);
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $venue->members()->attach($owner, ['role' => 'owner']);
    $editor = User::factory()->create();
    $editor->assignRole(UserRole::VenueEditor->value);
    $venue->members()->attach($editor, ['role' => 'editor']);

    gstServiFoglio("titolo,inizio,fine,id\n"
        ."Jazz in cortile,2026-11-03 21:00,2026-11-03 23:00,J1\n"
        ."Cinema all'aperto,2026-11-05 20:30,2026-11-05 22:30,C1\n");

    // Il collaboratore cura gli eventi, ma il calendario lo collega il referente.
    $this->actingAs($editor)->get('/gestione/'.$venue->slug.'/calendario')->assertForbidden();

    // 1. Il referente apre la pagina, guarda l'anteprima e collega.
    $this->actingAs($owner)->get('/gestione/'.$venue->slug.'/calendario')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue, true);
    $pagina = Livewire::test(CalendarImport::class)
        ->fillForm(['type' => ImportSourceType::Csv->value, 'url' => GST_FOGLIO_URL])
        ->call('preview')
        ->assertHasNoFormErrors()
        ->assertSet('previewError', null)
        ->assertSee('Jazz in cortile')
        ->assertSee("Cinema all'aperto");
    expect(Event::query()->count())->toBe(0);

    $pagina->call('connect')->assertNotified(__('manage.calendar.notifications.connected'));

    $source = ImportSource::query()->where('venue_id', $venue->id)->sole();
    expect($source->type)->toBe(ImportSourceType::Csv)
        ->and($source->is_active)->toBeTrue()
        // Il collegamento accoda subito la prima lettura (sincrona nei test).
        ->and(Event::query()->where('venue_id', $venue->id)->count())->toBe(2)
        ->and(EventOccurrence::query()->count())->toBe(2);
    $primaLettura = Event::query()->where('title', 'Jazz in cortile')->sole()->occurrences()->sole()->starts_at->utc()->format('Y-m-d H:i');

    // 2. «Esegui adesso» sullo stesso foglio non raddoppia.
    Livewire::test(CalendarImport::class)->callAction('runNow')->assertNotified(__('manage.calendar.notifications.run_done'));
    expect(Event::query()->count())->toBe(2)->and(EventOccurrence::query()->count())->toBe(2);

    // 3. Il foglio cambia l'orario di una riga: si aggiorna quella data.
    gstServiFoglio("titolo,inizio,fine,id\n"
        ."Jazz in cortile,2026-11-03 21:30,2026-11-03 23:30,J1\n"
        ."Cinema all'aperto,2026-11-05 20:30,2026-11-05 22:30,C1\n");
    Livewire::test(CalendarImport::class)->callAction('runNow');
    $jazz = Event::query()->where('title', 'Jazz in cortile')->sole();
    $dopoModifica = $jazz->occurrences()->sole()->starts_at;
    expect(Event::query()->count())->toBe(2)
        ->and(EventOccurrence::query()->count())->toBe(2)
        // L'orario si è spostato di mezz'ora: è la stessa data, aggiornata.
        ->and($dopoModifica->utc()->format('Y-m-d H:i'))->not->toBe($primaLettura);

    // 4. Sospende e poi scollega.
    Livewire::test(CalendarImport::class)->callAction('suspend');
    expect($source->fresh()->is_active)->toBeFalse();
    Livewire::test(CalendarImport::class)->callAction('disconnect');
    expect(ImportSource::query()->where('venue_id', $venue->id)->exists())->toBeFalse();

    // 5. Gli orari scritti nel foglio sono quelli del locale: le 21:00 e poi
    //    le 21:30 di Padova a novembre (CET) sono le 20:00 e le 20:30 UTC.
    expect($primaLettura)->toBe('2026-11-03 20:00')
        ->and($dopoModifica->setTimezone('Europe/Rome')->format('H:i'))->toBe('21:30');
});
