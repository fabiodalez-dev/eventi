<?php

declare(strict_types=1);

use App\Enums\CarpoolCaseStatus;
use App\Enums\CommunityStatus;
use App\Enums\Permission;
use App\Enums\RideStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\Carpool;
use App\Filament\Admin\Pages\Community;
use App\Models\CarpoolAudit;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\RideConversation;
use App\Models\RideFeedback;
use App\Models\RideReview;
use App\Models\SavedEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\EditorialSession;

/*
 * R7 — community e passaggi nell'app della redazione (R-CM-01…05,
 * R-CP-01…10, R-CP-12): le pagine «Profili e post» e «Passaggi in auto» del
 * pannello, con gli stessi permessi, le stesse regole e gli stessi limiti.
 * La griglia: moderatore, admin, super admin, referente di un locale, utente
 * comune, token non sbloccato (423), password non recente sulle azioni
 * pericolose (423). Ogni azione scrive nel registro dei passaggi come il
 * pannello.
 */
beforeEach(function (): void {
    Notification::fake();
    cpSetup($this);
    (new RolesAndPermissionsSeeder)->run();
    $this->moderator = EditorialSession::user(UserRole::Moderator);
    $this->admin = EditorialSession::user(UserRole::Admin);
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->owner = EditorialSession::user(UserRole::VenueOwner);
    $this->plain = User::factory()->create();
    $this->staff = [$this->moderator, $this->admin, $this->superAdmin];
    $this->outsiders = [$this->owner, $this->plain];
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** Una persona della community con il suo profilo pubblico. */
function r7Person(string $name = 'Marta Bellini', ?UserRole $role = null): User
{
    $user = User::factory()->create(['name' => $name]);
    if ($role !== null) {
        $user->assignRole($role->value);
    }
    $user->communityProfile()->create(['handle' => 'persona_'.$user->id, 'display_name' => $name, 'bio' => 'Mi piace la musica dal vivo.', 'visibility' => 'public']);

    return $user->fresh();
}

/** Una pubblicazione su una serata, come la scrive `Community::publication`. */
function r7Post(mixed $test, User $author, string $body = 'Consiglio questa serata'): CommunityPost
{
    $saved = SavedEvent::query()->create(['user_id' => $author->id, 'occurrence_id' => $test->date->id]);

    return CommunityPost::query()->create(['user_id' => $author->id, 'saved_event_id' => $saved->id, 'occurrence_id' => $test->date->id,
        'body' => $body, 'intent' => 'recommend', 'status' => CommunityStatus::Published, 'published_at' => now()]);
}

function r7Comment(CommunityPost $post, User $author, string $body = 'Ci vengo anche io!'): CommunityComment
{
    return CommunityComment::query()->create(['community_post_id' => $post->id, 'user_id' => $author->id, 'body' => $body, 'status' => CommunityStatus::Published]);
}

/** Fuori la gente comune (404), chiuso senza sblocco o con lo sblocco scaduto (423). */
function r7Gate(mixed $test, string $method, string $url, array $body = []): void
{
    foreach ($test->outsiders as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url, $body)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->moderator, unlocked: false))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    EditorialSession::as($test, EditorialSession::token($test->admin, unlockedHoursAgo: 12))->json($method, $url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
}

/** Un'azione pericolosa senza la password degli ultimi 15 minuti: 423 `PASSWORD_REQUIRED`. */
function r7NeedsPassword(mixed $test, User $person, string $url, array $body): void
{
    EditorialSession::as($test, EditorialSession::token($person, passwordMinutesAgo: 16))->postJson($url, $body)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
}

/** Un 422 con l'errore sotto il campo, nella busta degli errori dell'API. */
function r7Invalid(mixed $response, string $field): void
{
    $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect($response->json('error.fields'))->toHaveKey($field);
}

function r7As(mixed $test, User $person): mixed
{
    return EditorialSession::as($test, EditorialSession::token($person));
}

/** Toglie un permesso a un ruolo, per provare le sezioni riservate. */
function r7Revoke(UserRole $role, Permission $permission): void
{
    Role::findByName($role->value, 'web')->revokePermissionTo($permission->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

// ------------------------------------------------------------ community

it('elenca le quattro sezioni della community a tutta la redazione e tiene fuori gli altri', function (): void {
    $author = r7Person('Marta Bellini');
    $post = r7Post($this, $author);
    r7Comment($post, $author);
    DB::table('community_restrictions')->insert(['user_id' => $author->id, 'occurrence_id' => $this->date->id, 'created_at' => now(), 'updated_at' => now()]);

    foreach (['profiles', 'posts', 'comments', 'restrictions'] as $section) {
        r7Gate($this, 'GET', '/api/v1/editorial/community/'.$section);
    }

    foreach ($this->staff as $person) {
        $profile = r7As($this, $person)->getJson('/api/v1/editorial/community/profiles')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 24)->json('data.0');
        expect($profile)->toMatchArray(['kind' => 'profile', 'user_id' => $author->id, 'display_name' => 'Marta Bellini', 'handle' => 'persona_'.$author->id,
            'featured' => false, 'suspended' => false, 'visibility' => 'public', 'visibility_label' => 'Tutti, anche senza account'])
            ->and($profile['abilities'])->toBe(['feature' => true, 'suspend' => true, 'unsuspend' => false]);

        $row = r7As($this, $person)->getJson('/api/v1/editorial/community/posts')->assertOk()->json('data.0');
        expect($row)->toMatchArray(['kind' => 'post', 'id' => $post->id, 'author' => 'Marta Bellini', 'body' => 'Consiglio questa serata', 'hidden' => false])
            ->and($row['event_title'])->toBe($this->date->event->title)
            ->and($row['abilities'])->toBe(['hide' => true, 'restore' => false]);

        r7As($this, $person)->getJson('/api/v1/editorial/community/comments')->assertOk()
            ->assertJsonPath('data.0.kind', 'comment')->assertJsonPath('data.0.body', 'Ci vengo anche io!');

        $restriction = r7As($this, $person)->getJson('/api/v1/editorial/community/restrictions')->assertOk()->json('data.0');
        expect($restriction)->toMatchArray(['kind' => 'restriction', 'user_id' => $author->id, 'name' => 'Marta Bellini', 'occurrence_id' => $this->date->id,
            'event_title' => $this->date->event->title, 'timezone' => $this->city->timezone, 'abilities' => ['remove' => true]]);
    }
});

it('cerca come la pagina: nome nei profili e nelle restrizioni, testo in pubblicazioni e commenti', function (): void {
    $marta = r7Person('Marta Bellini');
    $luca = r7Person('Luca Rinaldi');
    r7Post($this, $marta, 'Concerto imperdibile');
    r7Post($this, $luca, 'Serata tranquilla');

    r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/profiles?q=Luca')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.display_name', 'Luca Rinaldi');
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/posts?q=imperdibile')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.author', 'Marta Bellini');
    // `%` e `_` si cercano alla lettera.
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/posts?q=%25')->assertOk()->assertJsonPath('meta.total', 0);
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/unknown')->assertNotFound();
});

it('tiene costante il numero di query degli elenchi della community', function (): void {
    $token = EditorialSession::token($this->moderator);
    $queries = function () use ($token): array {
        EditorialSession::as($this, $token)->getJson('/api/v1/editorial/community/posts')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        EditorialSession::as($this, $token)->getJson('/api/v1/editorial/community/posts')->assertOk();
        $log = collect(DB::getQueryLog())->pluck('query')->all();
        DB::disableQueryLog();

        return $log;
    };
    r7Post($this, r7Person('Prima'));
    $one = $queries();
    foreach (['Seconda' => 11, 'Terza' => 12, 'Quarta' => 13] as $name => $day) {
        $occurrence = occurrenceAt($this->city, $this->category, '2026-10-'.$day.' 19:00:00');
        $author = r7Person($name, UserRole::Moderator);
        $saved = SavedEvent::query()->create(['user_id' => $author->id, 'occurrence_id' => $occurrence->id]);
        CommunityPost::query()->create(['user_id' => $author->id, 'saved_event_id' => $saved->id, 'occurrence_id' => $occurrence->id, 'body' => 'Bella', 'status' => CommunityStatus::Published, 'published_at' => now()]);
    }
    $four = $queries();

    expect(count($four))->toBe(count($one), implode("\n", $four));
});

it('mette e toglie un profilo dall\'evidenza con il motivo e lo scrive nei registri', function (): void {
    $author = r7Person();
    $profile = $author->communityProfile;
    $url = '/api/v1/editorial/community/profiles/'.$profile->id.'/feature';

    r7Gate($this, 'POST', $url, ['enabled' => true, 'reason' => 'Profilo molto attivo']);
    r7Invalid(r7As($this, $this->moderator)->postJson($url, ['enabled' => true, 'reason' => 'no']), 'reason');
    r7Invalid(r7As($this, $this->moderator)->postJson($url, ['reason' => 'Profilo molto attivo']), 'enabled');

    r7As($this, $this->moderator)->postJson($url, ['enabled' => true, 'reason' => 'Profilo molto attivo'])->assertOk()
        ->assertJsonPath('data.featured', true)->assertJsonPath('meta.message', __('admin.community_page.done'));
    expect($profile->fresh()->featured)->toBeTrue();
    $audit = CarpoolAudit::query()->where('action', 'social_moderation')->latest('id')->firstOrFail();
    expect($audit->actor_id)->toBe($this->moderator->id)->and($audit->metadata)->toMatchArray(['kind' => 'profile', 'enabled' => true, 'reason' => 'Profilo molto attivo']);
    $activity = Activity::query()->where('log_name', 'community')->where('event', 'moderation')->latest('id')->firstOrFail();
    expect($activity->getProperty('canale'))->toBe('app');

    r7As($this, $this->admin)->postJson($url, ['enabled' => false, 'reason' => 'Tolto dalla selezione'])->assertOk()->assertJsonPath('data.featured', false);
    expect($profile->fresh()->featured)->toBeFalse();
});

it('sospende con la password recente solo chi sta sotto nello staff, e riattiva', function (): void {
    $author = r7Person();
    $colleague = r7Person('Altra moderatrice', UserRole::Moderator);
    $url = '/api/v1/editorial/community/users/'.$author->id.'/suspend';
    $body = ['reason' => 'Messaggi offensivi ripetuti'];

    r7Gate($this, 'POST', $url, $body);
    r7NeedsPassword($this, $this->moderator, $url, $body);

    r7As($this, $this->moderator)->postJson($url, $body)->assertOk()
        ->assertJsonPath('data.kind', 'profile')->assertJsonPath('data.suspended', true)
        ->assertJsonPath('data.abilities.suspend', false)->assertJsonPath('data.abilities.unsuspend', true);
    expect($author->fresh()->community_suspended_at)->not->toBeNull();

    // Un pari grado no: come il pannello (403), e la riga non offre il pulsante.
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/profiles?q=Altra')->assertOk()->assertJsonPath('data.0.abilities.suspend', false);
    r7As($this, $this->moderator)->postJson('/api/v1/editorial/community/users/'.$colleague->id.'/suspend', $body)->assertForbidden();
    expect($colleague->fresh()->community_suspended_at)->toBeNull();

    // Riattivare non chiede la password ed è libero.
    r7As($this, $this->moderator)->postJson('/api/v1/editorial/community/users/'.$author->id.'/unsuspend', ['reason' => 'Chiarimento avvenuto'])->assertOk()
        ->assertJsonPath('data.suspended', false);
    expect($author->fresh()->community_suspended_at)->toBeNull();
});

it('nasconde una pubblicazione bloccando la serata, e toglie la restrizione', function (): void {
    $author = r7Person();
    $post = r7Post($this, $author);
    $url = '/api/v1/editorial/community/posts/'.$post->id.'/hide';

    r7Gate($this, 'POST', $url, ['reason' => 'Contenuto fuori tema']);
    r7As($this, $this->moderator)->postJson($url, ['reason' => 'Contenuto fuori tema'])->assertOk()
        ->assertJsonPath('data.hidden', true)->assertJsonPath('data.abilities', ['hide' => false, 'restore' => true]);
    expect($post->fresh()->status)->toBe(CommunityStatus::Hidden)
        ->and(DB::table('community_restrictions')->where('user_id', $author->id)->where('occurrence_id', $this->date->id)->exists())->toBeTrue();

    $restriction = (int) DB::table('community_restrictions')->value('id');
    $remove = '/api/v1/editorial/community/restrictions/'.$restriction;
    foreach ($this->outsiders as $outsider) {
        EditorialSession::as($this, EditorialSession::token($outsider))->deleteJson($remove, ['reason' => 'Serata di nuovo aperta'])->assertNotFound();
    }
    r7Invalid(r7As($this, $this->admin)->deleteJson($remove, ['reason' => 'x']), 'reason');
    r7As($this, $this->admin)->deleteJson($remove, ['reason' => 'Serata di nuovo aperta'])->assertOk()->assertJsonPath('data.removed', true);
    expect(DB::table('community_restrictions')->count())->toBe(0)
        ->and($post->fresh()->status)->toBe(CommunityStatus::Published)
        ->and(CarpoolAudit::query()->where('action', 'social_restriction_restored')->value('metadata'))->toMatchArray(['reason' => 'Serata di nuovo aperta']);
    r7As($this, $this->admin)->deleteJson($remove, ['reason' => 'Serata di nuovo aperta'])->assertNotFound();
});

it('nasconde e ripristina un commento, ma non quello di chi sta sopra nello staff', function (): void {
    $post = r7Post($this, r7Person());
    $comment = r7Comment($post, r7Person('Commentatore'));
    $adminComment = r7Comment($post, r7Person('Amministratrice', UserRole::Admin), 'Avviso della redazione');

    r7As($this, $this->moderator)->postJson('/api/v1/editorial/community/comments/'.$comment->id.'/hide', ['reason' => 'Insulto a un utente'])->assertOk()
        ->assertJsonPath('data.kind', 'comment')->assertJsonPath('data.hidden', true);
    r7As($this, $this->moderator)->postJson('/api/v1/editorial/community/comments/'.$comment->id.'/restore', ['reason' => 'Rivisto il contesto'])->assertOk()
        ->assertJsonPath('data.hidden', false);
    expect($comment->fresh()->status)->toBe(CommunityStatus::Published);

    $rows = collect(r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/comments')->json('data'))->keyBy('id');
    expect($rows[$adminComment->id]['abilities']['hide'])->toBeFalse();
    r7As($this, $this->moderator)->postJson('/api/v1/editorial/community/comments/'.$adminComment->id.'/hide', ['reason' => 'Insulto a un utente'])->assertForbidden();
    r7As($this, $this->superAdmin)->postJson('/api/v1/editorial/community/comments/'.$adminComment->id.'/hide', ['reason' => 'Rimosso su richiesta'])->assertOk();
});

it('chiude la community a chi non ha community.manage, come il pannello', function (): void {
    r7Revoke(UserRole::Moderator, Permission::ManageCommunity);
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/profiles')->assertForbidden();
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/cases')->assertOk();
});

// ------------------------------------------------------------ passaggi

it('elenca i casi a tutta la redazione, con lo stato come filtro e senza ricerca', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $url = '/api/v1/editorial/carpool/cases';
    r7Gate($this, 'GET', $url);

    foreach ($this->staff as $person) {
        $row = r7As($this, $person)->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        expect($row)->toMatchArray(['kind' => 'case', 'id' => $case->id, 'reporter' => $this->passenger->name, 'status' => 'open',
            'status_label' => CarpoolCaseStatus::Open->label(), 'assignee' => null, 'reason' => 'safety']);
    }
    r7As($this, $this->moderator)->getJson($url.'?filter[status]=resolved')->assertOk()->assertJsonPath('meta.total', 0);
    // La pagina non cerca fra i casi: la ricerca non toglie niente.
    r7As($this, $this->moderator)->getJson($url.'?q=nessuno')->assertOk()->assertJsonPath('meta.total', 1);
    r7As($this, $this->moderator)->getJson($url.'?filter[status]=sconosciuto')->assertStatus(422);
});

it('riserva passaggi, persone, recensioni, feedback e consegne a carpool.manage', function (): void {
    cpCase($this, cpAccepted($this));
    r7Revoke(UserRole::Moderator, Permission::ManageCarpool);

    foreach (['cases', 'audit'] as $section) {
        r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/'.$section)->assertOk();
    }
    foreach (['rides', 'users', 'reviews', 'feedback', 'deliveries'] as $section) {
        r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/'.$section)->assertForbidden();
        r7As($this, $this->admin)->getJson('/api/v1/editorial/carpool/'.$section)->assertOk();
    }
});

it('chiude i passaggi a chi non ha community_cases.manage', function (): void {
    r7Revoke(UserRole::Moderator, Permission::ManageCommunityCases);
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/cases')->assertForbidden();
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/community/profiles')->assertOk();
});

it('mostra il caso con la conversazione; conservazione ed evidenze solo ad admin e super admin', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $url = '/api/v1/editorial/carpool/cases/'.$case->id;
    r7Gate($this, 'GET', $url);

    $mine = r7As($this, $this->moderator)->getJson($url)->assertOk()->json('data');
    expect($mine)->toMatchArray(['id' => $case->id, 'body' => 'Vorrei chiarire questo problema.', 'revision' => 1, 'ride_request_id' => $case->ride_request_id, 'hold_until' => null])
        ->and($mine['abilities'])->toMatchArray(['assign' => true, 'note' => true, 'resolve' => true, 'reopen' => false, 'reply' => true,
            'freeze' => true, 'unfreeze' => true, 'hold' => false, 'release' => false, 'evidence' => false, 'export' => false])
        ->and(collect($mine['people'])->pluck('id')->all())->toEqualCanonicalizing([$this->passenger->id, $this->driver->id]);

    foreach ([$this->admin, $this->superAdmin] as $person) {
        r7As($this, $person)->getJson($url)->assertOk()
            ->assertJsonPath('data.abilities.hold', true)->assertJsonPath('data.abilities.evidence', true)->assertJsonPath('data.abilities.export', true);
    }
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/cases/999999')->assertNotFound();
});

it('assegna, annota, risolve e riapre con la revisione letta; una revisione vecchia è un conflitto', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $url = '/api/v1/editorial/carpool/cases/'.$case->id.'/manage';
    r7Gate($this, 'POST', $url, ['op' => 'assign', 'reason' => 'Esamino la segnalazione', 'revision' => 1]);

    r7As($this, $this->moderator)->postJson($url, ['op' => 'assign', 'reason' => 'Esamino la segnalazione', 'revision' => 1])->assertOk()
        ->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.assignee', $this->moderator->name)->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.messages.0.internal', true)->assertJsonPath('data.messages.0.body', 'Esamino la segnalazione');
    r7As($this, $this->admin)->postJson($url, ['op' => 'resolve', 'reason' => 'Chiudo il caso', 'revision' => 1])
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    r7Invalid(r7As($this, $this->admin)->postJson($url, ['op' => 'hold', 'reason' => 'Chiudo il caso', 'revision' => 2]), 'op');
    r7As($this, $this->admin)->postJson($url, ['op' => 'resolve', 'reason' => 'Chiudo il caso', 'revision' => 2])->assertOk()
        ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.abilities.reopen', true)->assertJsonPath('data.abilities.resolve', false);
    r7As($this, $this->admin)->postJson($url, ['op' => 'reopen', 'reason' => 'Nuovi elementi', 'revision' => 3])->assertOk()->assertJsonPath('data.status', 'open');

    expect(CarpoolAudit::query()->where('subject_type', 'CarpoolCase')->where('subject_id', $case->id)->pluck('action')->all())
        ->toContain('case_assign', 'case_resolve', 'case_reopen');
});

it('risponde solo a una persona del caso e mette il caso in attesa', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $url = '/api/v1/editorial/carpool/cases/'.$case->id.'/reply';

    r7As($this, $this->moderator)->postJson($url, ['recipient_id' => $this->plain->id, 'message' => 'Puoi raccontarmi meglio?', 'revision' => 1])->assertStatus(422);
    r7Invalid(r7As($this, $this->moderator)->postJson($url, ['recipient_id' => $this->driver->id, 'message' => 'ok', 'revision' => 1]), 'message');
    r7As($this, $this->moderator)->postJson($url, ['recipient_id' => $this->driver->id, 'message' => 'Puoi raccontarmi meglio?', 'revision' => 1])->assertOk()
        ->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.messages.0.recipient', $this->driver->name)->assertJsonPath('data.messages.0.internal', false);
    expect(CarpoolAudit::query()->where('action', 'case_reply')->where('actor_id', $this->moderator->id)->exists())->toBeTrue();
});

it('preserva e rilascia le evidenze solo ad admin e super admin, con la password recente', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $url = '/api/v1/editorial/carpool/cases/'.$case->id.'/hold';
    $body = ['op' => 'hold', 'reason' => 'Possibile denuncia in corso', 'revision' => 1];

    r7Gate($this, 'POST', $url, $body);
    r7NeedsPassword($this, $this->admin, $url, $body);
    r7As($this, $this->moderator)->postJson($url, $body)->assertForbidden();

    r7As($this, $this->admin)->postJson($url, $body)->assertOk()->assertJsonPath('data.revision', 2);
    expect($case->fresh()->hold_until)->not->toBeNull();
    r7As($this, $this->superAdmin)->postJson($url, ['op' => 'release', 'reason' => 'Verifica conclusa', 'revision' => 2])->assertOk()->assertJsonPath('data.hold_until', null);
    expect($case->fresh()->hold_until)->toBeNull()
        ->and(CarpoolAudit::query()->where('subject_id', $case->id)->where('subject_type', 'CarpoolCase')->pluck('action')->all())->toContain('case_hold', 'case_release');
});

it('fa leggere le evidenze solo ad admin e super admin, con motivo e password, e lo registra', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $url = '/api/v1/editorial/carpool/cases/'.$case->id.'/evidence';
    $body = ['reason' => 'Verifica della segnalazione'];

    r7Gate($this, 'POST', $url, $body);
    r7NeedsPassword($this, $this->admin, $url, $body);
    r7As($this, $this->moderator)->postJson($url, $body)->assertForbidden();
    r7Invalid(r7As($this, $this->admin)->postJson($url, ['reason' => 'no']), 'reason');

    $evidence = r7As($this, $this->admin)->postJson($url, $body)->assertOk()->json('data');
    expect($evidence)->toHaveKeys(['case_id', 'request_id', 'messages', 'generated_at', 'operator_id', 'audit', 'sha256'])
        ->and($evidence['case_id'])->toBe($case->id)->and($evidence['operator_id'])->toBe($this->admin->id);
    $audit = CarpoolAudit::query()->where('action', 'evidence_view')->where('actor_id', $this->admin->id)->firstOrFail();
    expect($audit->metadata)->toBe(['reason' => 'Verifica della segnalazione']);
});

it('oscura e riapre la chat del passaggio del caso', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    $url = '/api/v1/editorial/carpool/cases/'.$case->id.'/freeze';

    r7Invalid(r7As($this, $this->moderator)->postJson($url, ['reason' => 'Messaggi minacciosi']), 'freeze');
    r7As($this, $this->moderator)->postJson($url, ['freeze' => true, 'reason' => 'Messaggi minacciosi'])->assertOk()->assertJsonPath('data.revision', 2);
    expect(RideConversation::query()->where('ride_request_id', $ride->id)->value('hidden_at'))->not->toBeNull();
    r7As($this, $this->moderator)->postJson($url, ['freeze' => false, 'reason' => 'Chiarito con le parti'])->assertOk();
    expect(RideConversation::query()->where('ride_request_id', $ride->id)->value('hidden_at'))->toBeNull()
        ->and(CarpoolAudit::query()->whereIn('action', ['chat_hidden', 'chat_restored'])->count())->toBe(2);
});

it('elenca i passaggi con stato e ricerca, e annulla quelli ancora aperti', function (): void {
    $offer = cpOffer($this);
    $url = '/api/v1/editorial/carpool/rides';
    r7Gate($this, 'GET', $url);

    $row = r7As($this, $this->moderator)->getJson($url)->assertOk()->json('data.0');
    expect($row)->toMatchArray(['kind' => 'ride', 'id' => $offer->id, 'driver' => $this->driver->name, 'zone' => 'Prato della Valle', 'capacity' => 3, 'requests_count' => 0, 'status' => 'open'])
        ->and($row['abilities'])->toBe(['cancel' => true]);
    r7As($this, $this->moderator)->getJson($url.'?q=Valle')->assertOk()->assertJsonPath('meta.total', 1);
    r7As($this, $this->moderator)->getJson($url.'?q=Arcella')->assertOk()->assertJsonPath('meta.total', 0);
    r7As($this, $this->moderator)->getJson($url.'?filter[status]=cancelled')->assertOk()->assertJsonPath('meta.total', 0);

    $cancel = '/api/v1/editorial/carpool/rides/'.$offer->id.'/cancel';
    r7Gate($this, 'POST', $cancel, ['reason' => 'Conducente segnalato più volte']);
    r7As($this, $this->moderator)->postJson($cancel, ['reason' => 'Conducente segnalato più volte'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.abilities.cancel', false);
    expect($offer->fresh()->status)->toBe(RideStatus::Cancelled)
        ->and(CarpoolAudit::query()->where('action', 'admin_ride_cancelled')->value('metadata'))->toBe(['reason' => 'Conducente segnalato più volte']);
    r7As($this, $this->moderator)->postJson($cancel, ['reason' => 'Conducente segnalato più volte'])->assertStatus(409);
});

it('nasconde e ripristina una recensione di passaggio', function (): void {
    $ride = cpAccepted($this);
    $review = RideReview::query()->create(['ride_request_id' => $ride->id, 'user_id' => $this->passenger->id, 'driver_id' => $this->driver->id, 'rating' => 2, 'body' => 'Guida troppo veloce', 'status' => CommunityStatus::Published]);
    $url = '/api/v1/editorial/carpool/reviews/'.$review->id.'/moderate';

    $row = r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/reviews')->assertOk()->json('data.0');
    expect($row)->toMatchArray(['kind' => 'review', 'author' => $this->passenger->name, 'driver' => $this->driver->name, 'rating' => 2, 'body' => 'Guida troppo veloce', 'hidden' => false])
        ->and($row['abilities'])->toBe(['hide' => true, 'restore' => false]);
    r7Gate($this, 'POST', $url, ['visible' => false, 'reason' => 'Dati personali nel testo']);
    r7As($this, $this->moderator)->postJson($url, ['visible' => false, 'reason' => 'Dati personali nel testo'])->assertOk()->assertJsonPath('data.hidden', true);
    expect($review->fresh()->status)->toBe(CommunityStatus::Hidden);
    r7As($this, $this->admin)->postJson($url, ['visible' => true, 'reason' => 'Testo corretto dall\'autore'])->assertOk()->assertJsonPath('data.hidden', false);
    expect(CarpoolAudit::query()->where('action', 'ride_review_moderated')->count())->toBe(2);
});

it('mostra feedback privati e registro', function (): void {
    $ride = cpAccepted($this);
    RideFeedback::query()->create(['ride_request_id' => $ride->id, 'user_id' => $this->passenger->id, 'kind' => 'problem', 'body' => 'Il conducente non è arrivato']);

    r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/feedback')->assertOk()
        ->assertJsonPath('data.0.author', $this->passenger->name)->assertJsonPath('data.0.feedback_kind', 'problem')
        ->assertJsonPath('data.0.body', 'Il conducente non è arrivato')->assertJsonPath('data.0.ride_request_id', $ride->id);
    $audit = r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/audit?q=request_accepted')->assertOk()->json('data');
    expect(collect(r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/audit')->json('data'))->pluck('kind')->unique()->all())->toBe(['audit']);
    expect($audit)->toBeArray();
});

it('sospende e ripristina una persona per ambito, verso il basso, con la password recente', function (): void {
    $person = carpoolPerson();
    $colleague = EditorialSession::user(UserRole::Moderator);
    $url = '/api/v1/editorial/carpool/users/'.$person->id.'/suspend';
    $body = ['scope' => 'carpool', 'reason' => 'Comportamento scorretto in auto'];

    $row = collect(r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/users?q='.urlencode($person->name))->assertOk()->json('data'))->firstWhere('id', $person->id);
    expect($row['abilities'])->toBe(['suspend_social' => true, 'restore_social' => false, 'suspend_carpool' => true, 'restore_carpool' => false,
        'suspend_global' => false, 'restore_global' => false])->and($row)->not->toHaveKey('email');
    $adminRow = collect(r7As($this, $this->admin)->getJson('/api/v1/editorial/carpool/users?q='.urlencode($person->name))->json('data'))->firstWhere('id', $person->id);
    expect($adminRow['abilities']['suspend_global'])->toBeTrue();

    r7Gate($this, 'POST', $url, $body);
    r7NeedsPassword($this, $this->moderator, $url, $body);
    r7Invalid(r7As($this, $this->moderator)->postJson($url, ['scope' => 'altro', 'reason' => 'Comportamento scorretto']), 'scope');
    r7As($this, $this->moderator)->postJson($url, $body)->assertOk()->assertJsonPath('data.carpool_suspended', true)
        ->assertJsonPath('data.abilities.restore_carpool', true);
    expect($person->fresh()->carpool_suspended_at)->not->toBeNull();
    // Tutta la community: chiede `users.manage`, che il moderatore non ha.
    r7As($this, $this->moderator)->postJson($url, ['scope' => 'global', 'reason' => 'Comportamento scorretto in auto'])->assertForbidden();
    // Un pari grado no.
    r7As($this, $this->moderator)->postJson('/api/v1/editorial/carpool/users/'.$colleague->id.'/suspend', $body)->assertForbidden();

    r7As($this, $this->moderator)->postJson('/api/v1/editorial/carpool/users/'.$person->id.'/restore', $body)->assertOk()->assertJsonPath('data.carpool_suspended', false);
    expect($person->fresh()->carpool_suspended_at)->toBeNull()
        ->and(CarpoolAudit::query()->where('subject_type', 'User')->where('subject_id', $person->id)->pluck('action')->all())->toContain('user_restricted', 'user_restored');
});

it('rimette in coda solo le consegne fallite dopo otto tentativi', function (): void {
    $insert = fn (int $attempts): int => (int) DB::table('community_delivery_outbox')->insertGetId([
        'user_id' => $this->passenger->id, 'notification_id' => (string) Str::uuid(), 'dedupe_key' => (string) Str::uuid(), 'payload' => '{}',
        'available_at' => now(), 'attempts' => $attempts, 'last_error' => 'timeout', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $failed = $insert(8);
    $trying = $insert(2);

    $rows = collect(r7As($this, $this->moderator)->getJson('/api/v1/editorial/carpool/deliveries')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$failed])->toMatchArray(['attempts' => 8, 'last_error' => 'timeout', 'user_id' => $this->passenger->id, 'abilities' => ['retry' => true]])
        ->and($rows[$trying]['abilities'])->toBe(['retry' => false]);

    r7Gate($this, 'POST', '/api/v1/editorial/carpool/deliveries/'.$failed.'/retry', ['reason' => 'Servizio di nuovo raggiungibile']);
    r7As($this, $this->moderator)->postJson('/api/v1/editorial/carpool/deliveries/'.$trying.'/retry', ['reason' => 'Servizio di nuovo raggiungibile'])->assertStatus(409);
    r7As($this, $this->moderator)->postJson('/api/v1/editorial/carpool/deliveries/'.$failed.'/retry', ['reason' => 'Servizio di nuovo raggiungibile'])->assertOk()->assertJsonPath('data.retried', true);
    expect(DB::table('community_delivery_outbox')->where('id', $failed)->value('attempts'))->toBe(0)
        ->and(CarpoolAudit::query()->where('action', 'delivery_retry')->value('metadata'))->toBe(['delivery_id' => $failed, 'reason' => 'Servizio di nuovo raggiungibile']);
});

it('dà gli stati dei passaggi in options', function (): void {
    r7As($this, $this->moderator)->getJson('/api/v1/editorial/options')->assertOk()
        ->assertJsonPath('data.ride_statuses.0', ['value' => 'draft', 'label' => RideStatus::Draft->label()]);
});

// ------------------------------------------------------------ il pannello resta com'era

it('il pannello mostra le stesse sezioni con le query spostate in CommunityDesk e CarpoolDesk', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $author = r7Person('Marta Bellini');
    r7Post($this, $author, 'Consiglio il concerto');
    $case = cpCase($this, cpAccepted($this));
    // `cpAction` passa la guardia predefinita a Sanctum: il pannello vuole quella del sito.
    auth()->shouldUse('web');
    $this->actingAs($this->admin, 'web');

    $this->get('/admin/community')->assertOk()->assertSee('Marta Bellini');
    Livewire\Livewire::test(Community::class)->set('section', 'posts')->assertSee('Consiglio il concerto')
        ->set('search', 'nulla')->assertDontSee('Consiglio il concerto');
    $this->get('/admin/carpool')->assertOk();
    Livewire\Livewire::test(Carpool::class)->assertSee('#'.$case->id)
        ->set('selectedCase', $case->id)->assertSee('Vorrei chiarire questo problema.')
        ->set('section', 'rides')->assertSee('Prato della Valle');
});
