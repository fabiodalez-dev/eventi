<?php

declare(strict_types=1);

use App\Actions\ApproveVenueApplication;
use App\Actions\InviteVenueMemberAction;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\CommunityStatus;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Admin\Pages\NotificationTexts;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue;
use App\Filament\Admin\Resources\Venues\RelationManagers\MembersRelationManager;
use App\Filament\Organizer\Resources\Events\DatesRelationManager;
use App\Filament\Organizer\Resources\Events\Pages\EditEvent as OrganizerEditEvent;
use App\Filament\Venue\Pages\Collaborators;
use App\Filament\Venue\Resources\Events\Pages\EditEvent as VenueEditEvent;
use App\Models\CommunityPost;
use App\Models\NotificationText;
use App\Models\Organizer;
use App\Models\SavedEvent;
use App\Models\TicketTier;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\OrganizerAccessGranted;
use App\Notifications\VenueAccessGranted;
use App\Services\Carpool\CommunitySafety;
use App\Services\Community\CommunityModeration;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VenueIsolationScenario;

/**
 * Revisione OWASP di ottobre, area pannelli: chi può fare che cosa da
 * /gestione, /organizza e /admin, e che cosa resta nel registro.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    Notification::fake();
});

function securityLog(string $evento): Activity
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $evento)->latest('id')->firstOrFail();
}

function openCollaborators(Venue $venue): void
{
    test()->get(Collaborators::getUrl(panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
}

it('non lascia a un referente declassare e poi togliere un altro referente', function (): void {
    $venue = $this->scenario->venueA;
    $secondOwner = User::factory()->create();
    $secondOwner->assignRole(UserRole::VenueOwner->value);
    $venue->members()->attach($secondOwner, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);

    $this->actingAs($this->scenario->ownerA);
    openCollaborators($venue);

    Livewire::test(Collaborators::class)
        ->callTableAction('invite', data: ['name' => 'Chiunque', 'email' => $secondOwner->email])
        ->assertTableActionHidden('remove', $secondOwner);

    expect($venue->owners()->whereKey($secondOwner->getKey())->exists())->toBeTrue();
    Notification::assertNotSentTo($secondOwner, VenueAccessGranted::class);
});

it('non declassa un referente esistente nemmeno chiamando l’azione', function (): void {
    $venue = $this->scenario->venueA;
    $owner = $this->scenario->ownerA;

    app(InviteVenueMemberAction::class)->execute($venue, $owner->email, 'x', VenueRole::Editor);

    expect($venue->owners()->whereKey($owner->getKey())->exists())->toBeTrue();
    Notification::assertNotSentTo($owner, VenueAccessGranted::class);

    // Alzare il ruolo, come fa la redazione, continua a funzionare.
    $editor = $this->scenario->editorA;
    app(InviteVenueMemberAction::class)->execute($venue, $editor->email, 'x', VenueRole::Owner);
    expect($venue->owners()->whereKey($editor->getKey())->exists())->toBeTrue();
    Notification::assertSentTo($editor, VenueAccessGranted::class);
});

it('ferma gli inviti oltre il tetto orario, per referente e per locale', function (): void {
    $venue = $this->scenario->venueA;
    RateLimiter::clear('venue-invite:'.$this->scenario->ownerA->getKey().':'.$venue->getKey());
    $this->actingAs($this->scenario->ownerA);
    openCollaborators($venue);

    $page = Livewire::test(Collaborators::class);
    foreach (range(1, 11) as $n) {
        $page->callTableAction('invite', data: ['name' => 'Persona '.$n, 'email' => "invito{$n}@esempio.test"]);
    }

    expect(User::query()->where('email', 'invito10@esempio.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'invito11@esempio.test')->exists())->toBeFalse();
    Notification::assertSentTimes(VenueAccessGranted::class, 10);

    // Il tetto è di questo referente su questo locale, non di tutti.
    $this->actingAs($this->scenario->ownerB);
    openCollaborators($this->scenario->venueB);
    Livewire::test(Collaborators::class)->callTableAction('invite', data: ['name' => 'Altro', 'email' => 'altro@esempio.test']);
    expect(User::query()->where('email', 'altro@esempio.test')->exists())->toBeTrue();
});

it('non rimanda l’invito a chi è già collaboratore del locale', function (): void {
    $venue = $this->scenario->venueA;
    $existing = $this->scenario->plainUser;

    app(InviteVenueMemberAction::class)->execute($venue, $existing->email, 'x', VenueRole::Editor);
    app(InviteVenueMemberAction::class)->execute($venue, $existing->email, 'x', VenueRole::Editor);

    Notification::assertSentToTimes($existing, VenueAccessGranted::class, 1);
});

it('scrive nel registro chi dà e chi toglie l’accesso a un locale', function (): void {
    $venue = $this->scenario->venueA;
    $this->actingAs($this->scenario->ownerA);
    openCollaborators($venue);

    // Da D64 un invito resta in sospeso finché non è accettato: il registro
    // scrive l'invito, e l'accesso concesso quando la persona dice di sì.
    Livewire::test(Collaborators::class)->callTableAction('invite', data: ['name' => 'Marta', 'email' => 'marta@esempio.test']);
    $granted = securityLog('invito_locale_inviato');
    expect($granted->causer_id)->toBe($this->scenario->ownerA->getKey())
        ->and($granted->properties->get('venue_id'))->toBe($venue->getKey());

    Livewire::test(Collaborators::class)->callTableAction('remove', $this->scenario->editorA);
    $revoked = securityLog('accesso_locale_revocato');
    expect($revoked->subject_id)->toBe($this->scenario->editorA->getKey())
        ->and($revoked->causer_id)->toBe($this->scenario->ownerA->getKey());
});

it('riporta in revisione un evento pubblicato quando l’organizzatore sposta o aggiunge una data', function (): void {
    $scenario = $this->scenario;
    $organizer = Organizer::create(['name' => 'Portici', 'owner_id' => $scenario->ownerA->id, 'city_id' => $scenario->city->id, 'is_active' => true]);
    $event = $scenario->publishedEventA;
    $event->update(['organizer_id' => $organizer->id]);
    $date = $scenario->occurrenceA;
    $other = Venue::factory()->approved()->create(['city_id' => $scenario->city->id, 'ticketing_enabled' => true]);

    $this->actingAs($scenario->ownerA);
    $this->get(OrganizerEditEvent::getUrl(['record' => $event], panel: 'organizer', tenant: $organizer))->assertOk();
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    $manager = fn () => Livewire::test(DatesRelationManager::class, ['ownerRecord' => $event->fresh(), 'pageClass' => OrganizerEditEvent::class]);

    // Annullare resta immediato: è una notizia da dare subito.
    $manager()->callTableAction('edit', $date, data: ['status' => OccurrenceStatus::Cancelled->value])->assertHasNoTableActionErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Published);

    $manager()->callTableAction('edit', $date, data: ['venue_id' => $other->id, 'status' => OccurrenceStatus::Scheduled->value])->assertHasNoTableActionErrors();
    expect($date->fresh()->venue_id)->toBe($other->id)
        ->and($event->fresh()->status)->toBe(EventStatus::Pending);

    $event->update(['status' => EventStatus::Published]);
    $manager()->callTableAction('create', data: [
        'starts_at' => CarbonImmutable::now('Europe/Rome')->addDays(20)->setTime(21, 0)->format('Y-m-d H:i:s'),
        'status' => OccurrenceStatus::Scheduled->value,
    ])->assertHasNoTableActionErrors();
    expect($event->occurrences()->count())->toBe(2)
        ->and($event->fresh()->status)->toBe(EventStatus::Pending);
});

it('riporta in revisione il testo cambiato di un evento pubblicato da un locale senza pubblicazione autonoma', function (): void {
    $venue = $this->scenario->venueA;
    $venue->update(['auto_publish' => false]);
    $event = $this->scenario->publishedEventA;
    $this->actingAs($this->scenario->ownerA);
    $this->get(VenueEditEvent::getUrl(['record' => $event], panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);

    // Salvare senza cambiare nulla non lo ritira.
    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Published);

    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['title' => 'Titolo riscritto', 'ticket_url' => 'https://altro.test/biglietti'])
        ->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Pending)
        ->and($event->fresh()->title)->toBe('Titolo riscritto');
});

it('non ritira l’evento pubblicato a ogni salvataggio per l’indicizzazione che il locale non vede', function (): void {
    $venue = $this->scenario->venueA;
    $venue->update(['auto_publish' => false]);
    $event = $this->scenario->publishedEventA;
    // Come lo lascia il modello dopo un qualsiasi salvataggio del locale, e
    // con una chiave scritta da un import che il modulo non mostra.
    $event->forceFill([
        'seo' => ['indexing' => 'automatic'],
        'content_details' => ['introduction' => '<p>Una serata</p>', 'imported_from' => 'facebook'],
    ])->saveQuietly();
    $this->actingAs($this->scenario->ownerA);
    $this->get(VenueEditEvent::getUrl(['record' => $event], panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);

    foreach (range(1, 2) as $volta) {
        Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])->call('save')->assertHasNoFormErrors();
        expect($event->fresh()->status)->toBe(EventStatus::Published);
    }

    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['price_type' => 'ticket', 'price_min' => 12, 'price_max' => 15])
        ->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Published)
        ->and((float) $event->fresh()->price_min)->toBe(12.0);

    // Il contenuto dentro le stesse colonne resta sotto revisione.
    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['seo.title' => 'Titolo per i motori'])
        ->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Pending);
});

it('riporta in revisione l’evento pubblicato quando il locale cambia la locandina', function (): void {
    Storage::fake('media');
    $venue = $this->scenario->venueA;
    $venue->update(['auto_publish' => false]);
    $event = $this->scenario->publishedEventA;
    $this->actingAs($this->scenario->ownerA);
    $this->get(VenueEditEvent::getUrl(['record' => $event], panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);

    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['poster_media' => [UploadedFile::fake()->image('locandina.png', 800, 1000)]])
        ->call('save')->assertHasNoFormErrors();

    expect($event->fresh()->getMedia('poster'))->toHaveCount(1)
        ->and($event->fresh()->status)->toBe(EventStatus::Pending);

    // La locandina già salvata, lasciata com'è, non lo ritira di nuovo.
    $event->refresh()->forceFill(['status' => EventStatus::Published])->saveQuietly();
    expect($event->fresh()->status)->toBe(EventStatus::Published);
    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Published);
});

it('riporta in revisione l’evento pubblicato quando il locale riscrive il collegamento di un biglietto, non il prezzo', function (): void {
    $venue = $this->scenario->venueA;
    $venue->update(['auto_publish' => false]);
    $event = $this->scenario->publishedEventA;
    $tier = TicketTier::factory()->create(['event_id' => $event->getKey(), 'name' => 'Intero', 'price' => 20, 'url' => 'https://biglietti.example/intero']);
    $this->actingAs($this->scenario->ownerA);
    $this->get(VenueEditEvent::getUrl(['record' => $event], panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
    $voce = 'record-'.$tier->getKey();

    // Il prezzo è una correzione: resta pubblicato.
    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])
        ->set("data.ticketTiers.{$voce}.price", 25)
        ->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Published)
        ->and((float) $tier->fresh()->price)->toBe(25.0);

    // Un collegamento nuovo è contenuto da rileggere.
    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])
        ->set("data.ticketTiers.{$voce}.url", 'https://altro.example/paga')
        ->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Pending);
});

it('lascia pubblicato l’evento modificato da un locale con pubblicazione autonoma', function (): void {
    $venue = $this->scenario->venueA;
    $venue->update(['auto_publish' => true]);
    $event = $this->scenario->publishedEventA;
    $this->actingAs($this->scenario->ownerA);
    $this->get(VenueEditEvent::getUrl(['record' => $event], panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);

    Livewire::test(VenueEditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['title' => 'Titolo riscritto'])->call('save')->assertHasNoFormErrors();
    expect($event->fresh()->status)->toBe(EventStatus::Published);
});

it('chiude i testi delle email al moderatore', function (): void {
    $this->actingAs($this->scenario->moderator)->get(NotificationTexts::getUrl(panel: 'admin'))->assertForbidden();
    expect(NotificationTexts::canAccess())->toBeFalse();
});

it('non lascia all’amministratore riscrivere o cancellare i testi della newsletter', function (): void {
    NotificationText::query()->create(['key' => 'notifications.weekend.line', 'value' => 'Scritto dal super admin :count']);
    NotificationText::dimenticaLaCache();
    $this->actingAs($this->scenario->admin)->get(NotificationTexts::getUrl(panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');

    Livewire::test(NotificationTexts::class)
        ->assertFormFieldDoesNotExist('notifications__weekend__line')
        ->set('data.notifications__weekend__line', 'Phishing :count')
        ->call('salva')->assertHasNoErrors()
        ->call('ripristinaTutto');

    expect(NotificationText::query()->where('key', 'notifications.weekend.line')->value('value'))->toBe('Scritto dal super admin :count');
});

it('nasconde al moderatore l’esportazione dell’anagrafica utenti', function (): void {
    $this->actingAs($this->scenario->moderator)->get(ListUsers::getUrl(panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(ListUsers::class)->assertActionHidden('export');

    $this->actingAs($this->scenario->admin);
    Livewire::test(ListUsers::class)->assertActionVisible('export');
});

it('non lascia al moderatore sospendere dalla community chi gli sta sopra', function (): void {
    $moderation = app(CommunityModeration::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(UserRole::SuperAdmin->value);

    foreach ([$superAdmin, $this->scenario->admin] as $target) {
        expect(fn () => $moderation->apply($this->scenario->moderator, 'user', $target->id, false))->toThrow(HttpException::class);
        expect($target->fresh()->community_suspended_at)->toBeNull();
    }

    // Verso il basso continua a funzionare.
    $moderation->apply($this->scenario->moderator, 'user', $this->scenario->plainUser->id, false);
    expect($this->scenario->plainUser->fresh()->community_suspended_at)->not->toBeNull();

    $this->actingAs($this->scenario->moderator)->get(ListUsers::getUrl(panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('community_suspend', $this->scenario->admin)
        ->assertTableActionVisible('community_suspend', $this->scenario->plainUser);
});

it('scrive nel registro la password e l’email cambiate dallo staff e chiude le sessioni dell’app', function (): void {
    $victim = $this->scenario->plainUser;
    $victim->createToken('android');
    $this->actingAs($this->scenario->admin)->get(EditUser::getUrl(['record' => $victim], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');

    Livewire::test(EditUser::class, ['record' => $victim->getRouteKey()])
        ->fillForm(['email' => 'nuova@esempio.test', 'password' => 'una-password-nuova'])
        ->call('save')->assertHasNoFormErrors();

    expect(securityLog('password_impostata_da_staff')->causer_id)->toBe($this->scenario->admin->getKey())
        ->and(securityLog('email_cambiata_da_staff')->subject_id)->toBe($victim->getKey())
        ->and($victim->tokens()->count())->toBe(0);
});

it('scrive nel registro chi elimina un account', function (): void {
    $victim = $this->scenario->plainUser;
    $this->actingAs($this->scenario->admin)->get(EditUser::getUrl(['record' => $victim], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');

    Livewire::test(EditUser::class, ['record' => $victim->getRouteKey()])->callAction('delete');

    expect($victim->fresh()->trashed())->toBeTrue()
        ->and(securityLog('utente_eliminato')->causer_id)->toBe($this->scenario->admin->getKey());
});

it('non lascia al moderatore sospendere dal social o dai passaggi chi gli sta sopra', function (): void {
    $safety = app(CommunitySafety::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(UserRole::SuperAdmin->value);

    foreach ([$superAdmin, $this->scenario->admin] as $target) {
        foreach (['social' => 'social_suspended_at', 'carpool' => 'carpool_suspended_at'] as $scope => $field) {
            expect(fn () => $safety->restrict($this->scenario->moderator, $target, $scope, true, 'Motivo qualsiasi'))->toThrow(HttpException::class);
            expect($target->fresh()->{$field})->toBeNull();
        }
    }

    // Verso il basso continua a funzionare.
    $safety->restrict($this->scenario->moderator, $this->scenario->plainUser, 'social', true, 'Abuso delle pubblicazioni');
    expect($this->scenario->plainUser->fresh()->social_suspended_at)->not->toBeNull();
});

it('non lascia al moderatore nascondere il post di chi gli sta sopra, che lo escluderebbe dalla data', function (): void {
    $admin = $this->scenario->admin;
    $saved = SavedEvent::query()->create(['user_id' => $admin->id, 'occurrence_id' => $this->scenario->occurrenceA->id]);
    $post = CommunityPost::query()->forceCreate([
        'user_id' => $admin->id, 'saved_event_id' => $saved->id, 'occurrence_id' => $this->scenario->occurrenceA->id,
        'body' => 'Ci vado', 'status' => CommunityStatus::Published->value, 'published_at' => now(),
    ]);

    expect(fn () => app(CommunityModeration::class)->apply($this->scenario->moderator, 'post', $post->id, false))->toThrow(HttpException::class);
    expect($post->fresh()->status)->toBe(CommunityStatus::Published)
        ->and(DB::table('community_restrictions')->where('user_id', $admin->id)->exists())->toBeFalse();
});

it('dice alla redazione che non parte nessun invito per chi è già nel locale con quel ruolo', function (): void {
    $venue = $this->scenario->venueA;
    $owner = $this->scenario->ownerA;
    $this->actingAs($this->scenario->admin)->get(EditVenue::getUrl(['record' => $venue], panel: 'admin'))->assertOk();
    Filament::setCurrentPanel('admin');
    $manager = fn () => Livewire::test(MembersRelationManager::class, ['ownerRecord' => $venue, 'pageClass' => EditVenue::class]);

    foreach ([VenueRole::Owner, VenueRole::Editor] as $role) {
        $manager()->callTableAction('invite_by_email', data: ['name' => 'Chiunque', 'email' => $owner->email, 'role' => $role->value])
            ->assertNotified(__('admin.notifications.already_member', ['role' => mb_strtolower(VenueRole::Owner->label())]));
    }
    Notification::assertNotSentTo($owner, VenueAccessGranted::class);

    // Un collaboratore promosso a referente riceve il suo invito.
    $editor = $this->scenario->editorA;
    $manager()->callTableAction('invite_by_email', data: ['name' => 'Editor', 'email' => $editor->email, 'role' => VenueRole::Owner->value])
        ->assertNotified(__('admin.notifications.invitation_sent'));
    Notification::assertSentTo($editor, VenueAccessGranted::class);
});

it('azzera la password di chi ha preregistrato l’email prima di dargli un locale o un organizzatore', function (): void {
    $intruder = User::factory()->unverified()->create(['email' => 'info@locale.test', 'password' => 'password-dell-intruso']);
    $intruder->forceFill(['email_claim_pending_at' => now()])->save();
    $intruder->createToken('android');

    // Un invito da accettare (D64) non apre niente: per entrare serve il collegamento
    // arrivato nella casella vera, e chi la possiede entra con il collegamento di
    // accesso, che azzera l'intruso da sé (`ClaimMailbox`). Qui non si tocca nulla.
    app(InviteVenueMemberAction::class)->execute($this->scenario->venueA, 'info@locale.test', 'Info', VenueRole::Editor);
    expect(Hash::check('password-dell-intruso', $intruder->fresh()->password))->toBeTrue()
        ->and($intruder->fresh()->canAccessTenant($this->scenario->venueA))->toBeFalse();
    Notification::assertSentTo($intruder, VenueAccessGranted::class, fn (VenueAccessGranted $n): bool => $n->toMail($intruder)->actionText === __('manage.invitation.accept_action'));
    $this->scenario->venueA->membersIncludingInvited()->detach($intruder->getKey());

    // L'accesso dato subito — la richiesta approvata — azzera invece le credenziali.
    app(InviteVenueMemberAction::class)->execute($this->scenario->venueA, 'info@locale.test', 'Info', VenueRole::Editor, requested: true);

    $user = $intruder->fresh();
    expect(Hash::check('password-dell-intruso', $user->password))->toBeFalse()
        ->and($user->tokens()->count())->toBe(0)
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->email_claim_pending_at)->not->toBeNull();
    // Il messaggio porta il collegamento per scegliere la password, che arriva solo nella casella vera.
    Notification::assertSentTo($user, VenueAccessGranted::class, fn (VenueAccessGranted $n): bool => $n->toMail($user)->actionText === __('manage.invitation.password_action'));
    expect(securityLog('credenziali_azzerate_per_accesso')->subject_id)->toBe($user->getKey());

    // Lo stesso all'approvazione di una richiesta da organizzatore.
    $other = User::factory()->unverified()->create(['email' => 'collettivo@esempio.test', 'password' => 'altra-password']);
    $application = VenueApplication::query()->forceCreate([
        'venue_name' => 'Collettivo', 'contact_name' => 'Luca', 'contact_email' => 'collettivo@esempio.test',
        'message' => 'Rassegna', 'status' => ApplicationStatus::Pending->value, 'kind' => ApplicationKind::Organizer->value,
    ]);
    $organizer = app(ApproveVenueApplication::class)->handle($application, $this->scenario->moderator);
    expect($organizer)->toBeInstanceOf(Organizer::class)
        ->and(Hash::check('altra-password', $other->fresh()->password))->toBeFalse();
    Notification::assertSentTo($other, OrganizerAccessGranted::class);

    // Chi ha già dimostrato la casella non perde niente.
    $verified = $this->scenario->plainUser;
    $verified->forceFill(['password' => 'password-sua'])->save();
    app(InviteVenueMemberAction::class)->execute($this->scenario->venueA, $verified->email, 'x', VenueRole::Editor);
    expect(Hash::check('password-sua', $verified->fresh()->password))->toBeTrue();

    // Nemmeno chi si è iscritto dall'app e ha confermato dal browser, con il sospeso ancora aperto:
    // un invito non deve bastare a scollegarlo.
    $appUser = User::factory()->create(['email' => 'iscritta@app.test', 'password' => 'password-app']);
    $appUser->forceFill(['email_claim_pending_at' => now()])->save();
    $appUser->createToken('android');
    app(InviteVenueMemberAction::class)->execute($this->scenario->venueA, $appUser->email, 'x', VenueRole::Editor);
    expect(Hash::check('password-app', $appUser->fresh()->password))->toBeTrue()
        ->and($appUser->tokens()->count())->toBe(1);
});
