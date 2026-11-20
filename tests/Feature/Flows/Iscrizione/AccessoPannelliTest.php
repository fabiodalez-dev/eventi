<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Models\Organizer;
use App\Models\User;
use App\Notifications\RegistrationLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\VenueIsolationScenario;

/*
 * I pannelli visti da chi ci arriva (D68): `/admin`, `/gestione` e
 * `/organizza` mandano l'ospite all'accesso unico, rispondono 403 a chi è
 * dentro senza titolo, e lasciano entrare chi il titolo ce l'ha finché ce
 * l'ha. Un locale sospeso, un invito non accettato o un ruolo tolto chiudono
 * la porta alla richiesta dopo.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    Notification::fake();
    config(['community.enabled' => false, 'app.debug' => false]);
});

function iscrizioneEntra(User $user): void
{
    test()->post('/accedi', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    test()->assertAuthenticatedAs($user);
}

it('ferma l iscritto qualunque davanti alla redazione, lo fa uscire e lascia entrare l amministratore', function (): void {
    $s = $this->scenario;

    $this->get('/admin')->assertRedirect(route('login', ['intended' => url('/admin')]));

    /* L'iscritto qualunque entra: la redazione non è per lui, va nel feed. */
    $this->post('/accedi', ['email' => $s->plainUser->email, 'password' => 'password'])
        ->assertRedirect(route('account.feed'));
    $this->get('/admin')->assertForbidden()->assertSee(route('account.logout'), false);

    /* «Esci e cambia account» dalla pagina 403. */
    $this->post('/esci', ['to' => 'login'])->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post('/accedi', ['email' => $s->admin->email, 'password' => 'password'])->assertRedirect(url('/admin'));
    $this->get('/admin')->assertOk();
});

it('porta il referente nel suo locale e gli tiene chiusi redazione, organizzatori e locali altrui', function (): void {
    $s = $this->scenario;

    /* Parte dal locale di un altro: il ritorno si scarta, entra nel suo. */
    $this->get('/accedi?intended='.urlencode(url('/gestione/'.$s->venueB->slug)))->assertOk();
    $this->post('/accedi', ['email' => $s->ownerA->email, 'password' => 'password'])
        ->assertRedirect(url('/gestione/'.$s->venueA->slug));

    $this->get('/gestione/'.$s->venueA->slug)->assertOk();
    $this->get('/gestione/'.$s->venueB->slug)->assertNotFound();
    $this->get('/admin')->assertForbidden();
    $this->get('/organizza')->assertForbidden();
});

it('chiude il pannello al referente di un locale sospeso e lo riapre quando la sospensione cade', function (): void {
    $s = $this->scenario;
    $s->venueA->forceFill(['status' => VenueStatus::Suspended])->save();

    $this->post('/accedi', ['email' => $s->ownerA->email, 'password' => 'password'])
        ->assertRedirect(route('account.feed'));
    $this->get('/gestione')->assertForbidden();
    $this->get('/gestione/'.$s->venueA->slug)->assertForbidden();
    $this->post('/esci');

    $s->venueA->forceFill(['status' => VenueStatus::Approved])->save();

    $this->post('/accedi', ['email' => $s->ownerA->email, 'password' => 'password'])
        ->assertRedirect(url('/gestione/'.$s->venueA->slug));
    $this->get('/gestione/'.$s->venueA->slug)->assertOk();
});

it('non apre il pannello a chi ha un invito non ancora accettato', function (): void {
    $s = $this->scenario;
    $invitata = User::factory()->create(['email' => 'invitata.in.attesa@example.test']);
    $invitata->assignRole(UserRole::VenueEditor->value);
    $invitata->venuesIncludingInvited()->attach($s->venueA, [
        'role' => VenueRole::Editor->value,
        'invited_at' => CarbonImmutable::now('UTC'),
        'accepted_at' => null,
    ]);

    $this->get('/gestione/'.$s->venueA->slug)->assertRedirect(route('login', ['intended' => url('/gestione/'.$s->venueA->slug)]));
    $this->post('/accedi', ['email' => 'invitata.in.attesa@example.test', 'password' => 'password'])
        ->assertRedirect(route('account.feed'));
    $this->get('/gestione')->assertForbidden();

    /* Accettato l'invito, la stessa sessione entra. */
    $invitata->venuesIncludingInvited()->updateExistingPivot($s->venueA->id, ['accepted_at' => CarbonImmutable::now('UTC')]);
    /* Una richiesta nuova rilegge l'account dal database, come in produzione. */
    app('auth')->forgetGuards();
    $this->get('/gestione/'.$s->venueA->slug)->assertOk();
});

it('toglie la redazione alla richiesta dopo a chi perde il ruolo, senza farlo uscire dal sito', function (): void {
    $s = $this->scenario;
    iscrizioneEntra($s->moderator);
    $this->get('/admin')->assertOk();

    $s->moderator->removeRole(UserRole::Moderator->value);
    app('auth')->forgetGuards();

    $this->get('/admin')->assertForbidden();
    $this->assertAuthenticatedAs($s->moderator);
    $this->get('/il-mio-profilo')->assertOk();
});

it('porta chi gestisce un organizzatore nel suo pannello e non in quello dei locali', function (): void {
    $s = $this->scenario;
    $gestore = User::factory()->create(['email' => 'gestore.collettivo@example.test']);
    $organizzatore = Organizer::query()->create([
        'name' => 'Collettivo Portello',
        'city_id' => $s->city->id,
        'owner_id' => $gestore->id,
        'is_active' => true,
    ]);

    $this->get('/organizza/'.$organizzatore->slug)->assertRedirect(route('login', ['intended' => url('/organizza/'.$organizzatore->slug)]));
    $this->post('/accedi', ['email' => 'gestore.collettivo@example.test', 'password' => 'password'])
        ->assertRedirect(url('/organizza/'.$organizzatore->slug));
    $this->get('/organizza/'.$organizzatore->slug)->assertOk();
    $this->get('/gestione')->assertForbidden();

    /* Spento l'organizzatore, il pannello si chiude. */
    $organizzatore->forceFill(['is_active' => false])->save();
    $this->get('/organizza/'.$organizzatore->slug)->assertForbidden();
});

it('rifiuta la password di un amministratore con la casella non dimostrata e gli rispedisce il collegamento', function (): void {
    $sospeso = User::factory()->create([
        'email' => 'admin.sospeso@example.test',
        'password' => Hash::make('password-admin-sospeso'),
        'email_claim_pending_at' => CarbonImmutable::now(),
    ]);
    $sospeso->assignRole(UserRole::Admin->value);

    $this->get('/admin')->assertRedirect(route('login', ['intended' => url('/admin')]));
    $this->post('/accedi', ['email' => 'admin.sospeso@example.test', 'password' => 'password-admin-sospeso'])
        ->assertSessionHasErrors(['email' => __('auth.login.claim_pending')]);
    $this->assertGuest();
    Notification::assertSentToTimes($sospeso, RegistrationLink::class, 1);

    /* Aperto dal browser che ha chiesto, il collegamento chiude il sospeso e porta in redazione. */
    $this->get(Notification::sent($sospeso, RegistrationLink::class)->first()->url)->assertRedirect(url('/admin'));
    $this->assertAuthenticatedAs($sospeso);
    $this->get('/admin')->assertOk();
});

it('butta fuori alla richiesta dopo il referente il cui account viene cancellato', function (): void {
    $s = $this->scenario;
    iscrizioneEntra($s->ownerA);
    $this->get('/gestione/'.$s->venueA->slug)->assertOk();

    app(DeleteAccount::class)($s->ownerA->fresh());
    app('auth')->forgetGuards();

    $this->get('/gestione/'.$s->venueA->slug)->assertRedirect(route('login', ['intended' => url('/gestione/'.$s->venueA->slug)]));
    $this->get('/il-mio-profilo')->assertRedirect(route('login'));
    $this->assertGuest();

    $this->post('/accedi', ['email' => $s->ownerA->email, 'password' => 'password'])->assertSessionHasErrors('email');
});
