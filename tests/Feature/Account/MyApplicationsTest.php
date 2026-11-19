<?php

declare(strict_types=1);

use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * «Le mie richieste»: chi ha scritto segue le proprie richieste, con lo stato
 * e il pulsante giusto. Solo le proprie, comprese quelle che aspettano ancora
 * la conferma dell'email.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
});

it('mostra solo le richieste di chi è collegato, comprese quelle da confermare', function (): void {
    $io = User::factory()->create();
    $altro = User::factory()->create();

    VenueApplication::factory()->create(['user_id' => $io->id, 'venue_name' => 'Il Mio Locale']);
    VenueApplication::factory()->create(['user_id' => $io->id, 'venue_name' => 'Da Confermare', 'email_confirmed_at' => null]);
    VenueApplication::factory()->create(['user_id' => $altro->id, 'venue_name' => 'Locale Di Un Altro']);

    $this->actingAs($io)->get(route('account.applications'))
        ->assertOk()
        ->assertSee('data-service-page', escape: false)
        ->assertSee('Il Mio Locale')
        ->assertSee('Da Confermare')
        ->assertSee(__('venue_applications.account.state.pending'))
        ->assertSee(__('venue_applications.account.state.awaiting'))
        ->assertSee(__('venue_applications.account.confirm'))
        ->assertDontSee('Locale Di Un Altro');
});

it('chiede l’accesso agli ospiti e apre la pagina anche a chi deve ancora confermare l’email', function (): void {
    $this->get(route('account.applications'))->assertRedirect(route('login'));

    $nuovo = User::factory()->unverified()->create();
    VenueApplication::factory()->create(['user_id' => $nuovo->id, 'venue_name' => 'Appena Scritta', 'email_confirmed_at' => null]);

    $this->actingAs($nuovo)->get(route('account.applications'))
        ->assertOk()
        ->assertSee('Appena Scritta')
        ->assertSee(__('venue_applications.account.resend'))
        ->assertDontSee(__('venue_applications.account.confirm'));
});

it('porta al pannello dopo l’approvazione e propone una nuova richiesta dopo un rifiuto', function (): void {
    $io = User::factory()->create();
    $locale = Venue::factory()->create(['city_id' => $this->city->id, 'name' => 'Bottega Accolta']);
    $locale->membersIncludingInvited()->attach($io->id, ['role' => VenueRole::Owner->value, 'accepted_at' => now()]);

    VenueApplication::factory()->create([
        'user_id' => $io->id,
        'venue_name' => 'Bottega Accolta',
        'venue_id' => $locale->id,
        'status' => ApplicationStatus::Approved,
        'reviewed_at' => now(),
    ]);
    VenueApplication::factory()->create([
        'user_id' => $io->id,
        'kind' => ApplicationKind::Organizer,
        'venue_name' => 'Collettivo Respinto',
        'status' => ApplicationStatus::Rejected,
        'reviewed_at' => now(),
        'notes' => 'Nota interna che chi ha scritto non deve leggere',
    ]);

    $this->actingAs($io)->get(route('account.applications'))
        ->assertOk()
        ->assertSee(__('venue_applications.account.open_panel'))
        ->assertSee(url('/gestione/'.$locale->slug), escape: false)
        ->assertSee(__('venue_applications.account.state.rejected'))
        ->assertSee(route('organizer-applications.create'), escape: false)
        ->assertDontSee('Nota interna che chi ha scritto non deve leggere');
});

it('mostra lo stato vuoto con le strade per registrarsi, e la voce nel profilo', function (): void {
    $io = User::factory()->create();

    $this->actingAs($io)->get(route('account.applications'))
        ->assertOk()
        ->assertSee(__('venue_applications.account.empty_title'))
        ->assertSee(route('venue-applications.create'), escape: false);

    $this->actingAs($io)->get(route('account.profile'))
        ->assertOk()
        ->assertSee(route('account.applications'), escape: false)
        ->assertSee(__('venue_applications.account.menu'));

    $this->actingAs($io)->get('/account/richieste')->assertRedirect(route('account.applications'));
});
