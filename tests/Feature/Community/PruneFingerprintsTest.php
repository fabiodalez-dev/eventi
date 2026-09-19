<?php

declare(strict_types=1);

use App\Enums\KapsoOutcome;
use App\Models\User;
use App\Services\Community\WhatsappVerification;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * La scadenza delle impronte trattenute per gli account sospesi e cancellati
 * (issue #104): ventiquattro mesi dalla sospensione.
 */

/** Un account con l'impronta trattenuta, come lo lascia DeleteAccount (o attivo, se $deletedAt è null). */
function retainedFingerprint(string $phone, ?CarbonInterface $suspendedAt, ?CarbonInterface $deletedAt): User
{
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update([
        'whatsapp_phone' => null,
        'whatsapp_phone_hash' => app(WhatsappVerification::class)->fingerprint($phone),
        'community_suspended_at' => $suspendedAt,
        'deleted_at' => $deletedAt,
    ]);

    return $user;
}

beforeEach(function (): void {
    config(['community.enabled' => true, 'community.whatsapp_enabled' => true, 'community.kapso_key' => 'test-key-not-real', 'community.phone_number_id' => '123', 'community.phone_hash_key' => 'test-fingerprint-key']);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.test']]])]);
});

it('removes fingerprint and suspension date only from deleted accounts past the retention period', function (): void {
    $expired = retainedFingerprint('+393331111111', now()->subMonths(25), now()->subMonths(24));
    $recent = retainedFingerprint('+393332222222', now()->subMonths(23), now()->subMonths(22));
    $active = retainedFingerprint('+393333333333', now()->subMonths(30), null);
    // Difensivo: un'impronta senza sospensione su un account cancellato da oltre due anni.
    $stray = retainedFingerprint('+393334444444', null, now()->subMonths(25));

    $this->artisan('community:prune-fingerprints')
        ->expectsOutputToContain('Impronte eliminate: 2 (sospensione più vecchia di 24 mesi)')
        ->assertExitCode(0);

    foreach ([$expired, $stray] as $user) {
        $row = DB::table('users')->where('id', $user->id)->first();
        expect($row->whatsapp_phone_hash)->toBeNull()->and($row->community_suspended_at)->toBeNull()->and($row->deleted_at)->not->toBeNull();
    }
    foreach ([$recent, $active] as $user) {
        $row = DB::table('users')->where('id', $user->id)->first();
        expect($row->whatsapp_phone_hash)->not->toBeNull()->and($row->community_suspended_at)->not->toBeNull();
    }

    $log = Activity::query()->where('log_name', 'community')->where('event', 'fingerprints_pruned')->sole();
    expect($log->properties->all())->toBe(['count' => 2])->and($log->subject_id)->toBeNull();

    $this->artisan('community:prune-fingerprints')->expectsOutputToContain('Nessuna impronta')->assertExitCode(0);
    expect(Activity::query()->where('event', 'fingerprints_pruned')->count())->toBe(1);
});

it('dry run counts without changing anything', function (): void {
    $expired = retainedFingerprint('+393331111111', now()->subMonths(25), now()->subMonths(24));

    $this->artisan('community:prune-fingerprints --dry-run')
        ->expectsOutputToContain('Da eliminare: 1 impronte')
        ->assertExitCode(0);
    expect(DB::table('users')->where('id', $expired->id)->value('whatsapp_phone_hash'))->not->toBeNull()
        ->and(Activity::query()->where('event', 'fingerprints_pruned')->exists())->toBeFalse();
});

it('frees the number of an expired ban for a new account', function (): void {
    retainedFingerprint('+393331111111', now()->subMonths(25), now()->subMonths(24));
    $verification = app(WhatsappVerification::class);

    expect(fn () => $verification->request(User::factory()->create(), '+393331111111', '198.51.100.1'))
        ->toThrow(ValidationException::class, __('community.whatsapp.phone_unavailable'));

    $this->artisan('community:prune-fingerprints')->assertExitCode(0);

    expect($verification->request(User::factory()->create(), '+393331111111', '198.51.100.2')->outcome)->toBe(KapsoOutcome::Sent);
});

it('runs every day', function (): void {
    $task = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'community:prune-fingerprints'));

    expect($task)->not->toBeNull()->and($task->getExpression())->toBe('0 0 * * *');
});
