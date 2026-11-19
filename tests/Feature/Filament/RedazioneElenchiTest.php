<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\EventSubmissions\EventSubmissionResource;
use App\Filament\Admin\Resources\EventSubmissions\Pages\ListEventSubmissions;
use App\Filament\Support\PanelDate;
use App\Models\City;
use App\Models\EventSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Le proposte arrivate dal pubblico si leggono senza decifrare: quando è
 * l'evento («sab 22 nov, 21:00»), chi l'ha proposto, da quanto aspetta.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('mostra le proposte con la data leggibile e chi le ha mandate', function (): void {
    $city = City::query()->first() ?? City::factory()->create();
    $starts = CarbonImmutable::now()->addDays(5)->setTime(20, 0);

    $submission = EventSubmission::query()->create([
        'city_id' => $city->getKey(),
        'title' => 'Concerto di prova nel cortile',
        'contact_name' => 'Giulia Rossi',
        'contact_email' => 'giulia@example.test',
        'venue_hint' => 'Cortile delle Arti',
        'starts_at_hint' => $starts,
        'status' => SubmissionStatus::Pending,
    ]);

    $this->get(EventSubmissionResource::getUrl('index'))->assertOk();

    Livewire::test(ListEventSubmissions::class)
        ->assertOk()
        ->assertSee('Concerto di prova nel cortile')
        ->assertSee(__('pannelli-redazione.proposte.from', ['name' => 'Giulia Rossi']))
        ->assertSee(PanelDate::event($submission->starts_at_hint, $city->timezone))
        ->assertDontSee($starts->format('d/m/Y'));
});

it('quando non ci sono proposte dice che cosa arriverà qui', function (): void {
    $this->get(EventSubmissionResource::getUrl('index'))->assertOk();

    Livewire::test(ListEventSubmissions::class)
        ->assertOk()
        ->assertSee(__('pannelli-redazione.proposte.empty_heading'))
        ->assertSee(__('pannelli-redazione.proposte.empty_description'));
});
