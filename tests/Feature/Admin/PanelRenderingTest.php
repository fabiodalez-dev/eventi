<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Categories\CategoryResource;
use App\Filament\Admin\Resources\Cities\CityResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Filament\Admin\Resources\Tags\TagResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\ImportSource;
use App\Models\Report;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Ogni pagina del pannello si apre davvero, e **nessuna mostra una chiave di
 * traduzione al posto di una parola**.
 *
 * La seconda verifica è il guardiano della regola più violata del progetto
 * (§2.1 delle convenzioni): una `__('admin.qualcosa')` scritta male non fa
 * fallire nulla, si limita a stampare la chiave in pagina — e nessuno se ne
 * accorge finché non la vede un utente.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);
    $this->actingAs($admin);

    $this->city = City::factory()->padova()->create();
    $this->category = Category::factory()->create(['default_duration_minutes' => 180]);
    $this->tag = Tag::factory()->create();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $this->event = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => $this->venue->getKey(),
    ]);

    $recurrence = EventRecurrence::factory()->create([
        'event_id' => $this->event->getKey(),
        'rrule' => 'FREQ=WEEKLY;BYDAY=TH;COUNT=3',
        'exdates' => ['2026-12-25'],
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $this->event->getKey(),
        'recurrence_id' => $recurrence->getKey(),
        'ends_at' => null,
    ]);

    $this->report = Report::factory()->create();
    $this->application = VenueApplication::factory()->create();
    $this->importSource = ImportSource::factory()->create(['city_id' => $this->city->getKey()]);
    $this->user = User::factory()->create();
});

/**
 * @return array<int, string>
 */
function rawTranslationKeys(string $html): array
{
    preg_match_all('/\b(?:admin|enums|common)\.[a-z_]+\.[a-z_.]+\b/', $html, $matches);

    return array_values(array_unique($matches[0]));
}

it('apre ogni elenco senza mostrare chiavi di traduzione', function (string $url): void {
    $response = $this->get($url);

    $response->assertOk();

    expect(rawTranslationKeys($response->getContent()))->toBe([]);
})->with([
    'riepilogo' => '/admin',
    'città' => '/admin/cities',
    'locali' => '/admin/venues',
    'eventi' => '/admin/events',
    'categorie' => '/admin/categories',
    'tag' => '/admin/tags',
    'utenti' => '/admin/users',
    'segnalazioni' => '/admin/reports',
    'import' => '/admin/import-sources',
    'richieste' => '/admin/venue-applications',
]);

it('apre ogni modulo di creazione', function (string $url): void {
    $this->get($url)->assertOk();
})->with([
    'città' => '/admin/cities/create',
    'locale' => '/admin/venues/create',
    'evento' => '/admin/events/create',
    'categoria' => '/admin/categories/create',
    'tag' => '/admin/tags/create',
    'utente' => '/admin/users/create',
    'sorgente' => '/admin/import-sources/create',
]);

it('apre ogni scheda di modifica senza chiavi di traduzione', function (): void {
    $urls = [
        CityResource::getUrl('edit', ['record' => $this->city]),
        VenueResource::getUrl('edit', ['record' => $this->venue]),
        EventResource::getUrl('edit', ['record' => $this->event]),
        CategoryResource::getUrl('edit', ['record' => $this->category]),
        TagResource::getUrl('edit', ['record' => $this->tag]),
        UserResource::getUrl('edit', ['record' => $this->user]),
        ReportResource::getUrl('edit', ['record' => $this->report]),
        ImportSourceResource::getUrl('edit', ['record' => $this->importSource]),
        VenueApplicationResource::getUrl('edit', ['record' => $this->application]),
    ];

    foreach ($urls as $url) {
        $response = $this->get($url);

        $response->assertOk();

        expect(rawTranslationKeys($response->getContent()))->toBe([], $url);
    }
});

it('non nasconde le segnalazioni e le richieste dietro un pulsante di creazione', function (): void {
    // Arrivano dal pubblico: crearle dal pannello sarebbe inventare una prova.
    expect(ReportResource::canCreate())->toBeFalse()
        ->and(VenueApplicationResource::canCreate())->toBeFalse();
});
