<?php

declare(strict_types=1);

use App\Actions\InviteVenueMemberAction;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Filament\Venue\Pages\Collaborators;
use App\Models\Organizer;
use App\Models\User;
use App\Notifications\VenueAccessGranted;
use App\Services\Management\VenueCollaborators;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\VenueIsolationScenario;

/*
 * P8 — i collaboratori del locale e «Il tuo accesso» dall'app (W-CO-01…06).
 * Le regole sono quelle della pagina «Collaboratori» del pannello, estratte
 * in `VenueCollaborators` e `RemoveVenueMember`: qui si prova che l'API le
 * segue, compreso il tetto degli inviti condiviso con il sito.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->venue = $this->s->venueA;
    $this->url = '/api/v1/management/venues/'.$this->venue->id;
    foreach (VenueCollaborators::limitKeys($this->s->ownerA, $this->venue) as $key) {
        RateLimiter::clear($key);
    }
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);
});

function collaboratorLog(string $evento): ?Activity
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $evento)->latest('id')->first();
}

it('mostra al referente le persone del locale, con gli inviti in attesa senza nome e con l’email mascherata', function (): void {
    $invited = User::factory()->create(['name' => 'Zelinda Nascosta', 'email' => 'invitata@esempio.test']);
    app(InviteVenueMemberAction::class)->execute($this->venue, $invited->email, 'Zelinda', VenueRole::Editor);
    Sanctum::actingAs($this->s->ownerA);

    $rows = collect($this->getJson($this->url.'/members')->assertOk()
        ->assertJsonPath('meta.invite_expires_days', InviteVenueMemberAction::EXPIRES_DAYS)
        ->assertJsonPath('meta.invites_per_hour', 10)
        ->assertJsonPath('meta.invites_per_day', 30)
        ->json('data'));

    expect($rows->pluck('email')->all())->toBe([
        ...collect([$this->s->ownerA, $this->s->editorA])->sortBy('name')->pluck('email')->all(),
        'i*******@esempio.test',
    ])
        ->and($rows->pluck('email'))->not->toContain($this->s->ownerB->email);

    $pending = $rows->last();
    expect($pending['name'])->toBeNull()
        ->and($pending['pending'])->toBeTrue()
        ->and($pending['accepted_at'])->toBeNull()
        ->and($pending['can_resend'])->toBeTrue()
        ->and($pending['can_withdraw'])->toBeTrue()
        ->and($pending['can_remove'])->toBeFalse();

    $owner = $rows->firstWhere('id', $this->s->ownerA->id);
    $editor = $rows->firstWhere('id', $this->s->editorA->id);
    expect($owner['role'])->toBe('owner')->and($owner['is_me'])->toBeTrue()->and($owner['can_remove'])->toBeFalse()
        ->and($editor['role'])->toBe('editor')->and($editor['name'])->toBe($this->s->editorA->name)->and($editor['can_remove'])->toBeTrue();

    // La ricerca per nome non trova un invito: direbbe di chi è l'account.
    $this->getJson($this->url.'/members?q=Zelinda')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->url.'/members?q=invitata@')->assertOk()->assertJsonCount(1, 'data');
});

it('chiude i collaboratori al collaboratore e all’organizzazione', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $this->getJson($this->url.'/members')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    $this->postJson($this->url.'/members', ['name' => 'Marta', 'email' => 'marta@esempio.test'])->assertForbidden();
    $this->deleteJson($this->url.'/members/'.$this->s->ownerA->id)->assertForbidden();

    // Un referente di un altro locale non entra nemmeno: 404 dalla porta del soggetto.
    Sanctum::actingAs($this->s->ownerB);
    $this->getJson($this->url.'/members')->assertNotFound();

    $owner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/management/organizers/'.$organizer->id.'/members')->assertNotFound();
    $this->getJson('/api/v1/management/organizers/'.$organizer->id.'/access')->assertNotFound();
});

it('invita sempre come collaboratore, come il modulo del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->url.'/members', ['name' => 'Marta Bianchi', 'email' => 'MARTA@esempio.test', 'role' => 'owner'])->assertCreated()
        ->assertJsonPath('data.name', null)
        ->assertJsonPath('data.email', 'm****@esempio.test')
        ->assertJsonPath('data.role', 'editor')
        ->assertJsonPath('data.pending', true);

    $invited = User::query()->where('email', 'marta@esempio.test')->sole();
    expect($this->venue->membersIncludingInvited()->whereKey($invited->id)->first()?->pivot?->role)->toBe(VenueRole::Editor->value)
        ->and($this->venue->members()->whereKey($invited->id)->exists())->toBeFalse()
        ->and(collaboratorLog('invito_locale_inviato')?->causer_id)->toBe($this->s->ownerA->id);
    Notification::assertSentTo($invited, VenueAccessGranted::class);

    // Già invitato, già dentro: un errore sul campo, nessun messaggio in più.
    $this->postJson($this->url.'/members', ['name' => 'Marta', 'email' => 'marta@esempio.test'])->assertUnprocessable()
        ->assertJsonPath('error.fields.email.0', __('manage.collaborators.already_invited'));
    $this->postJson($this->url.'/members', ['name' => 'Ed', 'email' => $this->s->editorA->email])->assertUnprocessable()
        ->assertJsonPath('error.fields.email.0', __('manage.collaborators.already_member'));
    $this->postJson($this->url.'/members', ['name' => '', 'email' => 'non-una-email'])->assertUnprocessable()
        ->assertJsonValidationErrorFor('name', 'error.fields')->assertJsonValidationErrorFor('email', 'error.fields');
    Notification::assertSentToTimes($invited, VenueAccessGranted::class, 1);
});

it('conta inviti e reinvii dell’app nello stesso secchio della pagina del sito', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    foreach (range(1, 9) as $n) {
        $this->postJson($this->url.'/members', ['name' => 'Persona '.$n, 'email' => "persona{$n}@esempio.test"])->assertCreated();
    }

    // Il decimo dal pannello: stesso tetto, stesse chiavi.
    $this->actingAs($this->s->ownerA, 'web')->get(Collaborators::getUrl(panel: 'venue', tenant: $this->venue))->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(Collaborators::class)->callTableAction('invite', data: ['name' => 'Decima', 'email' => 'decima@esempio.test']);
    expect(User::query()->where('email', 'decima@esempio.test')->exists())->toBeTrue();

    // L'undicesimo, dall'app, si ferma; anche il reinvio.
    $this->postJson($this->url.'/members', ['name' => 'Undicesima', 'email' => 'undicesima@esempio.test'])->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertJsonPath('error.message', __('manage.collaborators.invite_limit'))
        ->assertJsonPath('error.fields.invite.0', __('manage.collaborators.invite_limit'));
    $first = User::query()->where('email', 'persona1@esempio.test')->sole();
    $this->postJson($this->url.'/members/'.$first->id.'/resend')->assertStatus(429);
    expect(User::query()->where('email', 'undicesima@esempio.test')->exists())->toBeFalse();

    // E il pannello vede il tetto consumato dall'app.
    Livewire::test(Collaborators::class)->callTableAction('invite', data: ['name' => 'Dodicesima', 'email' => 'dodicesima@esempio.test']);
    expect(User::query()->where('email', 'dodicesima@esempio.test')->exists())->toBeFalse();
});

it('reinvia e ritira un invito in attesa, mai un’appartenenza accettata', function (): void {
    $invited = User::factory()->create();
    app(InviteVenueMemberAction::class)->execute($this->venue, $invited->email, 'Invitata', VenueRole::Editor);
    Sanctum::actingAs($this->s->ownerA);

    $this->travel(5)->minutes();
    $this->postJson($this->url.'/members/'.$invited->id.'/resend')->assertOk()
        ->assertJsonPath('data.pending', true)
        ->assertJsonPath('message', __('manage.collaborators.resent'));
    Notification::assertSentToTimes($invited, VenueAccessGranted::class, 2);
    expect(collaboratorLog('invito_locale_reinviato')?->causer_id)->toBe($this->s->ownerA->id);

    // Chi ha accettato non si reinvita né si ritira; chi non è del locale non esiste.
    $this->postJson($this->url.'/members/'.$this->s->editorA->id.'/resend')->assertForbidden();
    $this->deleteJson($this->url.'/members/'.$this->s->editorA->id.'/invitation')->assertForbidden();
    $this->postJson($this->url.'/members/'.$this->s->ownerB->id.'/resend')->assertNotFound();

    $this->deleteJson($this->url.'/members/'.$invited->id.'/invitation')->assertOk()
        ->assertJsonPath('data.message', __('manage.collaborators.withdrawn'));
    expect(DB::table('venue_user')->where('venue_id', $this->venue->id)->where('user_id', $invited->id)->exists())->toBeFalse()
        ->and(collaboratorLog('invito_locale_ritirato')?->causer_id)->toBe($this->s->ownerA->id)
        ->and($this->s->editorA->fresh()->canAccessTenant($this->venue))->toBeTrue();
});

it('toglie un collaboratore, mai un referente né un invito', function (): void {
    $invited = User::factory()->create();
    app(InviteVenueMemberAction::class)->execute($this->venue, $invited->email, 'Invitata', VenueRole::Editor);
    $second = User::factory()->create();
    $second->assignRole(UserRole::VenueOwner->value);
    $this->venue->members()->attach($second, ['role' => VenueRole::Owner->value, 'accepted_at' => now()]);
    Sanctum::actingAs($this->s->ownerA);

    $this->deleteJson($this->url.'/members/'.$second->id)->assertForbidden();
    $this->deleteJson($this->url.'/members/'.$this->s->ownerA->id)->assertForbidden();
    $this->deleteJson($this->url.'/members/'.$invited->id)->assertForbidden();

    $this->deleteJson($this->url.'/members/'.$this->s->editorA->id)->assertOk()
        ->assertJsonPath('data.message', __('manage.collaborators.removed'));

    $log = collaboratorLog('accesso_locale_revocato');
    expect($this->s->editorA->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and(User::query()->whereKey($this->s->editorA->id)->exists())->toBeTrue()
        ->and($log?->subject_id)->toBe($this->s->editorA->id)
        ->and($log?->causer_id)->toBe($this->s->ownerA->id)
        ->and($log?->properties['ruolo'] ?? null)->toBe('editor');
    $this->deleteJson($this->url.'/members/'.$this->s->editorA->id)->assertNotFound();
});

it('mostra «Il tuo accesso» a ogni persona del locale e lascia uscire il collaboratore', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->url.'/access')->assertOk()
        ->assertJsonPath('data.venue.id', $this->venue->id)
        ->assertJsonPath('data.role', 'editor')
        ->assertJsonPath('data.is_last_owner', false)
        ->assertJsonPath('data.can_leave', true)
        ->assertJsonPath('data.can_manage_collaborators', false);

    $this->postJson($this->url.'/access/leave')->assertOk()
        ->assertJsonPath('data.remaining', 0)
        ->assertJsonPath('data.message', __('manage.access.left', ['venue' => $this->venue->name]));

    expect($this->s->editorA->fresh()->canAccessTenant($this->venue))->toBeFalse()
        ->and(collaboratorLog('uscita_dal_locale')?->subject_id)->toBe($this->s->editorA->id);
    $this->getJson($this->url.'/access')->assertNotFound();
});

it('non lascia uscire l’ultimo referente, ma un referente fra due sì', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson($this->url.'/access')->assertOk()
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.is_last_owner', true)
        ->assertJsonPath('data.can_leave', false)
        ->assertJsonPath('data.can_manage_collaborators', true);
    $this->postJson($this->url.'/access/leave')->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT')
        ->assertJsonPath('error.message', __('manage.access.last_owner'));
    expect($this->s->ownerA->fresh()->isOwnerOfVenue($this->venue->id))->toBeTrue();

    $second = User::factory()->create();
    $second->assignRole(UserRole::VenueOwner->value);
    $this->venue->members()->attach($second, ['role' => VenueRole::Owner->value, 'accepted_at' => now()]);

    $this->getJson($this->url.'/access')->assertOk()->assertJsonPath('data.can_leave', true);
    // Gestiva solo questo locale: non gli resta niente da aprire.
    $this->postJson($this->url.'/access/leave')->assertOk()->assertJsonPath('data.remaining', 0);
    expect($second->fresh()->isOwnerOfVenue($this->venue->id))->toBeTrue();
});
