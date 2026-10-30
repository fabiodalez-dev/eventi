<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Community\PruneSuspendedFingerprints;
use App\Enums\KapsoOutcome;
use App\Enums\WhatsappChallengeStatus;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\User;
use App\Models\WhatsappChallenge;
use App\Services\Community\WhatsappVerification;
use App\Support\IpBucket;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Regressioni della revisione di sicurezza di ottobre sulla community:
 * nomi dei bloccati, accaparramento dei nomi utente, tetti per IP con IPv6
 * (WhatsApp e misure sponsor) e sospensioni per ambito che liberavano il numero.
 */

/** Una persona idonea alla community, con numero verificato e profilo. */
function abuseCommunityPerson(string $visibility = 'public', string $name = ''): User
{
    $user = User::factory()->create();
    $phone = '+39334'.str_pad((string) $user->id, 7, '0', STR_PAD_LEFT);
    $user->forceFill(['whatsapp_phone' => $phone, 'whatsapp_phone_hash' => app(WhatsappVerification::class)->fingerprint($phone), 'whatsapp_verified_at' => now()])->save();
    $user->communityProfile()->create(['handle' => 'abuso_'.$user->id, 'display_name' => $name !== '' ? $name : 'Persona '.$user->id, 'visibility' => $visibility]);

    return $user;
}

beforeEach(function (): void {
    config(['community.enabled' => true, 'community.whatsapp_enabled' => true, 'community.kapso_key' => 'test-key-not-real', 'community.phone_number_id' => '123', 'community.phone_hash_key' => 'test-fingerprint-key']);
    Http::preventStrayRequests();
    Http::fake(['api.kapso.ai/*' => Http::response(['messages' => [['id' => 'wamid.test']]])]);
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

// Finding 9: la lista dei bloccati non è un oracolo sui nomi.

it('non rivela il nome di un profilo privato o sospeso nella lista dei bloccati', function (): void {
    $viewer = User::factory()->create();
    $private = abuseCommunityPerson('private', 'NomeSegreto');
    $suspended = abuseCommunityPerson('public', 'NomeSospeso');
    $suspended->forceFill(['community_suspended_at' => now()])->save();
    $public = abuseCommunityPerson('public', 'NomePubblico');

    Sanctum::actingAs($viewer);
    foreach ([$private, $suspended, $public] as $target) {
        $this->postJson('/api/v1/community/people/'.$target->id.'/block')->assertOk();
    }

    $blocks = collect($this->getJson('/api/v1/community/followers')->assertOk()
        ->assertDontSee('NomeSegreto')->assertDontSee('NomeSospeso')->json('data.blocks'))->keyBy('user_id');

    expect($blocks[$private->id]['display_name'])->toBe(__('community.member'))
        ->and($blocks[$suspended->id]['display_name'])->toBe(__('community.member'))
        // Il blocco in sé non nasconde il nome di un profilo che chi guarda potrebbe vedere.
        ->and($blocks[$public->id]['display_name'])->toBe('NomePubblico');

    $this->actingAs($viewer)->get(route('community.followers', ['tab' => 'blocks']))
        ->assertOk()->assertDontSee('NomeSegreto')->assertDontSee('NomeSospeso')->assertSee('NomePubblico');
});

// Finding 10: un profilo prenota al massimo il nome attuale e quello appena lasciato.

it('non lascia accaparrare i nomi utente con una serie di cambi', function (): void {
    $owner = abuseCommunityPerson();
    Sanctum::actingAs($owner);
    foreach (['nome_a', 'nome_b', 'nome_c', 'nome_d'] as $handle) {
        // Fra due cambi passa l'attesa (D64): qui conta il registro dei nomi, non la fretta.
        $this->travel(31)->days();
        $this->postJson('/api/v1/community/profile', ['handle' => $handle, 'display_name' => 'Titolare', 'visibility' => 'public'])->assertOk();
    }

    $aliases = DB::table('community_handle_aliases')->where('community_profile_id', $owner->communityProfile->id)->pluck('handle')->sort()->values()->all();
    expect($aliases)->toBe(['nome_c', 'nome_d']);

    // Il nome appena lasciato rinvia ancora al profilo; quelli più vecchi tornano liberi.
    $this->get(route('community.profile', ['handle' => 'nome_c']))->assertRedirect(route('community.profile', ['handle' => 'nome_d']));

    $other = abuseCommunityPerson();
    Sanctum::actingAs($other);
    $this->postJson('/api/v1/community/profile', ['handle' => 'nome_a', 'display_name' => 'Altra persona', 'visibility' => 'public'])->assertOk();
    // Passata l'attesa, così il rifiuto qui sotto viene dal registro dei nomi e non dall'attesa.
    $this->travel(31)->days();
    Sanctum::actingAs($other->fresh());
    $this->postJson('/api/v1/community/profile', ['handle' => 'nome_c', 'display_name' => 'Altra persona', 'visibility' => 'public'])
        ->assertUnprocessable()->assertJsonPath('error.fields.handle.0', fn (string $errore): bool => ! str_contains($errore, 'potrai cambiarlo'));
});

it('permette di tornare al nome appena lasciato', function (): void {
    $owner = abuseCommunityPerson();
    Sanctum::actingAs($owner);
    foreach (['nome_a', 'nome_b', 'nome_a'] as $handle) {
        $this->travel(31)->days();
        $this->postJson('/api/v1/community/profile', ['handle' => $handle, 'display_name' => 'Titolare', 'visibility' => 'public'])->assertOk();
    }

    $aliases = DB::table('community_handle_aliases')->where('community_profile_id', $owner->communityProfile->id)->pluck('handle')->sort()->values()->all();
    expect($owner->communityProfile->fresh()->handle)->toBe('nome_a')->and($aliases)->toBe(['nome_a', 'nome_b']);
});

// Finding 11 e 25: i tetti per indirizzo contano il /64 di IPv6.

it('riduce un indirizzo IPv6 al suo /64 e lascia intero un IPv4', function (): void {
    expect(IpBucket::key('2001:db8:1:1:aaaa::1'))->toBe(IpBucket::key('2001:0db8:0001:0001:bbbb:0000:0000:0002'))
        ->and(IpBucket::key('2001:db8:1:1::1'))->toBe('2001:db8:1:1::/64')
        ->and(IpBucket::key('2001:db8:1:2::1'))->not->toBe(IpBucket::key('2001:db8:1:1::1'))
        ->and(IpBucket::key('::ffff:203.0.113.5'))->toBe('203.0.113.5')
        ->and(IpBucket::key('203.0.113.5'))->not->toBe(IpBucket::key('203.0.113.6'))
        ->and(IpBucket::key(null))->toBe('sconosciuto');
});

it('conta il tetto giornaliero degli invii WhatsApp sul /64 e non sull indirizzo intero', function (): void {
    $verification = app(WhatsappVerification::class);
    foreach (range(1, 10) as $i) {
        expect($verification->request(User::factory()->create(), '+39335000000'.($i - 1), '2001:db8:1:1::'.dechex($i))->outcome)->toBe(KapsoOutcome::Sent);
    }

    expect(fn () => $verification->request(User::factory()->create(), '+393351111111', '2001:db8:1:1::ffff'))
        ->toThrow(ValidationException::class, __('community.whatsapp.rate_limit'));

    // Un altro /64 ha il suo conto.
    expect($verification->request(User::factory()->create(), '+393352222222', '2001:db8:1:2::1')->outcome)->toBe(KapsoOutcome::Sent);
    Http::assertSentCount(11);
});

it('non lascia gonfiare le impressioni di una campagna ruotando gli indirizzi di un /64', function (): void {
    $campaign = Sponsorship::factory()->create(['city_id' => $this->city->id]);
    $url = route('sponsorships.metric', ['sponsorship' => $campaign, 'metric' => 'impressions']);

    foreach (range(1, 35) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::'.dechex($i)])->postJson($url)->assertNoContent();
    }
    expect((int) SponsorshipDailyStat::query()->where('sponsorship_id', $campaign->id)->sum('impressions'))->toBe(30);

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->postJson($url)->assertNoContent();
    expect((int) SponsorshipDailyStat::query()->where('sponsorship_id', $campaign->id)->sum('impressions'))->toBe(31);
});

it('non lascia gonfiare i clic dall API ruotando gli indirizzi di un /64', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $campaign = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $occurrence->event_id]);
    $token = hash_hmac('sha256', $campaign->getKey().'|'.$campaign->placement->value.'|'.now('UTC')->format('Y-m-d'), (string) config('app.key'));
    $url = '/api/v1/reports/sponsorships/'.$campaign->getKey().'/metrics/clicks';

    foreach (range(1, 35) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:9:9::'.dechex($i)])
            ->withHeaders(['X-Metric-Token' => $token])
            ->postJson($url, ['click_id' => (string) Str::uuid()])->assertNoContent();
    }

    expect((int) $campaign->refresh()->clicks)->toBe(30);
});

// Finding 53: una sospensione per ambito trattiene il numero come quella globale.

dataset('scoped suspensions', ['social_suspended_at', 'carpool_suspended_at']);

it('non lascia revocare la verifica a chi ha una sospensione per ambito', function (string $field): void {
    $user = abuseCommunityPerson();
    $user->forceFill([$field => now()])->save();
    $hash = $user->whatsapp_phone_hash;

    Sanctum::actingAs($user);
    $this->deleteJson('/api/v1/community/whatsapp')->assertForbidden();
    $this->actingAs($user)->delete(route('community.whatsapp.revoke'))->assertForbidden();
    expect($user->fresh()->whatsapp_phone_hash)->toBe($hash);

    // Lo staff può ancora revocarla.
    app(WhatsappVerification::class)->revoke($user, User::factory()->create());
    expect($user->fresh()->whatsapp_phone_hash)->toBeNull();
})->with('scoped suspensions');

it('conserva l impronta di chi si cancella con una sospensione per ambito', function (string $field): void {
    $user = abuseCommunityPerson();
    $phone = $user->whatsapp_phone;
    $user->forceFill([$field => now()])->save();

    app(DeleteAccount::class)($user);

    $deleted = User::withTrashed()->findOrFail($user->id);
    expect($deleted->whatsapp_phone_hash)->not->toBeNull()->and($deleted->{$field})->not->toBeNull()->and($deleted->whatsapp_phone)->toBeNull();

    expect(fn () => app(WhatsappVerification::class)->request(User::factory()->create(), $phone, '198.51.100.20'))
        ->toThrow(ValidationException::class, __('community.whatsapp.phone_unavailable'));
})->with('scoped suspensions');

it('libera l impronta di una sospensione per ambito solo dopo il periodo di conservazione', function (string $field): void {
    $expired = User::factory()->create();
    $recent = User::factory()->create();
    foreach ([[$expired, 25, '+393361111111'], [$recent, 23, '+393362222222']] as [$user, $months, $phone]) {
        DB::table('users')->where('id', $user->id)->update(['whatsapp_phone' => null, 'whatsapp_phone_hash' => app(WhatsappVerification::class)->fingerprint($phone),
            $field => now()->subMonths($months), 'deleted_at' => now()->subMonths($months - 1)]);
    }

    expect(app(PruneSuspendedFingerprints::class)())->toBe(1);

    $row = DB::table('users')->where('id', $expired->id)->first();
    expect($row->whatsapp_phone_hash)->toBeNull()->and($row->{$field})->toBeNull();
    $row = DB::table('users')->where('id', $recent->id)->first();
    expect($row->whatsapp_phone_hash)->not->toBeNull()->and($row->{$field})->not->toBeNull();
})->with('scoped suspensions');

it('non libera prima del tempo un impronta con una sospensione recente in un altro ambito', function (): void {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['whatsapp_phone' => null, 'whatsapp_phone_hash' => app(WhatsappVerification::class)->fingerprint('+393363333333'),
        'community_suspended_at' => now()->subMonths(30), 'carpool_suspended_at' => now()->subMonths(2), 'deleted_at' => now()->subMonth()]);

    expect(app(PruneSuspendedFingerprints::class)())->toBe(0);
    expect(DB::table('users')->where('id', $user->id)->value('whatsapp_phone_hash'))->not->toBeNull();
});

it('non lascia a chi ha una sospensione per ambito liberare il numero verificandone un secondo', function (string $field): void {
    $user = abuseCommunityPerson();
    $first = $user->whatsapp_phone;
    $hash = $user->whatsapp_phone_hash;
    $user->forceFill([$field => now()])->save();
    $second = '+393371234567';

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => $second])->assertForbidden();
    $this->actingAs($user)->post(route('community.whatsapp.send'), ['phone' => $second])->assertForbidden();
    Http::assertNothingSent();

    // Una sfida sul secondo numero aperta prima della sospensione: la conferma non lo lega.
    $challenge = WhatsappChallenge::query()->create([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'phone' => $second,
        'phone_hash' => app(WhatsappVerification::class)->fingerprint($second), 'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5), 'status' => WhatsappChallengeStatus::Sent,
    ]);
    $this->postJson('/api/v1/community/whatsapp/confirm', ['challenge_id' => $challenge->id, 'code' => '123456'])->assertForbidden();
    expect($user->fresh()->whatsapp_phone_hash)->toBe($hash)
        ->and($challenge->fresh()->attempts)->toBe(0)
        ->and($challenge->fresh()->consumed_at)->toBeNull();

    // Il primo numero resta legato al sospeso: un altro account non lo può verificare.
    expect(fn () => app(WhatsappVerification::class)->request(User::factory()->create(), $first, '198.51.100.21'))
        ->toThrow(ValidationException::class, __('community.whatsapp.phone_unavailable'));
})->with('scoped suspensions');

it('lascia a chi ha una sospensione per ambito richiedere un codice sul numero che ha già', function (string $field): void {
    $user = abuseCommunityPerson();
    $user->forceFill([$field => now()])->save();

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => $user->whatsapp_phone])->assertSuccessful();
})->with('scoped suspensions');

it('lascia verificare un numero a chi è sospeso per ambito senza averne uno', function (string $field): void {
    $user = User::factory()->create([$field => now()]);

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/community/whatsapp', ['phone' => '+393371234568'])->assertSuccessful();
})->with('scoped suspensions');

it('rifiuta la revoca anche a chi è sospeso in tutto, come prima', function (): void {
    $user = abuseCommunityPerson();
    $user->forceFill(['community_suspended_at' => now()])->save();

    expect(fn () => app(WhatsappVerification::class)->revoke($user))->toThrow(HttpException::class);
});
