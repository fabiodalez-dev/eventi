<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\User;
use App\Support\Notifications\PreferenceLinks;

it('lets browser users restore denied permissions and renew only this subscription', function (string $device): void {
    testCity();
    $user = User::factory()->create();
    $this->actingAs($user);
    config(['webpush.vapid.public_key' => 'AQID', 'webpush.vapid.private_key' => 'test-key']);
    $page = visit(PreferenceLinks::preferences($user))->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->assertVisible('[data-push-reset]');
    $page->script(<<<'JS'
        (() => {
            window.testPermission = 'denied';
            Object.defineProperty(window, 'Notification', { configurable: true, value: {
                get permission() { return window.testPermission; },
                async requestPermission() { return window.testPermission; }
            }});
            let subscription = null;
            const worker = { pushManager: {
                async getSubscription() { return subscription; },
                async subscribe() { subscription = { endpoint: 'https://push.example.org/browser-test', options: { applicationServerKey: new Uint8Array([1,2,3]).buffer },
                    toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'test-key', auth: 'test-auth' } }; },
                    async unsubscribe() { subscription = null; return true; }
                }; return subscription; }
            }, async showNotification() { window.testNotificationShown = true; } };
            navigator.serviceWorker.getRegistration = async () => worker;
            navigator.serviceWorker.register = async () => worker;
            Object.defineProperty(navigator.serviceWorker, 'ready', { configurable: true, value: Promise.resolve(worker) });
            window.dispatchEvent(new Event('focus'));
            return true;
        })()
    JS);
    $page->assertVisible('[data-push-help][open]')->click('[data-push-reset]')->assertSee('Il browser ha bloccato le notifiche');
    $page->script('window.testPermission = "granted"; window.dispatchEvent(new Event("focus"));');
    $page->click('[data-push-reset]')->assertSee('Attive su questo dispositivo.');
    expect(Device::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1);
    $page->click('[data-push-test]')->assertSee('Prova richiesta al browser.');
    expect($page->script('window.testNotificationShown'))->toBeTrue();
    $page->click('[data-push-reset]')->assertSee('Attive su questo dispositivo.');
    expect(Device::query()->where('user_id', $user->id)->count())->toBe(1);
    $page->screenshot(filename: 'browser-push-'.$device);
})->with(['desktop', 'mobile']);
