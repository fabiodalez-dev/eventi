<?php

declare(strict_types=1);

use App\Enums\ContactMode;
use App\Filament\Admin\Resources\Organizers\Pages\ManageOrganizers;
use App\Filament\Admin\Resources\Venues\Pages\CreateVenue;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue;
use App\Filament\Organizer\Pages\Profile as OrganizerProfile;
use App\Filament\Venue\Pages\VenueProfile;
use App\Mail\PublicContact;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\ContactEmailConfirmationRequested;
use App\Services\Contact\ContactEmailConfirmation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * Doppio opt-in del recapito del modulo contatti (D64, rinvio chiuso).
 *
 * Il recapito lo scrive chi gestisce la scheda senza prova che la casella sia
 * sua: finché non è confermato con il collegamento il modulo pubblico non
 * compare (sito e API) e il servizio rifiuta l'invio.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->owner = $this->scenario->ownerA;
    $this->api = '/api/v1/venues/'.$this->venue->slug.'/contact';
    Notification::fake();
    Mail::fake();
});

/** Il collegamento di conferma spedito a un indirizzo, preso dalla notifica. */
function confirmationLinkFor(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(ContactEmailConfirmationRequested::class, function (ContactEmailConfirmationRequested $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }
        $url = $notification->url;

        return true;
    });

    return (string) $url;
}

/** Imposta il recapito dal pannello del locale, come il referente. */
function setVenueContactFromPanel(Venue $venue, User $owner, string $email): void
{
    test()->actingAs($owner)->get(VenueProfile::getUrl(panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
    Livewire::test(VenueProfile::class)
        ->fillForm(['address' => 'Via Roma 1', 'municipality' => 'Padova', 'zone' => 'Portello', 'contact_mode' => 'members', 'contact_email' => $email])
        ->call('save')->assertHasNoErrors();
}

it('un recapito non confermato nasconde il modulo e blocca l’invio, sul sito e nell’API', function (): void {
    setVenueContactFromPanel($this->venue, $this->owner, 'qualcuno@altrove.test');

    $venue = $this->venue->fresh();
    expect($venue->contact_email)->toBe('qualcuno@altrove.test')
        ->and($venue->contact_email_verified_at)->toBeNull()
        ->and($venue->hasConfirmedContactEmail())->toBeFalse();
    Notification::assertSentOnDemandTimes(ContactEmailConfirmationRequested::class, 1);
    expect(confirmationLinkFor('qualcuno@altrove.test'))->toContain('/conferma-recapito/venues/'.$venue->id);

    $sender = User::factory()->create();
    $this->actingAs($sender)->get('/locali/'.$venue->slug)->assertOk()->assertDontSee('id="contatta"', false);
    $this->actingAs($sender)->post('/contatta/venues/'.$venue->slug, ['message' => 'Vorrei informazioni sul programma.'])->assertNotFound();

    $this->getJson($this->api)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.guests', false);
    Sanctum::actingAs($sender);
    $this->postJson($this->api, ['message' => 'Vorrei informazioni sul programma.'])->assertNotFound();
    Mail::assertNothingSent();
});

it('il collegamento conferma il recapito solo dal pulsante e riattiva il modulo', function (): void {
    setVenueContactFromPanel($this->venue, $this->owner, 'qualcuno@altrove.test');
    $link = confirmationLinkFor('qualcuno@altrove.test');
    auth()->logout();

    /* La GET mostra il pulsante e non conferma: gli scanner di posta aprono i
       collegamenti da soli. */
    $this->get($link)->assertOk()->assertSee(__('contact.confirmation.page_submit'));
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeFalse();

    $this->post($link)->assertOk()->assertSee(__('contact.confirmation.page_confirmed', ['name' => $this->venue->name]));
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeTrue();

    $this->getJson($this->api)->assertJsonPath('data.enabled', true);
    $sender = User::factory()->create();
    $this->actingAs($sender)->get('/locali/'.$this->venue->slug)->assertOk()->assertSee('id="contatta"', false);
    Sanctum::actingAs($sender);
    $this->postJson($this->api, ['message' => 'Vorrei informazioni sul programma.'])->assertOk();
    Mail::assertSent(PublicContact::class, fn (PublicContact $mail): bool => $mail->hasTo('qualcuno@altrove.test'));
});

it('rifiuta un collegamento scaduto, manomesso o spedito a un indirizzo poi cambiato', function (): void {
    setVenueContactFromPanel($this->venue, $this->owner, 'primo@altrove.test');
    $link = confirmationLinkFor('primo@altrove.test');
    auth()->logout();

    // Firma manomessa: un altro locale nello stesso collegamento.
    $this->post(str_replace('/venues/'.$this->venue->id.'?', '/venues/'.$this->scenario->venueB->id.'?', $link))->assertForbidden();
    // Impronta manomessa.
    $this->post(preg_replace('/email=[0-9a-f]+/', 'email='.str_repeat('a', 64), $link))->assertForbidden();
    // Scaduto dopo sette giorni.
    $this->travel(ContactEmailConfirmation::LINK_DAYS + 1)->days();
    $this->post($link)->assertForbidden();
    $this->travelBack();
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeFalse();

    // Il recapito cambia: il collegamento del primo indirizzo non conferma il secondo.
    $this->venue->fresh()->update(['contact_email' => 'secondo@altrove.test']);
    $this->post($link)->assertOk()->assertSee(__('contact.confirmation.page_invalid', ['name' => $this->venue->name]));
    expect($this->venue->fresh()->contact_email_verified_at)->toBeNull();
});

it('cambiare il recapito azzera la conferma; toglierlo e rimetterlo uguale lo lascia da riconfermare', function (): void {
    $this->venue->update(['contact_mode' => ContactMode::Members, 'contact_email' => 'confermato@altrove.test']);
    $this->venue->markContactEmailConfirmed();
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeTrue();

    // Solo maiuscole e spazi: la casella è la stessa.
    $this->venue->update(['contact_email' => ' Confermato@Altrove.test ']);
    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeTrue();

    $this->venue->update(['contact_email' => 'nuovo@altrove.test']);
    expect($this->venue->fresh()->contact_email_verified_at)->toBeNull();

    $this->venue->markContactEmailConfirmed();
    $this->venue->update(['contact_email' => null]);
    $this->venue->update(['contact_email' => 'nuovo@altrove.test']);
    expect($this->venue->fresh()->contact_email_verified_at)->toBeNull();
    $this->getJson($this->api)->assertJsonPath('data.enabled', false);
});

it('vale anche dal pannello degli organizzatori: il cambio d’indirizzo azzera e spedisce la conferma', function (): void {
    $organizer = Organizer::create(['name' => 'Portici', 'owner_id' => $this->owner->id, 'city_id' => $this->scenario->city->id, 'is_active' => true,
        'contact_mode' => ContactMode::Members, 'contact_email' => 'vecchio@altrove.test']);
    $organizer->markContactEmailConfirmed();

    $this->actingAs($this->owner)->get(OrganizerProfile::getUrl(panel: 'organizer', tenant: $organizer))->assertOk()
        ->assertSee(__('contact.confirmation.confirmed', ['email' => 'vecchio@altrove.test']));
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    Livewire::test(OrganizerProfile::class)->fillForm(['contact_email' => 'nuovo@altrove.test'])->call('save')->assertHasNoErrors()
        ->assertSee(__('contact.confirmation.resend'));

    expect($organizer->fresh()->contact_email_verified_at)->toBeNull();
    $this->getJson('/api/v1/organizers/'.$organizer->slug.'/contact')->assertJsonPath('data.enabled', false);
    $link = confirmationLinkFor('nuovo@altrove.test');
    expect($link)->toContain('/conferma-recapito/organizers/'.$organizer->id);

    $this->post($link)->assertOk();
    expect($organizer->fresh()->hasConfirmedContactEmail())->toBeTrue();
});

it('l’email verificata di chi imposta il recapito vale come confermata subito', function (): void {
    setVenueContactFromPanel($this->venue, $this->owner, mb_strtoupper($this->owner->email));

    expect($this->venue->fresh()->hasConfirmedContactEmail())->toBeTrue();
    Notification::assertNothingSent();
    $this->getJson($this->api)->assertJsonPath('data.enabled', true);
});

it('l’email di chi la imposta non conta se il suo account non è confermato', function (): void {
    $this->owner->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($this->owner);
    $this->venue->update(['contact_mode' => ContactMode::Members, 'contact_email' => $this->owner->email]);

    expect($this->venue->fresh()->contact_email_verified_at)->toBeNull();
    Notification::assertSentOnDemandTimes(ContactEmailConfirmationRequested::class, 1);
});

it('la redazione che imposta il recapito di un locale passa dalla stessa conferma', function (): void {
    $admin = $this->scenario->admin;
    $this->actingAs($admin)->get(EditVenue::getUrl(['record' => $this->venue], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(EditVenue::class, ['record' => $this->venue->getRouteKey()])
        ->fillForm(['contact_mode' => 'members', 'contact_email' => 'scelto-dalla-redazione@altrove.test'])
        ->call('save')->assertHasNoFormErrors();

    expect($this->venue->fresh()->contact_email_verified_at)->toBeNull();
    confirmationLinkFor('scelto-dalla-redazione@altrove.test');
});

it('anche il cambio dalla redazione di un organizzatore azzera e spedisce; la creazione di un locale non mostra lo stato', function (): void {
    $organizer = Organizer::create(['name' => 'Portici', 'owner_id' => $this->owner->id, 'city_id' => $this->scenario->city->id, 'is_active' => true,
        'contact_mode' => ContactMode::Members, 'contact_email' => 'vecchio@altrove.test']);
    $organizer->markContactEmailConfirmed();
    $admin = $this->scenario->admin;
    $this->actingAs($admin)->get(CreateVenue::getUrl(panel: 'admin'))->assertOk()->assertDontSee(__('contact.confirmation.resend'));
    $this->get(ManageOrganizers::getUrl(panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(ManageOrganizers::class)
        ->callTableAction('edit', $organizer, data: ['contact_email' => 'scelto-dalla-redazione@altrove.test'])->assertHasNoTableActionErrors();

    expect($organizer->fresh()->contact_email_verified_at)->toBeNull();
    confirmationLinkFor('scelto-dalla-redazione@altrove.test');
});

it('le righe già configurate al deploy con il modulo acceso diventano confermate (backfill)', function (): void {
    $other = $this->scenario->venueB;
    DB::table('venues')->where('id', $this->venue->id)->update(['contact_email' => 'esistente@altrove.test', 'contact_mode' => 'members', 'contact_email_verified_at' => null]);
    DB::table('venues')->where('id', $other->id)->update(['contact_email' => null, 'contact_email_verified_at' => null]);
    $organizer = Organizer::create(['name' => 'Portici', 'owner_id' => $this->owner->id, 'city_id' => $this->scenario->city->id, 'is_active' => true]);
    DB::table('organizers')->where('id', $organizer->id)->update(['contact_email' => 'org@altrove.test', 'contact_mode' => 'members', 'contact_email_verified_at' => null]);
    // Un recapito salvato con il modulo spento non ha mai ricevuto niente: resta da confermare.
    $spento = Organizer::create(['name' => 'Spento', 'owner_id' => $this->owner->id, 'city_id' => $this->scenario->city->id, 'is_active' => true]);
    DB::table('organizers')->where('id', $spento->id)->update(['contact_email' => 'spento@altrove.test', 'contact_mode' => 'disabled', 'contact_email_verified_at' => null]);

    // Lo schema è già migrato: si osserva solo cosa fa la migrazione ai dati.
    Schema::spy();
    (require database_path('migrations/2026_10_30_130300_add_contact_email_verified_at.php'))->up();

    expect($this->venue->fresh()->contact_email_verified_at)->not->toBeNull()
        ->and($other->fresh()->contact_email_verified_at)->toBeNull()
        ->and($organizer->fresh()->contact_email_verified_at)->not->toBeNull()
        ->and($spento->fresh()->contact_email_verified_at)->toBeNull();
});

it('«reinvia conferma» ha un tetto per scheda e per indirizzo', function (): void {
    setVenueContactFromPanel($this->venue, $this->owner, 'attesa@altrove.test');
    Notification::assertSentOnDemandTimes(ContactEmailConfirmationRequested::class, 1);

    $page = Livewire::test(VenueProfile::class);
    $page->assertSee(__('contact.confirmation.pending', ['email' => 'attesa@altrove.test', 'days' => ContactEmailConfirmation::LINK_DAYS]));
    // Tre email al giorno verso lo stesso indirizzo, compresa quella partita col salvataggio.
    foreach (range(1, 2) as $volta) {
        $page->callAction(TestAction::make('resendContactConfirmation')->schemaComponent())
            ->assertNotified(__('contact.confirmation.resent', ['email' => 'attesa@altrove.test']));
    }
    $page->callAction(TestAction::make('resendContactConfirmation')->schemaComponent())->assertNotified(__('contact.confirmation.limit'));
    Notification::assertSentOnDemandTimes(ContactEmailConfirmationRequested::class, 3);

    // Il tetto per scheda (cinque al giorno) tiene anche cambiando indirizzo ogni volta.
    foreach (['b', 'c', 'd'] as $lettera) {
        $this->venue->fresh()->update(['contact_email' => $lettera.'@altrove.test']);
    }
    Notification::assertSentOnDemandTimes(ContactEmailConfirmationRequested::class, 5);
    expect(app(ContactEmailConfirmation::class)->send($this->venue->fresh()))->toBeFalse();
    Notification::assertSentOnDemandTimes(ContactEmailConfirmationRequested::class, 5);

    // Il tetto per indirizzo vale anche da un'altra scheda.
    $other = $this->scenario->venueB;
    $this->actingAs($this->scenario->ownerB);
    $other->update(['contact_mode' => ContactMode::Members, 'contact_email' => 'attesa@altrove.test']);
    Notification::assertSentOnDemandTimes(ContactEmailConfirmationRequested::class, 5);
    expect($other->fresh()->contact_email_verified_at)->toBeNull();
});
