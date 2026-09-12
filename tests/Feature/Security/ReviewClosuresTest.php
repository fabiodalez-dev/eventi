<?php

declare(strict_types=1);

use App\Enums\ImageType;
use App\Exceptions\ImportException;
use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Models\ImportSource;
use App\Models\MobileAuthChallenge;
use App\Models\Redirect;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Rules\InternalRedirectTarget;
use App\Rules\RealImage;
use App\Services\Http\BoundedStream;
use App\Services\Http\SafeWebPushFactory;
use App\Services\Import\HostResolver;
use App\Services\Import\IcsImportDriver;
use App\Services\Import\ImportUrlGuard;
use App\Services\Media\ImageSafety;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Minishlink\WebPush\Subscription;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ImageFixtures;

it('rejects private push destinations on both registration paths', function (string $endpoint): void {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route('account.push.store'), [
        'endpoint' => $endpoint, 'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
    ])->assertUnprocessable();
    $this->postJson('/api/v1/me/devices', [
        'platform' => 'web', 'endpoint' => $endpoint, 'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
    ])->assertUnprocessable();
})->with(['https://127.0.0.1/a', 'https://10.0.0.1/a', 'https://[::1]/a', 'https://localhost/a', 'https://user:pass@example.org/a']);

it('fails closed when DNS changes between validation and connection', function (): void {
    $resolver = new class implements HostResolver
    {
        public int $calls = 0;

        public function resolve(string $host): array
        {
            return ++$this->calls === 1 ? ['93.184.216.34'] : ['127.0.0.1'];
        }
    };
    expect(fn () => (new ImportUrlGuard($resolver))->resolvedAddress('https://rebind.example/a'))->toThrow(ImportException::class);
});

it('pins every redirect and strips credentials when changing origin', function (): void {
    app()->bind(HostResolver::class, fn () => new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return $host === 'first.example' ? ['93.184.216.34'] : ['1.1.1.1'];
        }
    });
    $optionsSeen = [];
    Http::fake(function ($request, $options) use (&$optionsSeen) {
        $optionsSeen[] = $options;
        if ($request->url() === 'https://first.example/a') {
            expect($request->hasHeader('Authorization'))->toBeTrue();

            return Http::response('', 302, ['Location' => 'https://second.example/b']);
        }
        expect($request->hasHeader('Authorization'))->toBeFalse();

        return Http::response("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");
    });
    $source = new ImportSource(['url' => 'https://first.example/a', 'credentials' => 'secret']);
    expect(app(IcsImportDriver::class)->fetch($source))->toBe([]);
    expect($optionsSeen)->toHaveCount(2);
    expect($optionsSeen[0]['curl'][CURLOPT_RESOLVE])->toBe(['first.example:443:93.184.216.34']);
    expect($optionsSeen[1]['curl'][CURLOPT_RESOLVE])->toBe(['second.example:443:1.1.1.1']);
    expect($optionsSeen[0]['stream'])->toBeFalse()->and($optionsSeen[0]['allow_redirects'])->toBeFalse();
});

it('stops a response while writing the first oversized chunk', function (): void {
    $sink = new BoundedStream(10);
    $sink->write('12345');
    expect(fn () => $sink->write('678901'))->toThrow(ImportException::class);
    expect((string) $sink)->toBe('12345');
});

it('revokes tokens and mobile challenges for a reset raised by any panel', function (): void {
    $user = User::factory()->create();
    $user->createToken('stolen');
    MobileAuthChallenge::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', 'old'), 'expires_at' => now()->addMinutes(15)]);
    event(new PasswordReset($user));
    expect($user->tokens()->count())->toBe(0)->and(MobileAuthChallenge::query()->count())->toBe(0);
});

it('does not resurrect a consumed web link after flushing cache', function (): void {
    testCity();
    $user = User::factory()->create();
    $url = MagicLoginLink::url($user);
    $this->get($url)->assertRedirect(route('account.feed'));
    auth()->logout();
    Cache::flush();
    $this->get($url)->assertRedirect(route('account.magic-link'));
    $this->assertGuest();
});

it('requires the requesting installation proof without consuming a link on an invalid proof', function (): void {
    $user = User::factory()->create();
    $verifier = str_repeat('v', 43);
    $raw = str_repeat('t', 64);
    $challenge = MobileAuthChallenge::query()->create([
        'user_id' => $user->id, 'token_hash' => hash('sha256', $raw),
        'password_fingerprint' => MagicLoginLink::fingerprint($user),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'expires_at' => now()->addMinutes(15),
    ]);
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $raw, 'code_verifier' => str_repeat('x', 43)])->assertBadRequest();
    expect($challenge->fresh()->used_at)->toBeNull()->and($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $raw, 'code_verifier' => $verifier])->assertOk();
    expect($user->tokens()->count())->toBe(1);
});

it('serves a mobile login landing without reflecting or leaking its token', function (): void {
    $this->get('/app/auth/magic?token=secret-marker')->assertOk()->assertDontSee('secret-marker')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('only accepts internal redirect targets', function (string $target): void {
    expect(Validator::make(['path' => $target], ['path' => [new InternalRedirectTarget]])->fails())->toBeTrue();
})->with(['https://evil.example', '//evil.example', '/\\evil.example', '/%2fevil.example', "/\nevil"]);

it('logs an actual failed API login without retaining the submitted password', function (): void {
    $user = User::factory()->create();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-secret'])->assertUnauthorized();
    $entry = Activity::query()->where('description', 'accesso_fallito')->firstOrFail();
    expect($entry->subject_id)->toBe($user->id)->and($entry->properties->toJson())->not->toContain('wrong-secret');
});

it('gives the same reset notification for known and unknown accounts in every panel', function (string $panel): void {
    (new RolesAndPermissionsSeeder)->run();
    Illuminate\Support\Facades\Notification::fake();
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    foreach ([$user->email, 'absent@example.test'] as $email) {
        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $email])->call('request')
            ->assertNotified(Notification::make()->title(__(Password::RESET_LINK_SENT))
                ->body(__('filament-panels::auth/pages/password-reset/request-password-reset.notifications.sent.body'))->success());
    }
})->with(['admin', 'venue', 'organizer']);

it('caps API registrations and reactivations without leaving unbounded revoked rows', function (): void {
    config()->set('account.max_devices_per_user', 3);
    $user = User::factory()->create();
    $this->withToken($user->createToken('test')->plainTextToken);
    foreach (range(1, 6) as $number) {
        $this->postJson('/api/v1/me/devices', [
            'platform' => 'web', 'endpoint' => 'https://push.example.org/'.$number,
            'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
        ])->assertCreated();
        $user->devices()->where('endpoint', 'https://push.example.org/'.$number)->update(['revoked_at' => now()]);
    }
    expect($user->devices()->count())->toBe(3);
    foreach ([4, 5, 6, 1] as $number) {
        $this->postJson('/api/v1/me/devices', [
            'platform' => 'web', 'endpoint' => 'https://push.example.org/'.$number,
            'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
        ])->assertCreated();
    }
    expect($user->devices()->count())->toBe(3)->and($user->devices()->whereNull('revoked_at')->count())->toBe(3);
});

it('checks real AVIF dimensions before decoding the image', function (): void {
    if (! extension_loaded('imagick') || Imagick::queryFormats('AVIF') === []) {
        $this->markTestSkipped('AVIF encoder unavailable');
    }
    $image = new Imagick;
    $image->newImage(600, 600, 'red');
    $image->setImageFormat('AVIF');
    $upload = ImageFixtures::upload('image.avif', $image->getImagesBlob());
    $image->clear();
    if (ImageType::detect($upload->getPathname()) !== ImageType::Avif) {
        $this->markTestSkipped('The installed encoder did not produce AVIF bytes');
    }
    config()->set('media.max_pixels', 300_000);
    expect(Validator::make(['file' => $upload], ['file' => [new RealImage]])->fails())->toBeTrue();
    config()->set('media.max_pixels', 500_000);
    expect(Validator::make(['file' => $upload], ['file' => [new RealImage]])->fails())->toBeFalse();
});

it('blocks previously stored private push endpoints at dispatch and returns a failed report', function (): void {
    config()->set('webpush.vapid.public_key', null);
    config()->set('webpush.vapid.private_key', null);
    $push = (new SafeWebPushFactory(app()))->make();
    $report = $push->sendOneNotification(new Subscription('https://127.0.0.1/private'));
    expect($report->isSuccess())->toBeFalse()->and($report->getReason())->toContain('non pubblica');
});

it('blocks external destinations already stored in redirects', function (): void {
    testCity();
    Redirect::query()->create(['from_path' => '/old-security-page', 'to_path' => '//evil.example', 'status' => 301, 'is_wildcard' => false]);
    $this->get('/old-security-page')->assertNotFound()->assertHeaderMissing('Location');
});

it('throttles panel login attempts on the account across changing IPs', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config()->set('api.rate_limit.attempts_per_account', 2);
    $user = User::factory()->create();
    foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'wrong'])
            ->call('authenticate')->assertHasErrors(['data.email']);
    }
    expect(Activity::query()->where('description', 'accesso.limitato')->count())->toBe(1);
});

it('does not raise a decoder resource limit configured as zero by the host', function (): void {
    if (! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick unavailable');
    }
    $previous = Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY);
    try {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 0);
        ImageSafety::limitResources();
        expect(Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY))->toEqual(0);
    } finally {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $previous);
    }
});
