<?php

declare(strict_types=1);

use App\Actions\InviteVenueMemberAction;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\CheckinStaffInvitation;
use App\Models\EventOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\VenueIsolationScenario;

/*
 * P8 — «I tuoi inviti» nell'app (W-CO-07, W-TK-15). Sul sito un invito si
 * accetta dal collegamento firmato con l'account invitato; nell'app, senza
 * collegamento, chiede il token di quell'account e l'indirizzo confermato.
 * Qui si prova che un invito si accetta e si rifiuta solo così, e che
 * scaduto, reinviato o di un altro account non vale.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->venue = $this->s->venueA;
});

function inviteToVenue(object $test, User $invited): int
{
    app(InviteVenueMemberAction::class)->execute($test->venue, $invited->email, 'Invitata', VenueRole::Editor);

    $invitedAt = DB::table('venue_user')->where('venue_id', $test->venue->id)->where('user_id', $invited->id)->value('invited_at');

    return CarbonImmutable::parse((string) $invitedAt)->getTimestamp();
}

/** Una data che non è ancora finita, con un invito al personale d'ingresso per `$email`. */
function doorInvitation(object $test, string $email): CheckinStaffInvitation
{
    $date = EventOccurrence::query()->create([
        'event_id' => $test->s->publishedEventA->id,
        'venue_id' => $test->venue->id,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(3),
    ]);

    return $date->staffInvitations()->create(['email' => $email, 'invited_by_id' => $test->s->ownerA->id, 'invited_at' => CarbonImmutable::now()->startOfSecond()]);
}

it('elenca gli inviti in attesa e li conta in /me', function (): void {
    $invited = User::factory()->create(['email' => 'giulia@esempio.test']);
    $sent = inviteToVenue($this, $invited);
    $door = doorInvitation($this, 'giulia@esempio.test');
    doorInvitation($this, 'altra@esempio.test');
    Sanctum::actingAs($invited);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management.invitations', 2);
    $response = $this->getJson('/api/v1/me/invitations')->assertOk();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response
        ->assertJsonCount(1, 'data.venues')
        ->assertJsonPath('data.venues.0.venue.id', $this->venue->id)
        ->assertJsonPath('data.venues.0.venue.name', $this->venue->name)
        ->assertJsonPath('data.venues.0.role', 'editor')
        ->assertJsonPath('data.venues.0.sent', $sent)
        ->assertJsonCount(1, 'data.checkin')
        ->assertJsonPath('data.checkin.0.id', $door->id)
        ->assertJsonPath('data.checkin.0.occurrence.id', $door->occurrence_id)
        ->assertJsonPath('data.checkin.0.occurrence.title', $this->s->publishedEventA->title)
        ->assertJsonPath('data.checkin.0.sent', $door->invited_at->getTimestamp());
});

it('fa accettare l’invito al locale dall’app, che apre il locale nella gestione', function (): void {
    $invited = User::factory()->create();
    $sent = inviteToVenue($this, $invited);
    Sanctum::actingAs($invited);

    $this->postJson('/api/v1/me/invitations/venues/'.$this->venue->id, ['decision' => 'accept', 'sent' => $sent])->assertOk()
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.subject.type', 'venue')
        ->assertJsonPath('data.subject.id', $this->venue->id);

    $fresh = $invited->fresh();
    $log = Activity::query()->where('log_name', 'sicurezza')->where('description', 'invito_locale_accettato')->latest('id')->first();
    expect($fresh->canAccessTenant($this->venue))->toBeTrue()
        ->and($fresh->hasRole(UserRole::VenueEditor->value))->toBeTrue()
        ->and($log?->properties['tramite'] ?? null)->toBe('app');
    $this->getJson('/api/v1/management/venues/'.$this->venue->id.'/access')->assertOk()->assertJsonPath('data.role', 'editor');
    $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonCount(0, 'data.venues');

    // Una seconda risposta non trova più niente.
    $this->postJson('/api/v1/me/invitations/venues/'.$this->venue->id, ['decision' => 'accept', 'sent' => $sent])->assertNotFound();
});

it('fa rifiutare l’invito al locale', function (): void {
    $invited = User::factory()->create();
    $sent = inviteToVenue($this, $invited);
    Sanctum::actingAs($invited);

    $this->postJson('/api/v1/me/invitations/venues/'.$this->venue->id, ['decision' => 'decline', 'sent' => $sent])->assertOk()
        ->assertJsonPath('data.accepted', false)
        ->assertJsonPath('data.subject', null);

    expect(DB::table('venue_user')->where('venue_id', $this->venue->id)->where('user_id', $invited->id)->exists())->toBeFalse()
        ->and($invited->fresh()->canAccessTenant($this->venue))->toBeFalse();
});

it('non vale per un altro account, scaduto, reinviato o senza indirizzo confermato', function (): void {
    $invited = User::factory()->create();
    $sent = inviteToVenue($this, $invited);
    $url = '/api/v1/me/invitations/venues/'.$this->venue->id;

    // Un altro account: come se l'invito non esistesse.
    Sanctum::actingAs($this->s->plainUser);
    $this->postJson($url, ['decision' => 'accept', 'sent' => $sent])->assertNotFound();
    $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonCount(0, 'data.venues');

    // L'indirizzo non confermato: niente inviti, come sul sito senza il collegamento della casella.
    $invited->forceFill(['email_verified_at' => null])->save();
    Sanctum::actingAs($invited->fresh());
    $this->getJson('/api/v1/me/invitations')->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->postJson($url, ['decision' => 'accept', 'sent' => $sent])->assertForbidden();
    $invited->forceFill(['email_verified_at' => now()])->save();
    Sanctum::actingAs($invited->fresh());

    // Reinviato: la risposta all'invito di prima non vale più.
    $this->travel(5)->minutes();
    app(InviteVenueMemberAction::class)->resend($this->venue, $invited, $this->s->ownerA);
    $this->postJson($url, ['decision' => 'accept', 'sent' => $sent])->assertNotFound();
    $this->postJson($url, ['decision' => 'forse', 'sent' => $sent])->assertUnprocessable();

    // Scaduto: sparisce dall'elenco e non si accetta più.
    $fresh = $this->getJson('/api/v1/me/invitations')->assertOk()->json('data.venues.0.sent');
    $this->travel(InviteVenueMemberAction::EXPIRES_DAYS + 1)->days();
    $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonCount(0, 'data.venues');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management.invitations', 0);
    $this->postJson($url, ['decision' => 'accept', 'sent' => $fresh])->assertNotFound();

    expect($invited->fresh()->canAccessTenant($this->venue))->toBeFalse();
});

it('fa accettare l’invito al personale d’ingresso, solo all’account di quell’indirizzo', function (): void {
    $doorman = User::factory()->create(['email' => 'porta@esempio.test']);
    $door = doorInvitation($this, 'porta@esempio.test');
    $url = '/api/v1/me/invitations/checkin/'.$door->id;
    $body = ['decision' => 'accept', 'sent' => $door->invited_at->getTimestamp()];

    Sanctum::actingAs($this->s->plainUser);
    $this->postJson($url, $body)->assertNotFound();

    Sanctum::actingAs($doorman);
    $this->postJson($url, ['decision' => 'accept', 'sent' => $body['sent'] - 60])->assertNotFound();
    $this->postJson($url, $body)->assertOk()
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.occurrence_id', $door->occurrence_id);

    $date = EventOccurrence::query()->findOrFail($door->occurrence_id);
    expect($date->checkinStaff()->whereKey($doorman->id)->exists())->toBeTrue()
        ->and(CheckinStaffInvitation::query()->whereKey($door->id)->exists())->toBeFalse();
    $this->getJson('/api/v1/management/dates/'.$date->id)->assertOk()->assertJsonPath('data.can_check_in', true);
    $this->postJson($url, $body)->assertNotFound();
});

it('fa rifiutare l’invito al personale d’ingresso e non accetta quello di una data finita', function (): void {
    $doorman = User::factory()->create(['email' => 'porta@esempio.test']);
    $door = doorInvitation($this, 'porta@esempio.test');
    $late = doorInvitation($this, 'porta@esempio.test');
    Sanctum::actingAs($doorman);

    $this->postJson('/api/v1/me/invitations/checkin/'.$door->id, ['decision' => 'decline', 'sent' => $door->invited_at->getTimestamp()])->assertOk()
        ->assertJsonPath('data.accepted', false);
    expect(CheckinStaffInvitation::query()->whereKey($door->id)->exists())->toBeFalse()
        ->and($door->occurrence->checkinStaff()->count())->toBe(0);

    $this->travel(4)->days();
    $this->getJson('/api/v1/me/invitations')->assertOk()->assertJsonCount(0, 'data.checkin');
    $this->postJson('/api/v1/me/invitations/checkin/'.$late->id, ['decision' => 'accept', 'sent' => $late->invited_at->getTimestamp()])->assertNotFound();
});
