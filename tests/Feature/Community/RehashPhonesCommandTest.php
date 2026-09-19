<?php

declare(strict_types=1);

use App\Enums\WhatsappChallengeStatus;
use App\Models\User;
use App\Models\WhatsappChallenge;
use App\Services\Community\WhatsappVerification;
use App\Support\PhoneFingerprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * La rotazione di WHATSAPP_PHONE_HASH_KEY (issue #103). La chiave nuova è in
 * configurazione, la vecchia solo nell'ambiente del processo.
 */

function rehashUser(string $phone, string $key, bool $trashed = false): User
{
    $user = User::factory()->create();
    $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_phone_hash' => PhoneFingerprint::of($phone, $key), 'whatsapp_verified_at' => now()])->save();
    if ($trashed) {
        $user->delete();
    }

    return $user;
}

function rehashChallenge(User $user, string $phone, string $key): WhatsappChallenge
{
    return WhatsappChallenge::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'phone' => $phone,
        'phone_hash' => PhoneFingerprint::of($phone, $key), 'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5), 'status' => WhatsappChallengeStatus::Sent,
    ]);
}

/** Un sospeso che ha cancellato l'account: resta l'impronta, il numero no. */
function rehashOrphan(string $phone, string $key): User
{
    $user = rehashUser($phone, $key);
    $user->forceFill(['community_suspended_at' => now()->subMonth()])->save();
    DB::table('users')->where('id', $user->id)->update(['whatsapp_phone' => null, 'deleted_at' => now()]);

    return $user;
}

/** @return array<int|string, string|null> */
function storedFingerprints(): array
{
    return DB::table('users')->whereNotNull('whatsapp_phone_hash')->pluck('whatsapp_phone_hash', 'id')->all()
        + DB::table('whatsapp_challenges')->pluck('phone_hash', 'id')->all();
}

beforeEach(function (): void {
    config(['community.enabled' => true, 'community.whatsapp_enabled' => true, 'community.kapso_key' => 'test-key-not-real', 'community.phone_number_id' => '123', 'community.phone_hash_key' => 'new']);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.test']]])]);
    putenv('WHATSAPP_PHONE_HASH_PREVIOUS_KEY=old');
});

afterEach(function (): void {
    putenv('WHATSAPP_PHONE_HASH_PREVIOUS_KEY');
    unset($_SERVER['WHATSAPP_PHONE_HASH_PREVIOUS_KEY']);
});

it('rehashes users and challenges to the new key, keeps numbers taken and is idempotent', function (): void {
    $active = rehashUser('+393331111111', 'old');
    $trashed = rehashUser('+393332222222', 'old', trashed: true);
    $challenge = rehashChallenge($active, '+393331111111', 'old');
    $current = rehashUser('+393333333333', 'new');

    expect(Artisan::call('community:rehash-phones'))->toBe(0);
    $output = Artisan::output();
    // Mai numeri, impronte o chiavi sul terminale.
    foreach (['+393331111111', '3331111111', PhoneFingerprint::of('+393331111111', 'old'), PhoneFingerprint::of('+393331111111', 'new')] as $secret) {
        expect($output)->not->toContain($secret);
    }
    expect($output)->toContain('Impronte ricalcolate: 2 utenti e 1 richieste');

    expect(DB::table('users')->where('id', $active->id)->value('whatsapp_phone_hash'))->toBe(PhoneFingerprint::of('+393331111111', 'new'))
        ->and(DB::table('users')->where('id', $trashed->id)->value('whatsapp_phone_hash'))->toBe(PhoneFingerprint::of('+393332222222', 'new'))
        ->and(DB::table('users')->where('id', $current->id)->value('whatsapp_phone_hash'))->toBe(PhoneFingerprint::of('+393333333333', 'new'))
        ->and(DB::table('whatsapp_challenges')->where('id', $challenge->id)->value('phone_hash'))->toBe(PhoneFingerprint::of('+393331111111', 'new'))
        ->and(app(WhatsappVerification::class)->fingerprint('+393331111111'))->toBe(PhoneFingerprint::of('+393331111111', 'new'));

    // I numeri già usati restano presi, anche quello dell'account cancellato (fuori dall'attesa di un minuto).
    $this->travel(2)->minutes();
    foreach (['+393331111111', '+393332222222'] as $i => $phone) {
        expect(fn () => app(WhatsappVerification::class)->request(User::factory()->create(), $phone, '198.51.100.'.($i + 1)))
            ->toThrow(ValidationException::class, __('community.whatsapp.phone_unavailable'));
    }

    $before = storedFingerprints();
    $this->artisan('community:rehash-phones')
        ->expectsOutputToContain('Tutte le impronte sono già con la chiave nuova: nulla da scrivere')
        ->assertExitCode(0);
    expect(storedFingerprints())->toBe($before);
});

it('dry run classifies without writing', function (): void {
    rehashUser('+393331111111', 'old');
    rehashChallenge(rehashUser('+393332222222', 'new'), '+393332222222', 'old');
    $before = storedFingerprints();

    $this->artisan('community:rehash-phones --dry-run')
        ->expectsOutputToContain('da ricalcolare 1 utenti e 1 richieste. Nulla è stato scritto')
        ->assertExitCode(0);
    expect(storedFingerprints())->toBe($before);
});

it('aborts without writing anything when a row matches neither key', function (): void {
    rehashUser('+393331111111', 'old');
    $stranger = rehashUser('+393332222222', 'third');
    rehashChallenge($stranger, '+393332222222', 'old');
    $before = storedFingerprints();

    $this->artisan('community:rehash-phones')
        ->expectsOutputToContain('non corrispondono')
        ->expectsOutputToContain('Utenti: '.$stranger->id)
        ->expectsOutputToContain('Nulla è stato scritto')
        ->assertExitCode(1);
    expect(storedFingerprints())->toBe($before);
});

it('aborts when two accounts would share the new fingerprint', function (): void {
    // Lo stesso numero con due chiavi: oggi convivono, dopo la rotazione violerebbero l'indice unico.
    $first = rehashUser('+393331111111', 'old');
    $second = rehashUser('+393331111111', 'new');
    $before = storedFingerprints();

    $this->artisan('community:rehash-phones')
        ->expectsOutputToContain('stessa impronta')
        ->expectsOutputToContain('Utenti: '.$first->id.', '.$second->id)
        ->assertExitCode(1);
    expect(storedFingerprints())->toBe($before);
});

it('refuses orphans unless asked to release them, keeping the suspension date', function (): void {
    $orphan = rehashOrphan('+393339999999', 'old');
    $user = rehashUser('+393331111111', 'old');
    $before = storedFingerprints();

    $this->artisan('community:rehash-phones')
        ->expectsOutputToContain('1 impronte orfane')
        ->expectsOutputToContain('Nulla è stato scritto')
        ->assertExitCode(1);
    expect(storedFingerprints())->toBe($before);

    $this->artisan('community:rehash-phones --release-orphans')
        ->expectsOutputToContain('Impronte orfane eliminate: 1')
        ->assertExitCode(0);
    $row = DB::table('users')->where('id', $orphan->id)->first();
    expect($row->whatsapp_phone_hash)->toBeNull()
        ->and($row->community_suspended_at)->not->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('whatsapp_phone_hash'))->toBe(PhoneFingerprint::of('+393331111111', 'new'));
});

it('treats a missing number on an account that is not suspended and deleted as a mismatch', function (): void {
    // Un account attivo con l'impronta ma senza numero non è un'orfana: --release-orphans non deve toccarlo.
    $broken = rehashUser('+393331111111', 'old');
    DB::table('users')->where('id', $broken->id)->update(['whatsapp_phone' => null]);
    rehashUser('+393332222222', 'old');
    $before = storedFingerprints();

    foreach (['community:rehash-phones', 'community:rehash-phones --release-orphans'] as $command) {
        $this->artisan($command)
            ->expectsOutputToContain('non corrispondono')
            ->expectsOutputToContain('Utenti: '.$broken->id)
            ->expectsOutputToContain('Nulla è stato scritto')
            ->assertExitCode(1);
        expect(storedFingerprints())->toBe($before);
    }
});

it('lets --release-orphans resolve a collision with an orphan fingerprint', function (): void {
    // L'orfana è già con la chiave nuova; un account attivo con lo stesso numero è ancora con la vecchia.
    $orphan = rehashOrphan('+393331111111', 'new');
    $user = rehashUser('+393331111111', 'old');
    $before = storedFingerprints();

    $this->artisan('community:rehash-phones')
        ->expectsOutputToContain('stessa impronta')
        ->assertExitCode(1);
    expect(storedFingerprints())->toBe($before);

    $this->artisan('community:rehash-phones --release-orphans')
        ->expectsOutputToContain('Impronte orfane eliminate: 1')
        ->assertExitCode(0);
    expect(DB::table('users')->where('id', $orphan->id)->value('whatsapp_phone_hash'))->toBeNull()
        ->and(DB::table('users')->where('id', $user->id)->value('whatsapp_phone_hash'))->toBe(PhoneFingerprint::of('+393331111111', 'new'));
});

it('refuses to run without two distinct keys', function (?string $previous, string $current): void {
    putenv($previous === null ? 'WHATSAPP_PHONE_HASH_PREVIOUS_KEY' : 'WHATSAPP_PHONE_HASH_PREVIOUS_KEY='.$previous);
    config(['community.phone_hash_key' => $current]);
    rehashUser('+393331111111', 'old');
    $before = storedFingerprints();

    $this->artisan('community:rehash-phones')->assertExitCode(1);
    expect(storedFingerprints())->toBe($before);
})->with([
    'vecchia assente' => [null, 'new'],
    'vecchia vuota' => ['', 'new'],
    'nuova vuota' => ['old', ''],
    'uguali' => ['same', 'same'],
]);
