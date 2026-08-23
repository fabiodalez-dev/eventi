<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\Cities\Pages\CreateCity;
use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Venues\Pages\CreateVenue;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Il criterio di accettazione di §9: **un amministratore crea una città, un
 * locale e un evento con tre date, e lo pubblica, senza toccare codice**.
 *
 * Il percorso è quello vero — gli stessi moduli Livewire che disegna il
 * pannello, con le stesse Policy e gli stessi observer — non una scorciatoia
 * che scriva sui model.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);

    $this->actingAs($this->admin);
});

it('porta un amministratore da nessuna città a un evento pubblicato con tre date', function (): void {
    expect(City::query()->count())->toBe(0);

    // 1. La città. Porta con sé i tre parametri del motore temporale.
    Livewire::test(CreateCity::class)
        ->fillForm([
            'name' => 'Padova',
            'province_code' => 'PD',
            'province_name' => 'Padova',
            'region' => 'Veneto',
            'country_code' => 'IT',
            'timezone' => 'Europe/Rome',
            'center_lat' => 45.4064,
            'center_lng' => 11.8768,
            'default_zoom' => 12,
            'radius_km' => 30,
            'locale' => 'it',
            'night_cutoff_time' => '06:00',
            'starting_soon_minutes' => 180,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $city = City::query()->firstOrFail();

    expect($city->slug)->toBe('padova')
        ->and($city->night_cutoff_time)->toStartWith('06:00')
        ->and($city->starting_soon_minutes)->toBe(180);

    // 2. Il locale. Il punto geometrico non è un campo del modulo: lo deriva
    //    l'observer da latitudine e longitudine.
    Livewire::test(CreateVenue::class)
        ->fillForm([
            'city_id' => $city->getKey(),
            'name' => 'Circolo Aurora',
            'type' => 'circolo',
            'address' => 'Via Portello 12',
            'municipality' => 'Padova',
            'province_code' => 'PD',
            'lat' => 45.4084,
            'lng' => 11.8880,
            'status' => VenueStatus::Approved->value,
            'plan' => 'free',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $venue = Venue::query()->firstOrFail();

    expect($venue->slug)->toBe('circolo-aurora')
        ->and($venue->location)->not->toBeNull()
        ->and(round($venue->location->latitude, 4))->toBe(45.4084)
        ->and(round($venue->location->longitude, 4))->toBe(11.888);

    $category = Category::factory()->create([
        'name' => 'Musica dal vivo',
        'default_duration_minutes' => 180,
    ]);

    // 3. L'evento con tre date, in un solo salvataggio.
    $first = CarbonImmutable::now($city->timezone)->addWeek()->setTime(21, 30);

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'city_id' => $city->getKey(),
            'venue_id' => $venue->getKey(),
            'category_id' => $category->getKey(),
            'title' => 'Rassegna di musica popolare',
            'description' => 'Tre serate di musica dal vivo al Circolo Aurora.',
            'price_type' => PriceType::Free->value,
            'currency' => 'EUR',
            'status' => EventStatus::Draft->value,
            'source' => 'manual',
            'verification_status' => 'unverified',
            'editorial_score' => 0,
            'occurrences' => [
                ['starts_at' => $first->format('Y-m-d H:i:s'), 'ends_at' => null, 'doors_at' => null, 'is_all_day' => false],
                ['starts_at' => $first->addWeek()->format('Y-m-d H:i:s'), 'ends_at' => null, 'doors_at' => null, 'is_all_day' => false],
                ['starts_at' => $first->addWeeks(2)->format('Y-m-d H:i:s'), 'ends_at' => null, 'doors_at' => null, 'is_all_day' => false],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->firstOrFail();

    expect($event->occurrences()->count())->toBe(3)
        ->and($event->created_by)->toBe($this->admin->getKey())
        ->and($event->status)->toBe(EventStatus::Draft);

    // Le colonne calcolate le ha scritte l'observer, non il modulo.
    $event->occurrences->each(function ($occurrence): void {
        expect($occurrence->business_date)->not->toBeNull()
            ->and($occurrence->effective_ends_at)->not->toBeNull()
            ->and($occurrence->status)->toBe(OccurrenceStatus::Scheduled);
    });

    // 4. La pubblicazione, dall'azione dell'intestazione.
    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('publish');

    $event->refresh();

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->published_at)->not->toBeNull();
});

it('rifiuta di pubblicare un evento che non ha nemmeno una data', function (): void {
    $city = City::factory()->padova()->create();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    $category = Category::factory()->create();

    $event = Event::factory()->create([
        'city_id' => $city->getKey(),
        'venue_id' => $venue->getKey(),
        'category_id' => $category->getKey(),
        'status' => EventStatus::Draft,
        'published_at' => null,
    ]);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('publish');

    expect($event->refresh()->status)->toBe(EventStatus::Draft);
});
