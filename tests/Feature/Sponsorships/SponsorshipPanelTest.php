<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Sponsorships\Pages\CreateSponsorship;
use App\Filament\Admin\Resources\Sponsorships\Pages\ListSponsorships;
use App\Models\Sponsorship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Chi puo' toccare le campagne, e cosa succede quando le crea.
 *
 * La domanda che questi test tengono ferma non e' «funziona il modulo»: e'
 * **chi decide cosa compare a pagamento**. Un moderatore modera i contenuti
 * altrui; un referente di locale cura i propri eventi. Nessuno dei due compra
 * il posto in cima, ed e' una separazione che a schermo non si vede — un
 * evento sponsorizzato e uno in evidenza occupano lo stesso spazio.
 */
beforeEach(function (): void {
    /* I ruoli e i permessi non ci sono finche' non li si semina: il pannello
       li interroga a ogni pagina. */
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
});

function persona(UserRole $ruolo): User
{
    $user = User::factory()->create();
    $user->assignRole($ruolo->value);

    return $user;
}

it('apre le campagne a un amministratore', function (): void {
    $this->actingAs(persona(UserRole::Admin))
        ->get('/admin/sponsorships')
        ->assertOk();
});

it('le tiene chiuse a un moderatore', function (): void {
    /* Non e' una svista: moderare i contenuti e decidere cosa si vende sono
       due mestieri, e §3 assegna al moderatore solo il primo. */
    $this->actingAs(persona(UserRole::Moderator))
        ->get('/admin/sponsorships')
        ->assertForbidden();
});

it('le tiene chiuse al referente di un locale', function (): void {
    $this->actingAs(persona(UserRole::VenueOwner))
        ->get('/admin/sponsorships')
        ->assertForbidden();
});

it('ricava la città dall evento, senza chiederla', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    Livewire::actingAs(persona(UserRole::Admin))
        ->test(CreateSponsorship::class)
        ->fillForm([
            'event_id' => $occorrenza->event_id,
            'placement' => SponsorshipPlacement::ListTop->value,
            'status' => SponsorshipStatus::Active->value,
            'starts_at' => CarbonImmutable::now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::now()->addWeek()->format('Y-m-d H:i:s'),
            'advertiser_name' => 'Consorzio di prova',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $campagna = Sponsorship::query()->latest('id')->first();

    /* La citta' non e' un campo del modulo: una campagna in una citta' diversa
       da quella del proprio evento e' un dato incoerente, non un caso da
       coprire. */
    expect($campagna->city_id)->toBe($this->city->getKey())
        ->and($campagna->created_by)->not->toBeNull();
});

it('rifiuta una finestra che finisce prima di cominciare', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    Livewire::actingAs(persona(UserRole::Admin))
        ->test(CreateSponsorship::class)
        ->fillForm([
            'event_id' => $occorrenza->event_id,
            'placement' => SponsorshipPlacement::ListTop->value,
            'status' => SponsorshipStatus::Active->value,
            'starts_at' => CarbonImmutable::now()->addWeek()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
            'advertiser_name' => 'Consorzio di prova',
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);
});

it('pretende il nome di chi paga', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    /* Senza committente l'etichetta pubblica direbbe «sponsorizzato» e basta,
       che e' mezza informazione: il nome e' spesso la parte che conta. */
    Livewire::actingAs(persona(UserRole::Admin))
        ->test(CreateSponsorship::class)
        ->fillForm([
            'event_id' => $occorrenza->event_id,
            'placement' => SponsorshipPlacement::ListTop->value,
            'status' => SponsorshipStatus::Active->value,
            'starts_at' => CarbonImmutable::now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::now()->addWeek()->format('Y-m-d H:i:s'),
        ])
        ->call('create')
        ->assertHasFormErrors(['advertiser_name']);
});

it('elenca le campagne con le loro misure', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    $campagna = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
        'advertiser_name' => 'Birrificio del Piave',
    ]);

    $campagna->forceFill(['impressions' => 1000, 'clicks' => 25])->save();

    Livewire::actingAs(persona(UserRole::Admin))
        ->test(ListSponsorships::class)
        ->assertCanSeeTableRecords([$campagna])
        ->assertSee('Birrificio del Piave');
});
