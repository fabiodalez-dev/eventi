<?php

declare(strict_types=1);

use App\Actions\Account\RemoveSavedOccurrence;
use App\Actions\Account\SaveOccurrences;
use App\Enums\ProfileVisibility;
use App\Enums\UserRole;
use App\Models\CommunityPost;
use App\Models\SavedEvent;
use App\Models\User;
use App\Services\Community\Community;
use App\Services\Community\CommunityAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config(['community.enabled' => true]);
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-20 12:00');
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-09-25 21:00', '2026-09-25 23:00');
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin);
    app(Community::class)->profile($this->admin, ['handle' => 'admin_public', 'display_name' => 'Admin pubblico', 'visibility' => 'public']);
});

afterEach(fn () => Carbon\Carbon::setTestNow());

it('exposes the same exemption for permissions, discovery and attendance without a verification badge', function (): void {
    app(Community::class)->attendance($this->admin, $this->date, true);
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.whatsapp_verified', false)
        ->assertJsonPath('data.community_access.whatsapp_exempt', true)->assertJsonPath('data.community_access.can_attend', true);
    $this->getJson('/api/v1/community/people/admin_public')->assertOk()->assertJsonPath('data.profile.verified', false);
    $this->getJson('/api/v1/community/occurrences/'.$this->date->id.'/attendance')->assertOk()->assertJsonPath('data.going', true)->assertJsonCount(1, 'data.attendees');
    $this->actingAs($this->admin)->get(route('community.profile', 'admin_public'))->assertOk()->assertDontSee(__('community.verified'));
    expect(app(CommunityAccess::class)->profiles(null)->where('user_id', $this->admin->id)->exists())->toBeTrue();
});

it('does not infer attendance from a recommendation and keeps each action independent', function (): void {
    app(SaveOccurrences::class)->one($this->admin, $this->date);
    $post = app(Community::class)->publication($this->admin, $this->date->id, ['visibility' => 'public', 'intent' => 'recommend', 'body' => 'Un consiglio']);
    expect(app(CommunityAccess::class)->attendees($this->date, null)->count())->toBe(0);
    app(Community::class)->attendance($this->admin, $this->date, true);
    app(Community::class)->attendance($this->admin, $this->date, false);
    expect($post->fresh())->not->toBeNull();
    app(Community::class)->attendance($this->admin, $this->date, true);
    app(RemoveSavedOccurrence::class)($this->admin, $this->date->id);
    expect($post->fresh()->saved_event_id)->toBeNull();
    expect(app(CommunityAccess::class)->canViewPost(null, $post->fresh()))->toBeTrue();
    app(Community::class)->publication($this->admin, $this->date->id, ['visibility' => 'private']);
    expect(CommunityPost::find($post->id))->toBeNull();
    $this->assertDatabaseHas('community_attendances', ['user_id' => $this->admin->id, 'occurrence_id' => $this->date->id]);
});

it('allows editing and withdrawing a recommendation after the bookmark is removed', function (): void {
    app(SaveOccurrences::class)->one($this->admin, $this->date);
    $post = app(Community::class)->publication($this->admin, $this->date->id, ['visibility' => 'public']);
    app(RemoveSavedOccurrence::class)($this->admin, $this->date->id);
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/community/saved/'.$this->date->id)->assertOk()->assertJsonPath('data.post_id', $post->id);
    $this->putJson('/api/v1/community/saved/'.$this->date->id, ['visibility' => 'public', 'body' => 'Aggiornato'])->assertOk();
    expect($post->fresh()->body)->toBe('Aggiornato');
});

it('revokes the exemption as soon as the role is removed', function (): void {
    app(Community::class)->attendance($this->admin, $this->date, true);
    $this->admin->removeRole(UserRole::Admin);
    Sanctum::actingAs($this->admin->fresh());
    $this->getJson('/api/v1/me')->assertJsonPath('data.community_access.eligible', false)->assertJsonPath('data.community_access.required_step', 'whatsapp');
    $this->postJson('/api/v1/community/saved/'.$this->date->id.'/attendance', ['going' => true])->assertForbidden();
    $this->postJson('/api/v1/community/saved/'.$this->date->id.'/attendance', ['going' => false])->assertOk();
    expect(app(CommunityAccess::class)->profiles(null)->count())->toBe(0);
});

it('does not extend the exemption to editorial and venue roles', function (UserRole $role): void {
    $user = User::factory()->create();
    $user->assignRole($role);
    expect($user->canParticipateInCommunity())->toBeFalse();
})->with([UserRole::Moderator, UserRole::VenueOwner, UserRole::VenueEditor]);

it('requires an audience before accepting a public participation', function (): void {
    $this->admin->communityProfile->update(['visibility' => ProfileVisibility::Private]);
    Sanctum::actingAs($this->admin->fresh());
    $this->getJson('/api/v1/me')->assertJsonPath('data.community_access.can_attend', false);
    $this->postJson('/api/v1/community/saved/'.$this->date->id.'/attendance', ['going' => true])->assertUnprocessable();
    $this->assertDatabaseCount('community_attendances', 0);
});

it('rejects community mutations while impersonating', function (): void {
    $this->actingAs($this->admin)->withSession(['impersonator_id' => 999])->post(route('community.attendance', $this->date), ['going' => true])->assertForbidden();
    $this->assertDatabaseCount('community_attendances', 0);
});

it('reserves previous handles and resolves them with the current visibility', function (): void {
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/community/profile', ['handle' => 'new_admin', 'display_name' => 'Admin', 'visibility' => 'public'])->assertOk();
    $this->getJson('/api/v1/community/people/admin_public')->assertOk()->assertJsonPath('data.profile.handle', 'new_admin');
    $other = User::factory()->create();
    $other->assignRole(UserRole::SuperAdmin);
    Sanctum::actingAs($other);
    $this->postJson('/api/v1/community/profile', ['handle' => 'admin_public', 'display_name' => 'Other', 'visibility' => 'public'])->assertUnprocessable();
    $this->admin->communityProfile->update(['visibility' => ProfileVisibility::Private]);
    $this->getJson('/api/v1/community/people/admin_public')->assertNotFound();
});

it('never changes notification preferences when saving account identity only', function (): void {
    $this->admin->forceFill(['notification_preferences' => ['reminders' => true, 'comments' => true], 'marketing_opt_in_at' => now(), 'quiet_hours' => ['from' => '22:00', 'to' => '08:00']])->save();
    $before = $this->admin->only(['notification_preferences', 'marketing_opt_in_at', 'quiet_hours']);
    $this->actingAs($this->admin)->patch(route('account.profile.update'), ['profile_only' => 1, 'name' => 'Nuovo nome', 'timezone' => 'Europe/Rome', 'locale' => 'it'])->assertRedirect();
    expect($this->admin->fresh()->only(array_keys($before)))->toEqual($before);
});

/*
 * Il criterio del backfill, caso per caso.
 *
 * `silenzioso` è il caso che conta e che prima si perdeva: pubblico, nessun
 * post, nessuna riga di registro. È la firma del vecchio «Ci vado», che non
 * creava post e scriveva nel registro solo da questa modifica in avanti — in
 * produzione sono 103 salvataggi su 140, e fermarsi ai post ne avrebbe migrati
 * 21 su 124.
 */
it('migrates explicit legacy attendance without turning recommendations into participation', function (): void {
    $migration = require database_path('migrations/2026_09_25_190000_separate_community_attendance.php');
    foreach (['recommend', 'attend', 'explicit', 'silenzioso', 'private'] as $kind) {
        $date = occurrenceAtLocal($this->city, $this->category, '2026-09-26 21:00', '2026-09-26 23:00');
        $saved = app(SaveOccurrences::class)->one($this->admin, $date);
        $saved->forceFill(['visibility' => $kind === 'private' ? 'private' : 'public'])->save();
        if (in_array($kind, ['recommend', 'attend'])) {
            CommunityPost::create(['user_id' => $this->admin->id, 'saved_event_id' => $saved->id, 'occurrence_id' => $date->id, 'intent' => $kind, 'status' => 'published', 'published_at' => now()]);
        } elseif ($kind === 'explicit') {
            activity('community')->causedBy($this->admin)->performedOn($date)->withProperties(['saved_event_id' => $saved->id])->event('attendance_public')->log('attendance_public');
        }
        $dates[$kind] = $date->id;
    }
    $migration->backfill();
    $migration->backfill();
    expect(DB::table('community_attendances')->orderBy('occurrence_id')->pluck('occurrence_id')->all())
        ->toBe([$dates['attend'], $dates['explicit'], $dates['silenzioso']]);
    expect(CommunityPost::count())->toBe(2);
});

it('refuses a schema rollback that would lose community activity', function (): void {
    app(Community::class)->attendance($this->admin, $this->date, true);
    $migration = require database_path('migrations/2026_09_25_190000_separate_community_attendance.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('community_attendances', 1);
});

/*
 * La riparazione delle partecipazioni non riconosciute.
 *
 * La separazione è stata rilasciata con un criterio che si fermava ai post: in
 * produzione ha migrato 21 gesti su 124, perché il vecchio «Ci vado» non creava
 * post. Questa migrazione recupera i mancanti dai salvataggi, che erano intatti.
 */
it('recupera le partecipazioni non riconosciute senza toccare i consigli', function (): void {
    $silenzioso = occurrenceAtLocal($this->city, $this->category, '2026-09-27 21:00', '2026-09-27 23:00');
    $consigliato = occurrenceAtLocal($this->city, $this->category, '2026-09-28 21:00', '2026-09-28 23:00');

    foreach ([$silenzioso, $consigliato] as $date) {
        app(SaveOccurrences::class)->one($this->admin, $date)->forceFill(['visibility' => 'public'])->save();
    }
    $salvato = SavedEvent::query()->where('occurrence_id', $consigliato->id)->sole();
    CommunityPost::create(['user_id' => $this->admin->id, 'saved_event_id' => $salvato->id, 'occurrence_id' => $consigliato->id,
        'intent' => 'recommend', 'status' => 'published', 'published_at' => now()]);

    // Lo stato in cui il rilascio ha lasciato la produzione: nessuna delle due.
    DB::table('community_attendances')->delete();

    $riparazione = require database_path('migrations/2026_09_25_200000_repair_legacy_attendance.php');
    $riparazione->up();
    // Ripetibile: una seconda esecuzione non duplica niente.
    $riparazione->up();

    expect(DB::table('community_attendances')->pluck('occurrence_id')->all())->toBe([$silenzioso->id]);
});
