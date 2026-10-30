<?php

declare(strict_types=1);

use App\Actions\Comments\PostComment;
use App\Actions\InviteVenueMemberAction;
use App\Actions\LeaveVenueAction;
use App\Actions\RespondToVenueInvitation;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Pages\Collaborators;
use App\Filament\Venue\Pages\VenueAccess;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\VenueAccessGranted;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\VenueIsolationScenario;

/*
 * D64, «Rinviato, da decidere», chiuso: un invito in un locale si accetta.
 *
 * Prima un referente che scriveva l'email di un account esistente lo
 * agganciava subito al locale: ne vedeva il nome, gli faceva arrivare le
 * email dello staff, senza che la persona avesse detto di sì. Qui si prova
 * che finché non accetta non succede niente di tutto questo, e che accettare,
 * rifiutare, ritirare e uscire fanno ciò che dicono.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    Notification::fake();
    RateLimiter::clear('venue-invite:'.$this->scenario->ownerA->getKey().':'.$this->venue->getKey());
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

function invitationLog(string $evento): ?Activity
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $evento)->latest('id')->first();
}

/** Apre la pagina dei collaboratori come farebbe il browser, poi la prepara per Livewire. */
function openInvitationCollaborators(Venue $venue): void
{
    test()->get(Collaborators::getUrl(panel: 'venue', tenant: $venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
}

/** Il collegamento «Accetta l'invito» che il messaggio ha portato alla persona. */
function invitationUrl(User $user): string
{
    $url = null;
    Notification::assertSentTo($user, VenueAccessGranted::class, function (VenueAccessGranted $n) use ($user, &$url): bool {
        $url = $n->toMail($user)->actionUrl;

        return true;
    });

    return (string) $url;
}

/** Un referente invita un account che esiste già, dalla pagina vera. */
function inviteExisting(User $owner, Venue $venue, User $invited): void
{
    test()->actingAs($owner);
    openInvitationCollaborators($venue);
    Livewire::test(Collaborators::class)->callTableAction('invite', data: ['name' => 'Nome scritto dal referente', 'email' => $invited->email]);
}

it('non dà pannello, notifiche dello staff né nome a chi è invitato finché non accetta', function (): void {
    $invited = User::factory()->create(['name' => 'Giulia Nascosta']);
    inviteExisting($this->scenario->ownerA, $this->venue, $invited);

    $row = DB::table('venue_user')->where('venue_id', $this->venue->getKey())->where('user_id', $invited->getKey())->first();
    expect($row)->not->toBeNull()
        ->and($row->accepted_at)->toBeNull()
        ->and($invited->fresh()->hasRole(UserRole::VenueEditor->value))->toBeFalse();
    expect(invitationLog('invito_locale_inviato')?->subject_id)->toBe($invited->getKey());

    // Il messaggio chiede di accettare, non annuncia un accesso che non c'è.
    Notification::assertSentTo($invited, VenueAccessGranted::class, fn (VenueAccessGranted $n): bool => $n->toMail($invited)->actionText === __('manage.invitation.accept_action'));

    // Pannello e locale chiusi, anche scrivendo l'indirizzo.
    $fresh = $invited->fresh();
    expect($fresh->canAccessPanel(Filament::getPanel('venue')))->toBeFalse()
        ->and($fresh->canAccessTenant($this->venue))->toBeFalse()
        ->and($fresh->getTenants(Filament::getPanel('venue')))->toHaveCount(0);
    $this->actingAs($fresh)->get(Filament::getPanel('venue')->getUrl($this->venue))->assertForbidden();

    // Il referente vede l'email mascherata e «in attesa», non il nome dell'account.
    $this->actingAs($this->scenario->ownerA);
    openInvitationCollaborators($this->venue);
    [$locale, $dominio] = explode('@', $invited->email);
    Livewire::test(Collaborators::class)
        ->assertSee(__('manage.collaborators.pending_name'))
        ->assertSee(mb_substr($locale, 0, 1).str_repeat('*', mb_strlen($locale) - 1).'@'.$dominio)
        ->assertDontSee('Giulia Nascosta')
        ->assertDontSee($invited->email)
        ->searchTable('Giulia')
        ->assertCountTableRecords(0);

    // Un commento sull'evento del locale avvisa lo staff, non chi è solo invitato.
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    $author = User::factory()->create();
    app(PostComment::class)->handle($this->scenario->publishedEventA, $author, 'A che ora aprite?');
    expect(ScheduledNotification::query()->where('type', NotificationType::EventNewComment->value)->where('user_id', $invited->getKey())->exists())->toBeFalse()
        ->and(ScheduledNotification::query()->where('type', NotificationType::EventNewComment->value)->where('user_id', $this->scenario->ownerA->getKey())->exists())->toBeTrue();
});

it('apre pannello, notifiche e nome dopo l’accettazione dal collegamento', function (): void {
    $invited = User::factory()->create(['name' => 'Giulia Accettata']);
    inviteExisting($this->scenario->ownerA, $this->venue, $invited);
    $url = invitationUrl($invited);

    // La GET mostra solo la domanda: niente cambia aprendo il collegamento.
    $this->actingAs($invited)->get($url)->assertOk()->assertSee($this->venue->name)->assertSee(__('manage.invitation.accept_action'));
    expect($invited->fresh()->canAccessTenant($this->venue))->toBeFalse();

    $this->actingAs($invited)->post($url, ['decision' => 'accept'])
        ->assertRedirect(Filament::getPanel('venue')->getUrl($this->venue));

    $fresh = $invited->fresh();
    expect($fresh->canAccessTenant($this->venue))->toBeTrue()
        ->and($fresh->hasRole(UserRole::VenueEditor->value))->toBeTrue()
        ->and($this->venue->editors()->whereKey($invited->getKey())->exists())->toBeTrue();
    expect(invitationLog('invito_locale_accettato')?->subject_id)->toBe($invited->getKey());
    $this->actingAs($fresh)->get(Filament::getPanel('venue')->getUrl($this->venue))->assertSuccessful();

    $this->actingAs($this->scenario->ownerA);
    openInvitationCollaborators($this->venue);
    Livewire::test(Collaborators::class)->assertSee('Giulia Accettata')->assertSee($invited->email);

    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
    app(PostComment::class)->handle($this->scenario->publishedEventA, User::factory()->create(), 'Si può prenotare?');
    expect(ScheduledNotification::query()->where('type', NotificationType::EventNewComment->value)->where('user_id', $invited->getKey())->exists())->toBeTrue();

    // Un secondo clic sul collegamento porta dentro, invece di un errore.
    $this->actingAs($fresh)->get($url)->assertRedirect(Filament::getPanel('venue')->getUrl($this->venue));
});

it('lascia rifiutare l’invito', function (): void {
    $invited = User::factory()->create();
    inviteExisting($this->scenario->ownerA, $this->venue, $invited);
    $url = invitationUrl($invited);

    $this->actingAs($invited)->post($url, ['decision' => 'decline'])->assertRedirect(route('account.profile'));

    expect(DB::table('venue_user')->where('venue_id', $this->venue->getKey())->where('user_id', $invited->getKey())->exists())->toBeFalse()
        ->and(invitationLog('invito_locale_rifiutato')?->subject_id)->toBe($invited->getKey());
    // Il collegamento non serve più a niente, nemmeno per accettare dopo.
    $this->actingAs($invited)->post($url, ['decision' => 'accept'])->assertForbidden()->assertSee(__('manage.invitation.gone'));
    expect($invited->fresh()->canAccessTenant($this->venue))->toBeFalse();
});

it('rifiuta il collegamento scaduto, alterato o aperto da un altro account', function (): void {
    $invited = User::factory()->create(['email' => 'giulia@esempio.test']);
    inviteExisting($this->scenario->ownerA, $this->venue, $invited);
    $url = invitationUrl($invited);

    // Un altro account, anche se ha il collegamento girato: errore chiaro, nessun effetto.
    $this->actingAs($this->scenario->plainUser)->get($url)->assertForbidden()->assertSee('g*****@esempio.test');
    $this->actingAs($this->scenario->plainUser)->post($url, ['decision' => 'accept'])->assertForbidden();
    expect($this->scenario->plainUser->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and($invited->fresh()->canAccessTenant($this->venue))->toBeFalse();

    // Senza accesso si passa dalla pagina di accesso, non si accetta.
    auth()->logout();
    $this->post($url, ['decision' => 'accept'])->assertRedirect(route('login'));

    // Alterato.
    $this->actingAs($invited)->get($url.'&y=1')->assertForbidden();
    $this->actingAs($invited)->get($url.'0')->assertForbidden()->assertSee(__('manage.invitation.invalid'));

    // Scaduto: dopo sette giorni serve un invito nuovo.
    $this->travel(InviteVenueMemberAction::EXPIRES_DAYS + 1)->days();
    $this->actingAs($invited)->get($url)->assertForbidden()->assertSee(__('manage.invitation.expired'));
    $this->actingAs($invited)->post($url, ['decision' => 'accept'])->assertForbidden();
    expect($invited->fresh()->canAccessTenant($this->venue))->toBeFalse();
});

it('lascia al referente reinviare e ritirare un invito, e il collegamento vecchio smette di valere', function (): void {
    $invited = User::factory()->create();
    inviteExisting($this->scenario->ownerA, $this->venue, $invited);
    $old = invitationUrl($invited);

    $this->travel(5)->minutes();
    Livewire::test(Collaborators::class)
        ->assertTableActionHidden('remove', $invited)
        ->callTableAction('resend', $invited);
    Notification::assertSentToTimes($invited, VenueAccessGranted::class, 2);
    expect(invitationLog('invito_locale_reinviato')?->causer_id)->toBe($this->scenario->ownerA->getKey());
    $this->actingAs($invited)->get($old)->assertForbidden()->assertSee(__('manage.invitation.gone'));

    $this->actingAs($this->scenario->ownerA);
    openInvitationCollaborators($this->venue);
    Livewire::test(Collaborators::class)->callTableAction('withdraw', $invited);

    expect(DB::table('venue_user')->where('venue_id', $this->venue->getKey())->where('user_id', $invited->getKey())->exists())->toBeFalse()
        ->and(invitationLog('invito_locale_ritirato')?->causer_id)->toBe($this->scenario->ownerA->getKey());

    // Reinvio e ritiro non toccano chi ha già accettato.
    Livewire::test(Collaborators::class)
        ->assertTableActionHidden('resend', $this->scenario->editorA)
        ->assertTableActionHidden('withdraw', $this->scenario->editorA);
    expect(app(InviteVenueMemberAction::class)->withdraw($this->venue, $this->scenario->editorA))->toBeFalse()
        ->and($this->scenario->editorA->fresh()->canAccessTenant($this->venue))->toBeTrue();
});

it('conta il reinvio nel tetto degli inviti', function (): void {
    $invited = User::factory()->create();
    inviteExisting($this->scenario->ownerA, $this->venue, $invited);

    $page = Livewire::test(Collaborators::class);
    foreach (range(1, 10) as $n) {
        $page->callTableAction('resend', $invited);
    }

    // Uno per l'invito e nove reinvii: l'undicesimo messaggio non parte.
    Notification::assertSentToTimes($invited, VenueAccessGranted::class, 10);
});

it('lascia uscire da solo un collaboratore', function (): void {
    $editor = $this->scenario->editorA;
    $this->actingAs($editor)->get(VenueAccess::getUrl(panel: 'venue', tenant: $this->venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);

    Livewire::test(VenueAccess::class)->callAction('leave')->assertRedirect(route('account.profile'));

    expect($editor->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and(invitationLog('uscita_dal_locale')?->subject_id)->toBe($editor->getKey());
    $this->actingAs($editor->fresh())->get(Filament::getPanel('venue')->getUrl($this->venue))->assertForbidden();
});

it('non lascia uscire l’ultimo referente, ma un referente fra due sì', function (): void {
    $owner = $this->scenario->ownerA;
    $this->actingAs($owner)->get(VenueAccess::getUrl(panel: 'venue', tenant: $this->venue))->assertOk()->assertSee(__('manage.access.last_owner'));
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);

    Livewire::test(VenueAccess::class)->assertActionDisabled('leave');
    expect(app(LeaveVenueAction::class)->execute($this->venue, $owner))->toBeFalse()
        ->and($owner->fresh()->isOwnerOfVenue((int) $this->venue->getKey()))->toBeTrue();

    $second = User::factory()->create();
    $second->assignRole(UserRole::VenueOwner->value);
    $this->venue->members()->attach($second, ['role' => VenueRole::Owner->value]);

    $this->actingAs($owner->fresh())->get(VenueAccess::getUrl(panel: 'venue', tenant: $this->venue))->assertOk();
    Livewire::test(VenueAccess::class)->assertActionEnabled('leave')->callAction('leave');
    expect($owner->fresh()->isMemberOfVenue((int) $this->venue->getKey()))->toBeFalse()
        ->and($second->fresh()->isOwnerOfVenue((int) $this->venue->getKey()))->toBeTrue();
});

it('vale come accettato quando l’account nato dall’invito sceglie la password', function (): void {
    $this->actingAs($this->scenario->ownerA);
    openInvitationCollaborators($this->venue);
    Livewire::test(Collaborators::class)->callTableAction('invite', data: ['name' => 'Nuova Persona', 'email' => 'nuova@esempio.test']);

    $invited = User::query()->where('email', 'nuova@esempio.test')->sole();
    expect($invited->canAccessTenant($this->venue))->toBeFalse();
    Notification::assertSentTo($invited, VenueAccessGranted::class, fn (VenueAccessGranted $n): bool => $n->toMail($invited)->actionText === __('manage.invitation.password_action')
        && str_contains((string) $n->toMail($invited)->actionUrl, '/reimposta-password'));

    // Un secondo invito, arrivato dopo all'account non ancora usato, resta da accettare.
    app(InviteVenueMemberAction::class)->execute($this->scenario->venueB, 'nuova@esempio.test', 'x', VenueRole::Editor);

    auth()->logout();
    $token = Password::broker()->createToken($invited);
    $this->post(route('account.password.update'), [
        'token' => $token,
        'email' => 'nuova@esempio.test',
        'password' => 'Una-password-lunga-2026!',
        'password_confirmation' => 'Una-password-lunga-2026!',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', Filament::getPanel('venue')->getUrl($this->venue));

    $fresh = $invited->fresh();
    expect($fresh->canAccessTenant($this->venue))->toBeTrue()
        ->and($fresh->hasRole(UserRole::VenueEditor->value))->toBeTrue()
        ->and($fresh->canAccessTenant($this->scenario->venueB))->toBeFalse()
        ->and(invitationLog('invito_locale_accettato')?->properties->get('tramite'))->toBe('password');
});

it('lascia entrare subito chi ha chiesto lui l’accesso e chi era già dentro', function (): void {
    $applicant = User::factory()->create();
    app(InviteVenueMemberAction::class)->execute($this->venue, $applicant->email, 'x', VenueRole::Owner, requested: true);
    expect($applicant->fresh()->canAccessTenant($this->venue))->toBeTrue();

    // Un invito in sospeso diventa accettato se poi la richiesta viene approvata.
    $pending = User::factory()->create();
    app(InviteVenueMemberAction::class)->execute($this->venue, $pending->email, 'x', VenueRole::Editor);
    expect($pending->fresh()->canAccessTenant($this->venue))->toBeFalse();
    app(InviteVenueMemberAction::class)->execute($this->venue, $pending->email, 'x', VenueRole::Editor, requested: true);
    expect($pending->fresh()->canAccessTenant($this->venue))->toBeTrue();
});

it('rende accettate tutte le appartenenze già esistenti al deploy', function (): void {
    $legacy = User::factory()->create();
    $older = User::factory()->create();
    DB::table('venue_user')->insert([
        ['venue_id' => $this->venue->getKey(), 'user_id' => $legacy->getKey(), 'role' => 'editor', 'invited_at' => '2026-09-01 10:00:00', 'accepted_at' => null, 'created_at' => '2026-09-02 10:00:00', 'updated_at' => now()],
        ['venue_id' => $this->venue->getKey(), 'user_id' => $older->getKey(), 'role' => 'owner', 'invited_at' => null, 'accepted_at' => null, 'created_at' => '2026-08-24 09:30:00', 'updated_at' => now()],
    ]);
    expect($legacy->canAccessTenant($this->venue))->toBeFalse();

    $migration = require database_path('migrations/2026_10_30_160500_add_invitation_acceptance_to_venue_user.php');
    $migration->up();

    $righe = DB::table('venue_user')->where('venue_id', $this->venue->getKey())->pluck('accepted_at', 'user_id');
    expect((string) $righe[$legacy->getKey()])->toBe('2026-09-01 10:00:00')
        ->and((string) $righe[$older->getKey()])->toBe('2026-08-24 09:30:00')
        ->and(DB::table('venue_user')->whereNull('accepted_at')->exists())->toBeFalse()
        ->and($legacy->fresh()->canAccessTenant($this->venue))->toBeTrue();
});

it('genera collegamenti che portano la data dell’invito', function (): void {
    $invited = User::factory()->create();
    $at = now()->startOfSecond();
    $url = RespondToVenueInvitation::url($this->venue, $invited, $at);

    expect($url)->toContain('/inviti-locali/'.$this->venue->getKey().'/'.$invited->getKey().'/'.$at->getTimestamp())
        ->toContain('signature=')
        ->toContain('expires=');
});
