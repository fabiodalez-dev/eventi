<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
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
