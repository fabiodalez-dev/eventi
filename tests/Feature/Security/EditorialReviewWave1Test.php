<?php

declare(strict_types=1);

use App\Actions\Comments\PostComment;
use App\Actions\ReviewVenueApplication;
use App\Actions\Users\SyncUserRoles;
use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\EventComments\Pages\ListEventComments;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Organizers\Pages\ManageOrganizers;
use App\Filament\Admin\Resources\Reports\Pages\EditReport;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\VenueApplications\Pages\EditVenueApplication;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\ImportSource;
use App\Models\Organizer;
use App\Models\Report;
use App\Models\User;
use App\Models\VenueApplication;
use App\Notifications\EditorialUnlockLockout;
use App\Notifications\EmailAddressChanged;
use App\Notifications\VerifyEmailLink;
use App\Services\Editorial\EditorialUnlocks;
use App\Services\Editorial\EditorialVenues;
use App\Services\Geo\AddressGeocoder;
use App\Support\Venues\VenueApplicationImage;
use Filament\Facades\Filament;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * Le correzioni della revisione di sicurezza dell'ondata 1 della redazione
 * (punti 1–11 e il dubbio su `VenueApplicationImage`), nell'API dell'app e,
 * dove il difetto c'era anche lì, nel pannello `/admin`. Ogni caso fallisce
 * sul codice di prima.
 */
beforeEach(function (): void {
    Notification::fake();
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4111, 'lng' => 11.8765])->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);

    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

function rsAs(User $user, int $passwordMinutesAgo = 0, bool $unlocked = true): mixed
{
    return EditorialSession::as(test(), EditorialSession::token($user, unlocked: $unlocked, passwordMinutesAgo: $passwordMinutesAgo));
}

/** Il moderatore dello scenario con in più il ruolo globale `venue_owner`, senza essere membro di nessun locale. */
function rsModeratorOwner(VenueIsolationScenario $s): User
{
    $s->moderator->assignRole(UserRole::VenueOwner->value);

    return $s->moderator->fresh();
}

function rsSecurityRows(string $description): Collection
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $description)->orderBy('id')->get();
}

/** Il pannello dopo le richieste dell'app: via il token e la guardia di Sanctum, poi la pagina come il browser. */
function rsPanel(User $user, string $url): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    test()->flushHeaders()->actingAs($user, 'web')->get($url)->assertOk();
    Filament::setCurrentPanel('admin');
}

// Punto 1 — la scorciatoia dello staff vale solo per i permessi dei ruoli di staff.

it('non dà al moderatore con venue_owner i locali e gli eventi altrui (policy)', function (): void {
    $moderator = rsModeratorOwner($this->s);
    $gate = Gate::forUser($moderator);

    expect($gate->allows('update', $this->s->venueB))->toBeFalse()
        ->and($gate->allows('manageCollaborators', $this->s->venueB))->toBeFalse()
        ->and($gate->allows('viewSensitiveData', $this->s->venueB))->toBeFalse()
        ->and($gate->allows('update', $this->s->draftEventB))->toBeFalse()
        ->and($gate->allows('delete', $this->s->draftEventB))->toBeFalse()
        ->and($gate->allows('forceDelete', $this->s->draftEventB))->toBeFalse()
        ->and($gate->allows('create', Event::class))->toBeFalse()
        ->and($gate->allows('viewAny', ImportSource::class))->toBeFalse()
        // Quello che il moderatore ha dal suo ruolo resta: vedere e moderare.
        ->and($gate->allows('view', $this->s->draftEventB))->toBeTrue()
        ->and($gate->allows('moderate', $this->s->venueB))->toBeTrue()
        ->and($gate->allows('publish', $this->s->draftEventB))->toBeTrue();

    // Referente vero del locale A: lì vale, come per chiunque altro; sul B no.
    $moderator->venues()->attach($this->s->venueA, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);
    $moderator = $moderator->fresh();
    expect(Gate::forUser($moderator)->allows('update', $this->s->venueA))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('delete', $this->s->draftEventA))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('update', $this->s->venueB))->toBeFalse();

    // Admin e super admin come prima.
    foreach ([$this->s->admin, $this->superAdmin] as $staff) {
        expect(Gate::forUser($staff)->allows('update', $this->s->venueB))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('forceDelete', $this->s->draftEventB))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('viewAny', ImportSource::class))->toBeTrue();
    }
});

it('chiude al moderatore con venue_owner la modifica dei locali e degli eventi altrui nell’API', function (): void {
    $moderator = rsModeratorOwner($this->s);

    rsAs($moderator)->patchJson('/api/v1/editorial/venues/'.$this->s->venueB->id, ['name' => 'Preso'])->assertForbidden();
    rsAs($moderator)->postJson('/api/v1/editorial/venues/'.$this->s->venueB->id.'/members', ['user_id' => $moderator->id, 'role' => 'owner'])->assertForbidden();
    rsAs($moderator)->deleteJson('/api/v1/editorial/events/'.$this->s->draftEventB->id)->assertForbidden();

    expect($this->s->venueB->fresh()->name)->toBe('Teatro Belzoni')
        ->and(Event::query()->whereKey($this->s->draftEventB->id)->exists())->toBeTrue()
        ->and($this->s->venueB->membersIncludingInvited()->whereKey($moderator->id)->exists())->toBeFalse();

    // Admin come prima.
    rsAs($this->s->admin)->patchJson('/api/v1/editorial/venues/'.$this->s->venueB->id, ['name' => 'Teatro Belzoni Nuovo'])->assertOk();
});

it('chiude al moderatore con venue_owner la scheda di modifica dei locali altrui nel pannello', function (): void {
    $moderator = rsModeratorOwner($this->s);

    $this->actingAs($moderator)->get(EditVenue::getUrl(['record' => $this->s->venueB], panel: 'admin'))->assertForbidden();
    $this->actingAs($this->s->admin)->get(EditVenue::getUrl(['record' => $this->s->venueB], panel: 'admin'))->assertOk();
});

it('dall’app non dà venue_owner o venue_editor a chi è staff, e lo dà a chi non lo è', function (): void {
    $url = '/api/v1/editorial/users/'.$this->s->moderator->id.'/roles';
    rsAs($this->s->admin)->putJson($url, ['roles' => ['moderator', 'venue_owner']])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['roles']]]);
    expect($this->s->moderator->fresh()->hasRole(UserRole::VenueOwner->value))->toBeFalse();

    // Nemmeno insieme alla promozione a moderatore di chi non lo era.
    rsAs($this->s->admin)->putJson('/api/v1/editorial/users/'.$this->s->plainUser->id.'/roles', ['roles' => ['moderator', 'venue_editor']])->assertStatus(422);
    expect($this->s->plainUser->fresh()->hasRole(UserRole::Moderator->value))->toBeFalse();

    // Né alla creazione di un account della redazione.
    rsAs($this->s->admin)->postJson('/api/v1/editorial/users', [
        'name' => 'Nuova', 'email' => 'nuova.redazione@example.test', 'password' => 'Una-password-lunga-2026!',
        'timezone' => 'Europe/Rome', 'locale' => 'it', 'roles' => ['moderator', 'venue_owner'],
    ])->assertStatus(422);
    expect(User::query()->where('email', 'nuova.redazione@example.test')->exists())->toBeFalse();

    // A chi non è staff sì, come prima.
    rsAs($this->s->admin)->putJson('/api/v1/editorial/users/'.$this->s->plainUser->id.'/roles', ['roles' => ['venue_owner']])->assertOk();
    expect($this->s->plainUser->fresh()->hasRole(UserRole::VenueOwner->value))->toBeTrue();

    // Chi li ha già li tiene: la regola toglie solo la possibilità di conferirli.
    $this->s->moderator->assignRole(UserRole::VenueOwner->value);
    expect(app(SyncUserRoles::class)->syncNames($this->s->admin, $this->s->moderator->fresh(), ['moderator', 'venue_owner'], inApp: true))
        ->toBe(['moderator', 'venue_owner']);
});

it('non lascia approvare una richiesta mandata da sé, né dall’app né dal pannello', function (): void {
    $own = VenueApplication::factory()->create(['user_id' => $this->s->moderator->id, 'contact_email' => 'altro@example.test']);
    $byEmail = VenueApplication::factory()->create(['user_id' => null, 'contact_email' => strtoupper($this->s->admin->email)]);

    rsAs($this->s->moderator)->getJson('/api/v1/editorial/applications/'.$own->id)->assertOk()->assertJsonPath('data.abilities.approve', false);
    rsAs($this->s->moderator)->postJson('/api/v1/editorial/applications/'.$own->id.'/approve')->assertForbidden();
    rsAs($this->s->admin)->postJson('/api/v1/editorial/applications/'.$byEmail->id.'/approve')->assertForbidden();
    expect($own->fresh()->status)->toBe(ApplicationStatus::Pending)
        ->and($byEmail->fresh()->status)->toBe(ApplicationStatus::Pending);

    // La regola sta anche nell'azione che chiamano tutti e due.
    expect(fn () => app(ReviewVenueApplication::class)->approve($own, $this->s->moderator))->toThrow(ValidationException::class);

    rsPanel($this->s->admin, VenueApplicationResource::getUrl('edit', ['record' => $byEmail]));
    Livewire::test(EditVenueApplication::class, ['record' => $byEmail->getKey()])->assertActionHidden('approva')->assertActionVisible('rifiuta');

    // Un'altra persona della redazione la approva.
    rsAs($this->s->admin)->postJson('/api/v1/editorial/applications/'.$own->id.'/approve')->assertOk();
    expect($own->fresh()->status)->toBe(ApplicationStatus::Approved);
});

// Punto 2 — «Unisci» chiede `delete` anche sull'evento assorbito.

it('non lascia unire con il proprio l’evento di un altro (API e pannello)', function (): void {
    $organizer = Organizer::create(['name' => 'Collettivo del moderatore', 'owner_id' => $this->s->moderator->id, 'city_id' => $this->s->city->id, 'is_active' => true]);
    $keep = Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => null, 'organizer_id' => $organizer->id, 'title' => 'Evento del collettivo']);
    expect(Gate::forUser($this->s->moderator)->allows('delete', $keep))->toBeTrue();

    rsAs($this->s->moderator)->postJson('/api/v1/editorial/events/'.$keep->id.'/merge', ['absorbed' => $this->s->publishedEventB->id])->assertForbidden();
    expect(Event::query()->whereKey($this->s->publishedEventB->id)->exists())->toBeTrue();

    rsPanel($this->s->moderator, '/admin/events');
    Livewire::test(ListEvents::class)->callTableAction('merge', $keep, ['absorbed' => $this->s->publishedEventB->id])->assertForbidden();
    expect(Event::query()->whereKey($this->s->publishedEventB->id)->exists())->toBeTrue();
});

// Punto 3 — creare un utente chiede la password recente.

it('chiede la password recente per creare un utente', function (): void {
    rsAs($this->s->admin, passwordMinutesAgo: 16)->postJson('/api/v1/editorial/users', [
        'name' => 'Nuovo', 'email' => 'nuovo@example.test', 'password' => 'Una-password-lunga-2026!',
        'timezone' => 'Europe/Rome', 'locale' => 'it', 'roles' => ['moderator'],
    ])->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect(User::query()->where('email', 'nuovo@example.test')->exists())->toBeFalse();
});

// Punto 5 — persone di un locale e di un organizzatore.

it('chiede la password recente per dare il ruolo di referente di un locale, non per il collaboratore', function (): void {
    $base = '/api/v1/editorial/venues/'.$this->s->venueB->id.'/members';
    $candidate = User::factory()->create(['email_verified_at' => now()]);
    $other = User::factory()->create(['email_verified_at' => now()]);

    rsAs($this->s->admin, passwordMinutesAgo: 16)->postJson($base, ['user_id' => $candidate->id, 'role' => 'owner'])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    rsAs($this->s->admin, passwordMinutesAgo: 16)->postJson($base.'/invite', ['name' => 'Referente', 'email' => 'referente@nuovo.example', 'role' => 'owner'])
        ->assertStatus(423);
    rsAs($this->s->admin, passwordMinutesAgo: 16)->postJson($base.'/invite', ['name' => 'Senza ruolo', 'email' => 'predefinito@nuovo.example'])
        ->assertStatus(423);
    expect($this->s->venueB->membersIncludingInvited()->whereKey($candidate->id)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'referente@nuovo.example')->exists())->toBeFalse();

    rsAs($this->s->admin, passwordMinutesAgo: 16)->postJson($base, ['user_id' => $other->id, 'role' => 'editor'])->assertCreated();
    rsAs($this->s->admin, passwordMinutesAgo: 16)->patchJson($base.'/'.$other->id, ['role' => 'owner', 'accepted_at' => now()->toIso8601String()])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    expect($other->fresh()->isOwnerOfVenue($this->s->venueB->id))->toBeFalse();

    rsAs($this->s->admin)->patchJson($base.'/'.$other->id, ['role' => 'owner', 'accepted_at' => now()->toIso8601String()])->assertOk();
    expect($other->fresh()->isOwnerOfVenue($this->s->venueB->id))->toBeTrue();
});

it('scrive nel registro di sicurezza chi entra ed esce da un organizzatore (API e pannello)', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $member = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Portici', 'owner_id' => $first->id, 'city_id' => $this->s->city->id, 'is_active' => true]);

    rsAs($this->superAdmin)->patchJson('/api/v1/editorial/organizers/'.$organizer->id, ['owner_id' => $second->id, 'users' => [$member->id]])->assertOk();

    $granted = rsSecurityRows('accesso_organizzatore_concesso');
    $revoked = rsSecurityRows('accesso_organizzatore_revocato');
    expect($granted->map(fn (Activity $row): array => [(int) $row->subject_id, $row->getProperty('ruolo')])->all())
        ->toEqualCanonicalizing([[$second->id, 'responsabile'], [$member->id, 'collaboratore']])
        ->and($revoked->map(fn (Activity $row): array => [(int) $row->subject_id, $row->getProperty('ruolo')])->all())->toBe([[$first->id, 'responsabile']])
        ->and((int) $granted->first()->getProperty('organizer_id'))->toBe($organizer->id)
        ->and((int) $granted->first()->causer_id)->toBe($this->superAdmin->id)
        ->and($granted->first()->getProperty('canale'))->toBe('app');

    // Un salvataggio che non cambia le persone non scrive niente.
    rsAs($this->superAdmin)->patchJson('/api/v1/editorial/organizers/'.$organizer->id, ['name' => 'Portici di Padova'])->assertOk();
    expect(rsSecurityRows('accesso_organizzatore_concesso'))->toHaveCount(2);

    rsPanel($this->superAdmin, ManageOrganizers::getUrl(panel: 'admin'));
    Livewire::test(ManageOrganizers::class)
        ->callTableAction('edit', $organizer->fresh(), data: ['owner_id' => $first->id, 'users' => []])->assertHasNoTableActionErrors();

    expect(rsSecurityRows('accesso_organizzatore_concesso')->last()->subject_id)->toBe($first->id)
        ->and(rsSecurityRows('accesso_organizzatore_revocato')->pluck('subject_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toEqualCanonicalizing([$first->id, $second->id, $member->id])
        ->and((int) rsSecurityRows('accesso_organizzatore_revocato')->last()->causer_id)->toBe($this->superAdmin->id);
});

// Punto 6 — dieci sblocchi sbagliati in 24 ore.

it('revoca il token dopo dieci password sbagliate in un giorno e avvisa la persona e i super admin', function (): void {
    $token = EditorialSession::token($this->s->moderator, unlocked: false);
    $tokenId = PersonalAccessToken::findToken($token)?->getKey();

    for ($i = 1; $i <= 9; $i++) {
        EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'sbagliata'])->assertUnprocessable();
        if ($i % 5 === 0) {
            $this->travel(16)->minutes();
        }
    }
    expect(PersonalAccessToken::query()->whereKey($tokenId)->exists())->toBeTrue();

    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'sbagliata'])
        ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(PersonalAccessToken::query()->whereKey($tokenId)->exists())->toBeFalse()
        ->and(rsSecurityRows('redazione_sblocco_fallito'))->toHaveCount(10);
    $revoked = rsSecurityRows('redazione_token_revocato')->sole();
    expect((int) $revoked->subject_id)->toBe($this->s->moderator->id)
        ->and((int) $revoked->getProperty('tentativi'))->toBe(EditorialUnlocks::LOCKOUT_FAILURES)
        ->and((int) $revoked->getProperty('token_id'))->toBe($tokenId);
    Notification::assertSentTo($this->s->moderator, EditorialUnlockLockout::class);
    Notification::assertSentTo($this->superAdmin, EditorialUnlockLockout::class);
    Notification::assertNotSentTo($this->s->admin, EditorialUnlockLockout::class);

    // Il token non c'è più: anche la password giusta non serve.
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertUnauthorized();
});

it('azzera il conto degli errori con uno sblocco riuscito', function (): void {
    $token = EditorialSession::token($this->s->admin, unlocked: false);
    for ($i = 1; $i <= 9; $i++) {
        EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'sbagliata'])->assertUnprocessable();
        if ($i % 5 === 0) {
            $this->travel(16)->minutes();
        }
    }
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertOk();
    $this->travel(16)->minutes();
    EditorialSession::as($this, $token)->postJson('/api/v1/editorial/unlock', ['password' => 'sbagliata'])->assertUnprocessable();

    expect(rsSecurityRows('redazione_token_revocato'))->toHaveCount(0);
    Notification::assertNothingSentTo($this->s->admin);
});

// Punto 7 — lo stato del locale solo dalle rotte di moderazione.

it('ignora lo stato nel PATCH del locale dell’app e lo mostra senza salvarlo nel modulo del pannello', function (): void {
    rsAs($this->s->admin)->patchJson('/api/v1/editorial/venues/'.$this->s->venueA->id, ['status' => 'suspended', 'plan' => 'premium'])->assertOk();
    expect($this->s->venueA->fresh()->status)->toBe(VenueStatus::Approved)
        ->and($this->s->venueA->fresh()->plan->value)->toBe('premium');

    rsPanel($this->s->admin, EditVenue::getUrl(['record' => $this->s->venueA], panel: 'admin'));
    Livewire::test(EditVenue::class, ['record' => $this->s->venueA->getRouteKey()])
        ->assertFormFieldIsDisabled('status')
        ->fillForm(['status' => 'suspended'])
        ->call('save')->assertHasNoFormErrors();
    expect($this->s->venueA->fresh()->status)->toBe(VenueStatus::Approved);
});

// Punto 8 — la propria email cambiata si riconferma e il vecchio indirizzo lo sa.

it('fa riconfermare la propria email cambiata dall’app e avvisa il vecchio indirizzo', function (): void {
    $old = (string) $this->s->admin->email;
    rsAs($this->s->admin)->putJson('/api/v1/editorial/users/'.$this->s->admin->id.'/credentials', ['email' => 'nuova.admin@example.test'])->assertOk();

    $admin = $this->s->admin->fresh();
    expect($admin->email)->toBe('nuova.admin@example.test')
        ->and($admin->email_verified_at)->toBeNull();
    Notification::assertSentTo($admin, VerifyEmailLink::class);
    Notification::assertSentOnDemand(EmailAddressChanged::class, fn (EmailAddressChanged $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $old);
});

it('fa riconfermare la propria email cambiata dal pannello e avvisa il vecchio indirizzo', function (): void {
    $old = (string) $this->superAdmin->email;
    rsPanel($this->superAdmin, '/admin/users/'.$this->superAdmin->id.'/edit');
    Livewire::test(EditUser::class, ['record' => $this->superAdmin->getKey()])
        ->fillForm(['email' => 'nuova.super@example.test'])
        ->call('save')->assertHasNoFormErrors();

    $super = $this->superAdmin->fresh();
    expect($super->email)->toBe('nuova.super@example.test')->and($super->email_verified_at)->toBeNull();
    Notification::assertSentTo($super, VerifyEmailLink::class);
    Notification::assertSentOnDemand(EmailAddressChanged::class, fn (EmailAddressChanged $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $old);

    // L'email di un'altra persona dal pannello: la conferma resta quella che decide chi la cambia, il vecchio indirizzo è avvisato.
    $target = User::factory()->create(['email' => 'vecchia.persona@example.test']);
    rsPanel($this->superAdmin, '/admin/users/'.$target->id.'/edit');
    Livewire::test(EditUser::class, ['record' => $target->getKey()])->fillForm(['email' => 'nuova.persona@example.test'])->call('save')->assertHasNoFormErrors();
    expect($target->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertSentOnDemand(EmailAddressChanged::class, fn (EmailAddressChanged $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'vecchia.persona@example.test');
});

// Punto 9 — eliminazioni definitive nel registro.

it('scrive nel registro l’eliminazione di segnalazioni e commenti dall’app', function (): void {
    $reporter = User::factory()->create();
    $report = Report::factory()->about($this->s->publishedEventA)->create(['reporter_user_id' => $reporter->id]);
    rsAs($this->s->moderator)->deleteJson('/api/v1/editorial/reports/'.$report->id)->assertOk();

    $row = rsSecurityRows('segnalazione_eliminata')->sole();
    expect((int) $row->getProperty('report_id'))->toBe($report->id)
        ->and((int) $row->getProperty('autore_id'))->toBe($reporter->id)
        ->and((int) $row->causer_id)->toBe($this->s->moderator->id)
        ->and($row->getProperty('canale'))->toBe('app');

    $author = User::factory()->create();
    $comment = app(PostComment::class)->handle($this->s->publishedEventA, $author, 'Commento da togliere');
    app(PostComment::class)->handle($this->s->publishedEventA, $author, 'Risposta al commento', $comment);
    rsAs($this->s->admin)->deleteJson('/api/v1/editorial/comments/'.$comment->id)->assertOk();

    $row = rsSecurityRows('commento_eliminato')->sole();
    expect((int) $row->getProperty('comment_id'))->toBe($comment->id)
        ->and((int) $row->getProperty('autore_id'))->toBe($author->id)
        ->and((int) $row->getProperty('risposte'))->toBe(1)
        ->and((int) $row->causer_id)->toBe($this->s->admin->id)
        ->and(EventComment::query()->count())->toBe(0);
});

it('scrive nel registro l’eliminazione di segnalazioni e commenti dal pannello', function (): void {
    $report = Report::factory()->about($this->s->publishedEventA)->create();
    rsPanel($this->s->admin, '/admin/reports/'.$report->id.'/edit');
    Livewire::test(EditReport::class, ['record' => $report->getKey()])->callAction('delete');
    expect(Report::query()->whereKey($report->id)->exists())->toBeFalse()
        ->and((int) rsSecurityRows('segnalazione_eliminata')->sole()->getProperty('report_id'))->toBe($report->id);

    $comment = app(PostComment::class)->handle($this->s->publishedEventA, $this->s->plainUser, 'Commento del pannello');
    rsPanel($this->s->admin, '/admin/event-comments');
    Livewire::test(ListEventComments::class)->callTableAction('delete', $comment)->assertHasNoTableActionErrors();
    expect(EventComment::query()->whereKey($comment->id)->exists())->toBeFalse()
        ->and((int) rsSecurityRows('commento_eliminato')->sole()->getProperty('comment_id'))->toBe($comment->id)
        ->and((int) rsSecurityRows('commento_eliminato')->sole()->causer_id)->toBe($this->s->admin->id);
});

// Punto 10 — esportazione dei locali con un tetto.

it('legge al massimo 5000 locali per l’esportazione', function (): void {
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });
    app(EditorialVenues::class)->exportRows('all', [], '', 'name');

    expect(collect($sql)->contains(fn (string $statement): bool => str_contains($statement, 'from `venues`') && str_contains($statement, 'limit 5000')))->toBeTrue();
});

// Punto 11 — `%` e `_` sono lettere nelle ricerche.

it('cerca % e _ come lettere fra i candidati di un locale e nelle richieste', function (): void {
    $underscore = User::factory()->create(['name' => 'Mario', 'email' => 'mario_rossi@example.test']);
    User::factory()->create(['name' => 'Luca', 'email' => 'luca@example.test']);
    $ids = rsAs($this->s->admin)->getJson('/api/v1/editorial/venues/'.$this->s->venueB->id.'/members/candidates?q=_')->assertOk()->json('data.*.id');
    expect($ids)->toBe([$underscore->id]);

    VenueApplication::factory()->create(['venue_name' => 'Bar Sport', 'contact_name' => 'Anna', 'contact_email' => 'anna@example.test']);
    $percent = VenueApplication::factory()->create(['venue_name' => 'Sconto 100% Club', 'contact_name' => 'Bea', 'contact_email' => 'bea@example.test']);
    $found = rsAs($this->s->moderator)->getJson('/api/v1/editorial/applications?tab=all&q='.rawurlencode('%'))->assertOk()->json('data.*.id');
    expect($found)->toBe([$percent->id]);
});

// Dubbio — l'immagine di una richiesta resta nella sua cartella.

it('non serve file fuori dalla cartella delle richieste, nemmeno con .. o percorsi assoluti', function (): void {
    Storage::fake('local');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Storage::disk('local')->put('privato/segreto.png', $png);
    Storage::disk('local')->put('venue-applications/vera.png', $png);

    foreach (['venue-applications/../privato/segreto.png', '/privato/segreto.png', 'venue-applications\\..\\privato\\segreto.png', 'venue-applications//vera.png', 'venue-applications/./vera.png'] as $path) {
        expect(VenueApplicationImage::safePath($path))->toBeNull();
        $application = VenueApplication::factory()->create(['image_path' => $path]);
        expect(fn () => VenueApplicationImage::response($application))->toThrow(NotFoundHttpException::class);
    }

    expect(VenueApplicationImage::safePath('venue-applications/vera.png'))->toBe('venue-applications/vera.png')
        ->and(VenueApplicationImage::response(VenueApplication::factory()->create(['image_path' => 'venue-applications/vera.png']))->getStatusCode())->toBe(200);
});
