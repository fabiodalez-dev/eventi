<?php

declare(strict_types=1);

use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * «Gestisci questo locale?»: l'invito a rivendicare compare, con un pulsante
 * vero, solo dove ha senso — locale pubblico senza referente, nemmeno
 * invitato — sulla scheda del locale, nella pagina di un evento e nell'app.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    $this->category = testCategory();
});

it('mostra la card con il pulsante sulla scheda di un locale senza referente', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Bar Senza Nessuno']);

    expect($venue->isClaimable())->toBeTrue();
    $this->get(route('venues.show', $venue))->assertOk()
        ->assertSee('data-claim-card', escape: false)
        ->assertSee(__('venues.claim_card.title'))
        ->assertSee(route('venues.claim.create', $venue), escape: false);
});

it('non la mostra a un locale con un referente, anche solo invitato', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $invitato = User::factory()->create();
    $venue->membersIncludingInvited()->attach($invitato->id, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => null, 'accepts_on_password' => false]);

    expect($venue->fresh()->isClaimable())->toBeFalse();
    $this->get(route('venues.show', $venue))->assertOk()->assertDontSee('data-claim-card', escape: false);
    $this->get(route('venues.claim.create', $venue))->assertNotFound();
});

it('non la mostra a un locale in bozza', function (): void {
    $venue = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);

    expect($venue->isClaimable())->toBeFalse();
    $this->get(route('venues.claim.create', $venue))->assertNotFound();
});

it('la mostra nella sezione «Dove» della pagina di un evento', function (): void {
    freezeLocal($this->city, '2026-09-05 12:00:00');
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-10 21:30:00');
    $venue = $occurrence->event->venue;

    $this->get('/eventi/'.$occurrence->event->slug)->assertOk()
        ->assertSee('data-claim-card', escape: false)
        ->assertSee(route('venues.claim.create', $venue), escape: false);

    $venue->membersIncludingInvited()->attach(User::factory()->create()->id, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now(), 'accepts_on_password' => false]);
    cache()->flush();

    $this->get('/eventi/'.$occurrence->event->slug)->assertOk()->assertDontSee('data-claim-card', escape: false);
});

it('dice all’app se il locale si può rivendicare, e dove', function (): void {
    $libero = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $gestito = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $gestito->membersIncludingInvited()->attach(User::factory()->create()->id, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now(), 'accepts_on_password' => false]);

    $this->getJson('/api/v1/venues/'.$libero->slug)->assertOk()
        ->assertJsonPath('data.claimable', true)
        ->assertJsonPath('data.claim_url', route('venues.claim.create', $libero));
    $this->getJson('/api/v1/venues/'.$gestito->slug)->assertOk()
        ->assertJsonPath('data.claimable', false)
        ->assertJsonPath('data.claim_url', null);
});

it('nell’elenco della ricerca sa chi si può rivendicare senza una query per locale', function (): void {
    Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria Uno']);
    $gestito = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria Due']);
    $gestito->membersIncludingInvited()->attach(User::factory()->create()->id, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now(), 'accepts_on_password' => false]);

    $stati = Venue::query()->withClaimState()->orderBy('name')->get()->mapWithKeys(fn (Venue $v): array => [$v->name => $v->isClaimable()]);
    expect($stati->all())->toBe(['Osteria Due' => false, 'Osteria Uno' => true]);

    $this->get('/registra-il-tuo-locale?cerca=osteria')->assertOk()
        ->assertSee('Osteria Uno')->assertSee('Osteria Due')
        ->assertSee(route('venues.claim.create', Venue::query()->where('name', 'Osteria Uno')->sole()), escape: false)
        ->assertSee(__('forms.application.catalogue.managed'));
});
