<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\City;
use App\Services\Editorial\EditorialPermissions;
use App\Support\Editorial\EditorialBadges;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * Il banco della redazione (piano android-redazione, §3.1–3.3): chi entra,
 * con quali permessi, su quale città, l'interruttore d'emergenza e i campi
 * nuovi di `/me` e `/config`. La griglia: moderatore, admin, super admin,
 * referente di un locale, utente comune, ospite.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
});

it('apre il banco solo alla redazione: 404 a referente e utente comune, 401 all’ospite', function (): void {
    $this->getJson('/api/v1/editorial/desk')->assertUnauthorized();

    foreach ([$this->s->ownerA, $this->s->editorA, $this->s->plainUser] as $outsider) {
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/editorial/desk')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/editorial/options')->assertNotFound();
        $this->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertNotFound();
    }

    foreach ([$this->s->moderator, $this->s->admin, $this->superAdmin] as $staff) {
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/editorial/desk')->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }
});

it('risponde con ruolo, città, permessi, contatori e stato dello sblocco', function (): void {
    Sanctum::actingAs($this->s->moderator);

    $data = $this->getJson('/api/v1/editorial/desk')->assertOk()
        ->assertJsonPath('data.user.id', $this->s->moderator->id)
        ->assertJsonPath('data.role', 'moderator')
        ->assertJsonPath('data.city.slug', $this->s->city->slug)
        ->assertJsonPath('data.city.timezone', 'Europe/Rome')
        ->assertJsonPath('data.unlock.unlocked', false)
        ->assertJsonPath('data.unlock.unlock_hours', 12)
        ->assertJsonPath('data.unlock.password_minutes', 15)
        ->assertJsonPath('data.unlock.lock_after_minutes', 15)
        ->assertJsonStructure(['data' => ['user' => ['id', 'name'], 'role', 'city' => ['id', 'slug', 'name', 'timezone', 'is_active'], 'cities' => [['id', 'slug', 'name', 'timezone', 'is_active']], 'permissions', 'badges', 'unlock' => ['unlocked', 'unlocked_until', 'password_confirmed_until']]])
        ->json('data');

    expect(array_keys($data['permissions']))->toBe(EditorialPermissions::KEYS)
        ->and(array_keys($data['badges']))->toBe(EditorialBadges::KEYS)
        ->and(collect($data['permissions'])->every(fn (mixed $value): bool => is_bool($value)))->toBeTrue();
});

it('dà a ogni ruolo i permessi del pannello', function (): void {
    $permissions = function ($user): array {
        app('auth')->forgetGuards();
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/editorial/desk')->assertOk()->json('data.permissions');
    };

    $moderator = $permissions($this->s->moderator);
    $admin = $permissions($this->s->admin);
    $super = $permissions($this->superAdmin);

    // Il moderatore modera e pubblica (come il pannello), non modifica, non mette in evidenza, niente commerciale.
    expect($moderator)->toMatchArray([
        'events_view' => true, 'events_publish' => true, 'events_moderate' => true,
        'events_edit' => false, 'events_feature' => false, 'submissions_publish' => false,
        'venues_view' => true, 'venues_moderate' => true, 'venues_edit' => false, 'venues_members' => false,
        'applications_review' => true, 'users_view' => true, 'users_manage' => false, 'users_roles' => false,
        'reports_resolve' => true, 'reviews_moderate' => false, 'comments_moderate' => true, 'comments_delete' => false,
        'community_manage' => true, 'cases_manage' => true, 'carpool_manage' => true, 'messages_read' => false,
        'sponsorships_manage' => false, 'social_use' => false, 'analytics_view' => false, 'search_misses_manage' => true,
        'catalog_manage' => false, 'pages_manage' => false, 'imports_manage' => false,
        'notifications_view' => true, 'notifications_cancel' => false,
        'system_view' => false, 'system_manage' => false, 'mail_manage' => false,
    ]);

    // L'admin ha tutto tranne la posta, che è del solo super admin.
    expect(collect($admin)->except('mail_manage')->every(fn (bool $value): bool => $value))->toBeTrue()
        ->and($admin['mail_manage'])->toBeFalse()
        ->and(collect($super)->every(fn (bool $value): bool => $value))->toBeTrue();
});

it('nasconde al moderatore i contatori delle voci che non vede', function (): void {
    Sanctum::actingAs($this->s->moderator);
    $badges = $this->getJson('/api/v1/editorial/desk')->assertOk()->json('data.badges');

    expect($badges['catalog_reviews_pending'])->toBeNull()
        ->and($badges['sponsorship_orders_awaiting'])->toBeNull()
        ->and($badges['events_pending'])->toBeInt()
        ->and($badges['carpool_open'])->toBeInt();

    app('auth')->forgetGuards();
    Sanctum::actingAs($this->s->admin);
    $badges = $this->getJson('/api/v1/editorial/desk')->assertOk()->json('data.badges');
    expect($badges['catalog_reviews_pending'])->toBeInt()->and($badges['sponsorship_orders_awaiting'])->toBeInt();
});

it('lavora anche su una città non attiva, e risponde 404 a una città che non esiste', function (): void {
    $draft = City::factory()->create(['slug' => 'vicenza', 'name' => 'Vicenza', 'is_active' => false, 'timezone' => 'Europe/Rome']);
    Sanctum::actingAs($this->s->admin);

    $this->getJson('/api/v1/editorial/desk?city=vicenza')->assertOk()
        ->assertJsonPath('data.city.id', $draft->id)
        ->assertJsonPath('data.city.is_active', false);

    $slugs = collect($this->getJson('/api/v1/editorial/desk')->assertOk()->json('data.cities'))->pluck('slug')->all();
    expect($slugs)->toContain('vicenza', $this->s->city->slug);

    $this->getJson('/api/v1/editorial/desk?city=atlantide')->assertNotFound();
});

it('pubblica gli elenchi dei moduli e i ruoli conferibili per chi chiede', function (): void {
    Sanctum::actingAs($this->s->admin);
    $data = $this->getJson('/api/v1/editorial/options')->assertOk()->json('data');

    expect($data)->toHaveKeys(['event_statuses', 'event_sources', 'venue_statuses', 'venue_types', 'report_reasons', 'review_statuses', 'sponsorship_placements', 'sponsorship_pacings', 'roles', 'limits'])
        ->and($data['limits'])->toMatchArray(['per_page_max' => 50, 'reason_min' => 5, 'reason_max' => 1000, 'unlock_hours' => 12, 'password_minutes' => 15])
        ->and(collect($data['event_statuses'])->pluck('value')->all())->toContain('pending', 'published');

    $roles = collect($data['roles'])->keyBy('value');
    expect($roles['super_admin']['assignable'])->toBeFalse()
        ->and($roles['admin']['assignable'])->toBeTrue()
        ->and($roles['admin']['assignable_in_app'])->toBeFalse()
        ->and($roles['moderator']['assignable_in_app'])->toBeTrue();

    app('auth')->forgetGuards();
    Sanctum::actingAs($this->superAdmin);
    $roles = collect($this->getJson('/api/v1/editorial/options')->assertOk()->json('data.roles'))->keyBy('value');
    expect($roles['super_admin']['assignable'])->toBeTrue()->and($roles['super_admin']['assignable_in_app'])->toBeFalse();
});

it('spegne tutta la redazione con l’interruttore d’emergenza, anche in /config', function (): void {
    Sanctum::actingAs($this->s->admin);
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.features.editorial', true);

    config(['api.features.editorial' => false]);
    cache()->flush();

    $this->getJson('/api/v1/editorial/desk')->assertNotFound();
    $this->postJson('/api/v1/editorial/unlock', ['password' => 'password'])->assertNotFound();
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.features.editorial', false);
});

it('dice in /me chi è della redazione', function (): void {
    foreach ([[$this->s->moderator, true], [$this->s->admin, true], [$this->superAdmin, true], [$this->s->ownerA, false], [$this->s->plainUser, false]] as [$user, $editorial]) {
        app('auth')->forgetGuards();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management.editorial', $editorial);
    }
});
