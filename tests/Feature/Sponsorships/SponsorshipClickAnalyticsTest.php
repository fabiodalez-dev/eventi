<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\SponsorshipAnalytics as AdminAnalytics;
use App\Filament\Venue\Pages\SponsorshipAnalytics as VenueAnalytics;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Models\User;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use App\Services\Sponsorship\SponsorshipReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 20:00');
    $date = occurrenceAtLocal($this->city, testCategory(), '2026-09-11 21:00', event: ['title' => 'Concerto del locale A']);
    $this->venue = $date->event->venue;
    $this->campaign = Sponsorship::factory()->create(['city_id' => $this->city->id, 'event_id' => $date->event_id]);
    (new RolesAndPermissionsSeeder)->run();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel('admin');
});

it('registra ogni nuovo clic web una volta sola anche con ritrasmissioni', function (): void {
    $url = route('sponsorships.metric', ['sponsorship' => $this->campaign, 'metric' => 'clicks']);
    $payload = ['click_id' => (string) Str::uuid(), 'placement' => 'banner', 'page' => 'feed'];
    $this->postJson($url, $payload)->assertNoContent();
    $this->postJson($url, $payload)->assertNoContent();
    $this->postJson($url, [...$payload, 'click_id' => (string) Str::uuid()])->assertNoContent();
    expect(SponsorshipClick::count())->toBe(2)->and($this->campaign->fresh()->clicks)->toBe(2)
        ->and((int) SponsorshipDailyStat::sum('clicks'))->toBe(2);
    expect(SponsorshipClick::first()->toArray())->not->toHaveKeys(['ip', 'user_id', 'email']);
});

it('registra clic Android distinti entro 15 minuti senza duplicare lo stesso identificatore', function (): void {
    $banner = $this->getJson('/api/v1/sponsorships/banner?platform=android')->json('data');
    $url = '/api/v1/reports/sponsorships/'.$this->campaign->id.'/metrics/clicks';
    $headers = ['X-Metric-Token' => $banner['metric_token'], 'X-Installation-ID' => 'test-installation-12345'];
    $payload = ['click_id' => (string) Str::uuid(), 'placement' => 'banner'];
    $this->postJson($url, $payload, $headers)->assertNoContent();
    $this->postJson($url, $payload, $headers)->assertNoContent();
    $this->postJson($url, [...$payload, 'click_id' => (string) Str::uuid()], $headers)->assertNoContent();
    expect(SponsorshipClick::where('channel', 'android')->count())->toBe(2)->and($this->campaign->fresh()->clicks)->toBe(2);
});

it('mostra contatori grafici e registro paginato agli admin', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    for ($i = 0; $i < 26; $i++) {
        app(RecordSponsorshipMetric::class)->record($this->campaign, 'clicks', Request::create('/'), 'web');
    }
    Livewire::test(AdminAnalytics::class)->assertSee('Registro dei clic')->assertSee('Clic giornalieri')
        ->assertSee('fi-pagination', false)->assertSee('Concerto del locale A')->call('setPage', 2)->assertSee('Concerto del locale A');
    $this->get('/admin/statistiche-sponsorizzazioni')->assertOk();
});

it('isola le campagne del locale anche se viene forzato un id altrui', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('venue_owner');
    $owner->venues()->attach($this->venue, ['role' => 'owner']);
    $foreign = Sponsorship::factory()->create();
    $this->actingAs($owner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    expect(app(SponsorshipReport::class)->campaigns()->pluck('id')->all())->toBe([$this->campaign->id]);
    Livewire::test(VenueAnalytics::class)->assertSee('Registro dei clic')
        ->set('filters.campaign', $foreign->id)->assertForbidden();
});

it('nega le statistiche ai normali utenti e non accetta filtri di periodo arbitrari', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin/statistiche-sponsorizzazioni')->assertForbidden();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Livewire::test(AdminAnalytics::class)->set('filters.days', 10000)->assertHasErrors('filters.days');
});
