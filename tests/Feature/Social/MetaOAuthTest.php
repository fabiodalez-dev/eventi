<?php

use App\Filament\Admin\Pages\SocialSettings;
use App\Models\SocialConnection;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->connection = SocialConnection::create(['city_id' => testCity()->id, 'app_id' => '12345', 'app_secret' => 'private-app-secret', 'graph_version' => 'v25.0']);
    Http::preventStrayRequests();
});

it('authorizes Meta using one-time state and stores only the chosen page token', function (): void {
    $this->actingAs($this->admin);
    $start = $this->get(route('social.meta.connect'))->assertRedirect();
    expect($start->headers->get('Location'))->toStartWith('https://www.facebook.com/v25.0/dialog/oauth?')->not->toContain('private-app-secret');
    $state = session('meta_oauth.state');
    Http::fake([
        '*/oauth/access_token' => Http::sequence()->push(['access_token' => 'short-user-token'])->push(['access_token' => 'long-user-token']),
        '*/me/accounts*' => Http::response(['data' => [
            ['id' => '500', 'name' => 'Pagina scelta', 'access_token' => 'page-private-token', 'instagram_business_account' => ['id' => '600']],
            ['id' => '501', 'name' => 'Altra pagina', 'access_token' => 'other-private-token'],
        ]]),
        '*/500*' => Http::response(['id' => '500', 'instagram_business_account' => ['id' => '600']]),
        '*/600*' => Http::response(['id' => '600', 'username' => 'incitta']),
    ]);
    $this->get(route('social.meta.callback', ['state' => $state, 'code' => 'private-auth-code']))->assertRedirect(route('social.meta.pages'));
    expect(session('meta_pages'))->not->toContain('page-private-token');
    $this->get(route('social.meta.pages'))->assertOk()->assertSee('Pagina scelta')->assertDontSee('page-private-token')->assertDontSee('other-private-token');
    $this->post(route('social.meta.select'), ['page_id' => '500'])->assertRedirect(SocialSettings::getUrl());
    $connection = $this->connection->fresh();
    expect($connection->page_id)->toBe('500')->and($connection->instagram_id)->toBe('600')
        ->and($connection->access_token)->toBe('page-private-token')->and($connection->automatic)->toBeFalse()->and($connection->verified_at)->not->toBeNull();
    expect($connection->getRawOriginal('app_secret'))->not->toContain('private-app-secret');
    $this->get(route('social.meta.callback', ['state' => $state, 'code' => 'private-auth-code']))->assertForbidden();
});

it('rejects forged expired or cross-user OAuth state', function (): void {
    $this->actingAs($this->admin)->get(route('social.meta.connect'));
    $this->get(route('social.meta.callback', ['state' => 'forged', 'code' => 'code']))->assertForbidden();
    $this->get(route('social.meta.connect'));
    $state = session('meta_oauth.state');
    $this->travel(11)->minutes();
    $this->get(route('social.meta.callback', ['state' => $state, 'code' => 'code']))->assertForbidden();
    Http::assertNothingSent();
});

it('rejects unauthorized users before starting authentication', function (): void {
    $this->actingAs(User::factory()->create())->get(route('social.meta.connect'))->assertForbidden();
    Http::assertNothingSent();
});

it('handles denied authorization without storing a token', function (): void {
    $this->actingAs($this->admin)->get(route('social.meta.connect'));
    $state = session('meta_oauth.state');
    $this->get(route('social.meta.callback', ['state' => $state, 'error' => 'access_denied']))->assertRedirect(SocialSettings::getUrl());
    expect($this->connection->fresh()->access_token)->toBeNull();
    Http::assertNothingSent();
});
