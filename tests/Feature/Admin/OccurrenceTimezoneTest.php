<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Providers\FilamentDefaultsServiceProvider;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Support\Facades\FilamentTimezone;
use Livewire\Livewire;

/**
 * Chi inserisce un evento dal pannello scrive l'ora LOCALE della citta.
 *
 * Senza `->timezone()` sui DateTimePicker, Filament interpreta "21:30" come
 * 21:30 UTC e lo salva tale e quale: a Padova diventano le 23:30, due ore piu
 * tardi di quanto l'operatore credeva di scrivere. L'errore non si vede nel
 * pannello — che rilegge lo stesso valore sbagliato — ma sposta l'evento nel
 * sito, nell'API, in "stasera" e, se cade a cavallo della mezzanotte, anche
 * in `business_date`.
 *
 * Questi test proteggono quel comportamento: se qualcuno rimuove `->timezone()`
 * diventano rossi.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->city = City::factory()->create([
        'timezone' => 'Europe/Rome',
        'night_cutoff_time' => '06:00',
    ]);

    $this->category = Category::factory()->create([
        'default_duration_minutes' => 180,
        'supports_ongoing' => true,
        'is_nightlife' => false,
    ]);

    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);

    $this->actingAs($this->admin);
});

it('salva in UTC l ora locale digitata nel pannello', function (): void {
    // 21:30 a Padova in agosto (ora legale, UTC+2) sono le 19:30 UTC.
    Livewire::test(CreateEvent::class)
        ->fillForm([
            'city_id' => $this->city->id,
            'venue_id' => $this->venue->id,
            'category_id' => $this->category->id,
            'title' => 'Concerto della prova',
            'occurrences' => [
                ['starts_at' => '2026-08-15 21:30:00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $occurrence = EventOccurrence::query()->latest('id')->firstOrFail();

    expect($occurrence->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-08-15 19:30')
        ->and($occurrence->starts_at->setTimezone('Europe/Rome')->format('H:i'))->toBe('21:30');
});

it('applica l offset invernale corretto fuori dall ora legale', function (): void {
    // A dicembre l'Italia e UTC+1: le 21:30 locali sono le 20:30 UTC.
    Livewire::test(CreateEvent::class)
        ->fillForm([
            'city_id' => $this->city->id,
            'venue_id' => $this->venue->id,
            'category_id' => $this->category->id,
            'title' => 'Concerto invernale',
            'occurrences' => [
                ['starts_at' => '2026-12-15 21:30:00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $occurrence = EventOccurrence::query()->latest('id')->firstOrFail();

    expect($occurrence->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-15 20:30');
});

it('assegna la business_date della serata a cui l evento appartiene', function (): void {
    // Un evento non-nightlife alle 21:30 locali resta nella propria giornata:
    // se l'ora fosse salvata come UTC diventerebbe il giorno dopo alle 23:30.
    Livewire::test(CreateEvent::class)
        ->fillForm([
            'city_id' => $this->city->id,
            'venue_id' => $this->venue->id,
            'category_id' => $this->category->id,
            'title' => 'Concerto a fine giornata',
            'occurrences' => [
                ['starts_at' => '2026-08-15 23:30:00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $occurrence = EventOccurrence::query()->latest('id')->firstOrFail();

    expect($occurrence->business_date->format('Y-m-d'))->toBe('2026-08-15');
});

it('mostra le serate nel fuso della città nella sezione delle date della modifica', function (): void {
    $event = Event::factory()->create([
        'city_id' => $this->city->id,
        'category_id' => $this->category->id,
        'venue_id' => $this->venue->id,
        'title' => 'Presentazioni del martedì',
    ]);
    // 16:30 UTC di un martedì d'estate: a Padova sono le 18:30.
    $occurrence = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => '2026-08-18 16:30:00', 'ends_at' => null]);

    $manager = Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertSee('mar 18 ago, 18:30')
        ->assertDontSee('18/08/2026 16:30')
        ->mountTableAction('edit', $occurrence);

    // Il campo della finestra mostra l'ora locale; salvata, torna UTC.
    expect((string) data_get($manager->get('mountedActions'), '0.data.starts_at'))->toStartWith('2026-08-18 18:30');
    $manager->assertTableActionDataSet(['starts_at' => '2026-08-18 16:30']);
});

it('usa l ora di Roma come fuso predefinito dei pannelli, tranne nei campi già convertiti a mano', function (): void {
    expect(FilamentTimezone::get())->toBe('Europe/Rome')
        ->and(DateTimePicker::make('publish_at')->getTimezone())->toBe('Europe/Rome')
        // Un orario senza data è un'ora da orologio di casa: non si converte.
        ->and(TimePicker::make('night_cutoff_time')->getTimezone())->toBe(config('app.timezone'))
        ->and(DateTimePicker::make('starts_at')->timezone('America/New_York')->getTimezone())->toBe('America/New_York');

    foreach (FilamentDefaultsServiceProvider::PRECONVERTED_FIELDS as $field) {
        expect(DateTimePicker::make($field)->getTimezone())->toBe(config('app.timezone'));
    }
});
