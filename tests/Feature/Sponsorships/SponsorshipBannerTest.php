<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipPlacement;
use App\Filament\Admin\Pages\SponsorshipBanners;
use App\Models\Sponsorship;
use App\Models\User;
use App\Settings\SponsorshipBannerSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SponsorshipBannerDemoSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 20:00');
});

it('autocompila lo stesso banner per web e Android senza inventare date o prezzi', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', event: ['title' => 'Concerto in piazza', 'price_type' => 'free', 'poster' => null]);
    $campaign = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $date->event_id]);
    foreach (['web', 'android'] as $platform) {
        $response = $this->getJson('/api/v1/sponsorships/banner?platform='.$platform)->assertOk()
            ->assertJsonPath('data.id', $campaign->id)->assertJsonPath('data.title', 'Concerto in piazza')
            ->assertJsonPath('data.price', __('events.price.free'))->assertJsonPath('data.image', null)
            ->assertJsonPath('data.place', $date->event->venue->name);
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        $this->get($response->json('data.url'))->assertOk();
        $this->postJson('/api/v1/reports/sponsorships/'.$campaign->id.'/metrics/impressions', [], [
            'X-Metric-Token' => $response->json('data.metric_token'),
            'X-Installation-ID' => 'banner-'.$platform,
        ])->assertNoContent();
    }
});

it('spegne indipendentemente i canali e non ripropone l evento già aperto', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00');
    Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $date->event_id]);
    $this->getJson('/api/v1/sponsorships/banner?platform=web&exclude_event='.$date->event->slug)->assertJsonPath('data', null);
    $settings = app(SponsorshipBannerSettings::class);
    $settings->web_enabled = false;
    $settings->save();
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertJsonPath('data', null);
    $this->getJson('/api/v1/sponsorships/banner?platform=android')->assertJsonPath('data.event_slug', $date->event->slug);
    $settings->android_enabled = false;
    $settings->save();
    $this->getJson('/api/v1/sponsorships/banner?platform=android')->assertJsonPath('data', null);
});

it('esclude tutte le collocazioni dalla mezzanotte locale successiva alla fine', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-10 16:00', '2026-09-10 18:00');
    foreach (SponsorshipPlacement::cases() as $placement) {
        Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $date->event_id, 'placement' => $placement]);
    }
    freezeLocal($this->city, '2026-09-10 23:59:30');
    expect(Sponsorship::visible()->count())->toBe(4);
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertJsonPath('data.expires_at', '2026-09-10T22:00:00+00:00');
    freezeLocal($this->city, '2026-09-11 00:00:00');
    expect(Sponsorship::visible()->count())->toBe(0);
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertJsonPath('data', null);
});

it('conserva eventi su più giorni e ricorrenti ma non le date annullate', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-09 16:00', '2026-09-11 18:00');
    $campaign = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $date->event_id]);
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeTrue();
    $date->update(['status' => OccurrenceStatus::Cancelled]);
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeFalse();
    $next = $date->replicate();
    $next->starts_at = now('UTC')->addWeek();
    $next->ends_at = now('UTC')->addWeek()->addHours(2);
    $next->status = OccurrenceStatus::Scheduled;
    $next->save();
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeTrue();
});

it('riserva gli interruttori agli amministratori della piattaforma', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin)->get('/admin/sponsorship-banners')->assertOk();
    Livewire::test(SponsorshipBanners::class)->fillForm(['web_enabled' => false, 'android_enabled' => false])
        ->call('save')->assertHasNoFormErrors();
    expect(app(SponsorshipBannerSettings::class)->web_enabled)->toBeFalse();
    $this->actingAs(User::factory()->create())->get('/admin/sponsorship-banners')->assertForbidden();
});

it('non pubblicizza locali sospesi né eventi appartenenti a un altra città', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00');
    $campaign = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $date->event_id]);
    $date->event->venue->update(['status' => 'suspended']);
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertJsonPath('data', null);
    $date->event->venue->update(['status' => 'approved']);
    $campaign->update(['city_id' => testCity(['slug' => 'vicenza'])->id]);
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeFalse();
});

it('include il banner nel profilo autenticato ma non nel login', function (): void {
    $this->get('/accedi')->assertOk()->assertDontSee('data-live-sponsorship', false);
    $this->actingAs(User::factory()->create())->get('/il-mio-profilo')->assertOk()->assertSee('data-live-sponsorship', false);
});

it('crea tre campagne dimostrative idempotenti senza pagamenti', function (): void {
    for ($i = 0; $i < 3; $i++) {
        occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00');
    }
    $this->seed(SponsorshipBannerDemoSeeder::class);
    $this->seed(SponsorshipBannerDemoSeeder::class);
    expect(Sponsorship::visible()->count())->toBe(3)
        ->and(Sponsorship::sum('amount_cents'))->toBe(0);
});

it('non pubblica demo in produzione senza autorizzazione esplicita', function (): void {
    $this->app->instance('env', 'production');
    $this->artisan('sponsorships:demo', ['city' => $this->city->slug])->assertFailed();
    expect(Sponsorship::count())->toBe(0);
    $this->app->instance('env', 'testing');
});
