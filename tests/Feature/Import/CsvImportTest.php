<?php

declare(strict_types=1);

use App\Enums\ImportSourceType;
use App\Enums\UserRole;
use App\Exceptions\ImportException;
use App\Filament\Venue\Pages\CalendarImport;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportSource;
use App\Models\User;
use App\Models\Venue;
use App\Services\Import\CsvImportDriver;
use App\Services\Import\HostResolver;
use App\Services\Import\ImportRunner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const CSV_URL = 'https://docs.example.test/sheet.csv';

function fakeCsv(string $body): void
{
    app()->bind(HostResolver::class, fn () => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });
    Http::fake([CSV_URL => Http::response($body, 200, ['Content-Type' => 'text/csv'])]);
}

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    freezeLocal($this->city, '2026-10-23 12:00');
    $this->source = ImportSource::factory()->create([
        'city_id' => $this->city->id, 'venue_id' => $this->venue->id, 'type' => ImportSourceType::Csv,
        'url' => CSV_URL, 'default_category_id' => $this->category->id, 'is_active' => true,
    ]);
});

it('reads an Italian sheet with semicolons, separate time column, free rows and local times', function (): void {
    fakeCsv("\xEF\xBB\xBFTitolo;Data;Ora;Fine;Descrizione;Link;Id\n"
        ."Concerto d'autunno;30/10/2026;21:00;23:30;Serata jazz;https://teatro.test/jazz;A1\n"
        ."Matinée;2026-11-01;;;;;\n"
        ."Senza data;;;;;;\n"
        ."Festa;31/10/2026 22.00;;01:00;;;B2\n");
    $driver = app(CsvImportDriver::class);
    $rows = $driver->fetch($this->source);
    expect($rows)->toHaveCount(4)->and($driver->map($rows[2], $this->source))->toBeNull();
    $first = $driver->map($rows[0], $this->source);
    expect($first->title)->toBe("Concerto d'autunno")
        ->and($first->uid)->toBe('A1')
        ->and($first->startsAt->utc()->format('Y-m-d H:i'))->toBe('2026-10-30 20:00')
        ->and($first->endsAt->utc()->format('H:i'))->toBe('22:30')
        ->and($first->isAllDay)->toBeFalse()
        ->and($first->url)->toBe('https://teatro.test/jazz');
    $second = $driver->map($rows[1], $this->source);
    expect($second->isAllDay)->toBeTrue()->and($second->uid)->toHaveLength(24)->and($second->endsAt)->toBeNull();
    $third = $driver->map($rows[3], $this->source);
    expect($third->startsAt->setTimezone('Europe/Rome')->format('d/m H:i'))->toBe('31/10 22:00')
        ->and($third->endsAt->setTimezone('Europe/Rome')->format('d/m H:i'))->toBe('01/11 01:00');

    $report = app(ImportRunner::class)->run($this->source);
    expect($report->counters()['created'])->toBe(3)
        ->and(Event::query()->where('title', "Concerto d'autunno")->exists())->toBeTrue()
        ->and(EventOccurrence::query()->count())->toBe(3);
});

it('reads an uploaded file only from the venue folder and refuses other paths', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put(CsvImportDriver::uploadPath($this->source, 'stagione.csv'), "title,start,end\nProva,2026-11-02 20:00,2026-11-02 22:00\n");
    Storage::disk('local')->put('imports/999/altro.csv', "title,start\nAltrui,2026-11-02 20:00\n");
    $this->source->update(['url' => CsvImportDriver::UPLOAD_PREFIX.'stagione.csv']);
    $rows = app(CsvImportDriver::class)->fetch($this->source);
    expect($rows)->toHaveCount(1)->and($rows[0]['title'])->toBe('Prova');
    $this->source->update(['url' => CsvImportDriver::UPLOAD_PREFIX.'../999/altro.csv']);
    expect(fn () => app(CsvImportDriver::class)->fetch($this->source))->toThrow(ImportException::class);
});

it('offers the CSV choice in the venue panel and previews a sheet from its address', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->source->delete();
    fakeCsv("titolo,inizio\nAnteprima dal foglio,2026-11-03 21:00\n");
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $this->venue->members()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue, true);
    Livewire::test(CalendarImport::class)
        ->fillForm(['type' => 'csv', 'url' => CSV_URL])
        ->call('preview')->assertHasNoFormErrors()
        ->assertSet('previewError', null)
        ->assertSee('Anteprima dal foglio')
        ->call('connect');
    $source = ImportSource::query()->where('venue_id', $this->venue->id)->sole();
    expect($source->type)->toBe(ImportSourceType::Csv)->and($source->url)->toBe(CSV_URL);
});
