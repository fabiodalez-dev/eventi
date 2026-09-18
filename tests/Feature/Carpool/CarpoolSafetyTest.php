<?php

use App\Actions\Account\DeleteAccount;
use App\Enums\Permission;
use App\Models\CarpoolAudit;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\UserBlock;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\CarpoolAccount;
use App\Services\Carpool\CarpoolRetention;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideChat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('hides all ride details from unrelated users', function (string $resource): void {
    $ride = cpAccepted($this);
    $chat = RideConversation::firstOrFail();
    $case = cpCase($this, $ride);
    Sanctum::actingAs(carpoolPerson(false));
    $path = match ($resource) {
        'requests' => 'requests/'.$ride->id, 'chats' => 'chats/'.$chat->id, 'cases' => 'cases/'.$case->id
    };
    $this->getJson('/api/v1/carpool/'.$path)->assertForbidden();
})->with(['requests', 'chats', 'cases']);

it('denies platform management even when a venue role is given a permission', function (string $role): void {
    $staff = cpStaff($role);
    $staff->givePermissionTo(Permission::ManageCommunityCases->value);
    expect(app(CommunitySafety::class)->staff($staff, Permission::ManageCommunityCases))->toBeFalse();
    $this->actingAs($staff)->get('/admin/carpool')->assertForbidden();
})->with(['venue_owner', 'venue_editor', 'user']);

it('allows a moderator to manage cases but not inspect private evidence', function (): void {
    $staff = cpStaff('moderator');
    $case = cpCase($this, cpAccepted($this));
    $safety = app(CommunitySafety::class);
    $safety->manage($staff, $case, 'assign', 'Esamino la segnalazione', ['revision' => $case->revision]);
    expect($case->fresh()->assignee_id)->toBe($staff->id);
    expect(fn () => $safety->inspect($staff, $case, 'Controllo conversazione'))->toThrow(HttpException::class);
});

it('audits purpose-bound evidence inspection without exposing it in ordinary views', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    $staff = cpStaff();
    $evidence = app(CommunitySafety::class)->inspect($staff, $case, 'Verifica della segnalazione');
    expect($evidence)->toHaveKeys(['sha256', 'case_id', 'operator_id', 'audit']);
    expect(CarpoolAudit::where('action', 'evidence_view')->where('actor_id', $staff->id)->count())->toBe(1);
    $this->actingAs($staff)->post(route('carpool.evidence', $case), ['reason' => 'Verifica del caso', 'password' => 'wrong-password'])->assertSessionHasErrors('password');
});

it('rejects case mutations without a useful reason and stale revisions', function (): void {
    $case = cpCase($this, cpAccepted($this));
    $admin = cpStaff();
    $safety = app(CommunitySafety::class);
    expect(fn () => $safety->manage($admin, $case, 'resolve', 'x', ['revision' => 1]))->toThrow(HttpException::class);
    $safety->manage($admin, $case, 'assign', 'Esame del caso', ['revision' => 1]);
    expect(fn () => $safety->manage($admin, $case, 'resolve', 'Conclusione del caso', ['revision' => 1]))->toThrow(HttpException::class);
    expect($case->fresh()->status->value)->toBe('assigned');
});

it('keeps internal notes and the other persons replies out of each user inbox', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    $admin = cpStaff();
    $safety = app(CommunitySafety::class);
    $safety->manage($admin, $case, 'note', 'Nota riservata agli operatori', ['revision' => 1]);
    $safety->manage($admin, $case, 'reply', 'Domanda privata al conducente', ['revision' => 2, 'recipient_id' => $this->driver->id]);
    Sanctum::actingAs($this->driver);
    $this->getJson('/api/v1/carpool/cases/'.$case->id)->assertOk()->assertJsonPath('data.body', null)->assertJsonCount(1, 'data.messages');
    Sanctum::actingAs($this->passenger);
    $this->getJson('/api/v1/carpool/cases/'.$case->id)->assertOk()->assertJsonCount(0, 'data.messages');
    expect(DB::table('carpool_case_messages')->value('body'))->not->toContain('Nota riservata');
});

it('forbids replying to an unrelated recipient', function (): void {
    $case = cpCase($this, cpAccepted($this));
    expect(fn () => app(CommunitySafety::class)->manage(cpStaff(), $case, 'reply', 'Messaggio riservato', ['revision' => 1, 'recipient_id' => carpoolPerson()->id]))->toThrow(HttpException::class);
    expect($case->messages()->count())->toBe(0);
});

it('separates social suspension from carpool eligibility', function (): void {
    app(CommunitySafety::class)->restrict(cpStaff(), $this->driver, 'social', true, 'Abuso delle pubblicazioni');
    expect($this->driver->fresh()->isWhatsappVerified())->toBeFalse()->and(app(CarpoolAccess::class)->eligible($this->driver->fresh()))->toBeTrue();
    cpOffer($this);
});

it('cancels future agreements on carpool or global suspension', function (string $scope): void {
    $ride = cpAccepted($this);
    $admin = cpStaff();
    app(CommunitySafety::class)->restrict($admin, $this->driver, $scope, true, 'Sospensione per verifica caso');
    expect($ride->fresh()->status->value)->toBe('cancelled');
    app(CommunitySafety::class)->restrict($admin, $this->driver->fresh(), $scope, false, 'Ripristino dopo verifica');
    expect($ride->fresh()->status->value)->toBe('cancelled');
})->with(['carpool', 'global']);

it('closes future agreements when either participant blocks the other', function (bool $driverBlocks): void {
    $ride = cpAccepted($this);
    UserBlock::create(['user_id' => $driverBlocks ? $this->driver->id : $this->passenger->id, 'blocked_user_id' => $driverBlocks ? $this->passenger->id : $this->driver->id]);
    expect($ride->fresh()->status->value)->toBe('cancelled')->and(RideConversation::first()->read_only_at)->not->toBeNull();
})->with([true, false]);

it('does not allow impersonated carpool mutations or chat reads', function (): void {
    $ride = cpAccepted($this);
    $chat = RideConversation::first();
    $this->actingAs($this->passenger)->withSession(['impersonator_id' => cpStaff()->id]);
    $this->get(route('carpool.chat', $chat))->assertForbidden();
    $this->post(route('carpool.action', 'withdraw'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id])->assertForbidden();
});

it('encrypts canonical IP evidence and ignores untrusted forwarding headers', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.23'])->withHeader('X-Forwarded-For', '203.0.113.99');
    cpOffer($this);
    $audit = CarpoolAudit::where('action', 'ride_offered')->firstOrFail();
    expect($audit->context['ip'])->toBe('192.0.2.23')->and(DB::table('carpool_audits')->where('id', $audit->id)->value('context'))->not->toContain('192.0.2.23');
});

it('purges expired IPs without removing the operational audit', function (): void {
    cpOffer($this);
    $audit = CarpoolAudit::where('action', 'ride_offered')->firstOrFail();
    $audit->update(['context_expires_at' => now()->subSecond()]);
    app(CarpoolRetention::class)->purge();
    expect($audit->fresh()->context)->toBeNull()->and($audit->fresh()->action)->toBe('ride_offered');
});

it('preserves only the documented case scope and expires its hold', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    $admin = cpStaff();
    $audit = CarpoolAudit::where('subject_type', 'RideRequest')->where('subject_id', $ride->id)->firstOrFail();
    $audit->update(['context_expires_at' => now()->subSecond()]);
    app(CommunitySafety::class)->manage($admin, $case, 'hold', 'Richiesta documentata di preservazione', ['revision' => 1]);
    app(CarpoolRetention::class)->purge();
    expect($audit->fresh()->context)->not->toBeNull();
    $case->refresh()->update(['hold_until' => now()->subSecond()]);
    app(CarpoolRetention::class)->purge();
    expect($audit->fresh()->context)->toBeNull();
});

it('makes expired chat inaccessible even when the scheduled purge has not run', function (): void {
    cpAccepted($this);
    $chat = RideConversation::firstOrFail();
    $chat->update(['read_only_at' => now()->subDays(91)]);
    Sanctum::actingAs($this->passenger);
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertForbidden();
});

it('erases expired chat text while keeping recent ride history', function (): void {
    cpAccepted($this);
    $chat = RideConversation::firstOrFail();
    app(RideChat::class)->send($this->driver, $chat, 'Un messaggio privato', (string) Str::uuid());
    $chat->update(['read_only_at' => now()->subDays(91)]);
    app(CarpoolRetention::class)->purge();
    expect(DB::table('chat_messages')->count())->toBe(0)->and($chat->fresh()->purged_at)->not->toBeNull()->and(RideOffer::count())->toBe(1);
});

it('removes closed operational history after its retention period', function (): void {
    $ride = cpAccepted($this);
    $offer = $ride->offer;
    app(CommunitySafety::class)->cancelRide(cpStaff(), $offer, 'Annullamento richiesto dal conducente');
    $offer->refresh()->update(['closed_at' => now()->subDays(366)]);
    app(CarpoolRetention::class)->purge();
    expect(RideOffer::count())->toBe(0)->and(RideConversation::count())->toBe(0)->and(DB::table('chat_conversations')->count())->toBe(0);
});

it('freezes chat visibility without deleting evidence or reopening a cancelled conversation', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    $staff = cpStaff();
    $chat = RideConversation::first();
    app(CommunitySafety::class)->freezeChat($staff, $case, true, 'Contenuto in verifica');
    expect(app(CarpoolAccess::class)->canReadChat($this->driver, $chat->fresh()))->toBeFalse();
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    app(CommunitySafety::class)->freezeChat($staff, $case, false, 'Verifica conclusa');
    expect(app(RideChat::class)->writable($this->driver, $chat->fresh()))->toBeFalse();
});

it('exports only authored messages and no internal case notes or security IPs', function (): void {
    $ride = cpAccepted($this);
    $chat = RideConversation::first();
    $case = cpCase($this, $ride);
    app(RideChat::class)->send($this->driver, $chat, 'Testo conducente', (string) Str::uuid());
    app(RideChat::class)->send($this->passenger, $chat, 'Testo richiedente', (string) Str::uuid());
    app(CommunitySafety::class)->manage(cpStaff(), $case, 'note', 'Riservato amministrazione', ['revision' => 1]);
    $result = app(CarpoolAccount::class)->export($this->passenger);
    expect($result['messages_authored'])->toHaveCount(1)->and($result['messages_authored'][0]['body'])->toBe('Testo richiedente');
    expect(json_encode($result))->not->toContain('Riservato amministrazione')->not->toContain('context_expires_at');
});

it('cancels rides and queued deliveries when the account is deleted', function (): void {
    $ride = cpAccepted($this);
    app(DeleteAccount::class)($this->driver);
    expect($ride->fresh()->status->value)->toBe('cancelled');
    expect(DB::table('community_delivery_outbox')->where('user_id', $this->driver->id)->count())->toBe(0);
    expect($this->driver->fresh()->whatsapp_phone_hash)->toBeNull();
});
