<?php

declare(strict_types=1);

use App\Models\Organizer;
use App\Models\User;
use App\Support\Auth\PostLoginDestination;
use Tests\Support\VenueIsolationScenario;

/**
 * Dove arriva chi entra dal login unico: la redazione in `/admin`, chi
 * gestisce un locale nel suo pannello, chi gestisce un organizzatore nel suo,
 * tutti gli altri nel feed. La pagina da cui si era partiti vince, se è di
 * questo sito e la persona la può aprire.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
});

function destinationOrganizerOwner(): array
{
    $owner = User::factory()->create();
    $organizer = Organizer::query()->create([
        'name' => 'Collettivo Portici',
        'city_id' => test()->scenario->city->id,
        'owner_id' => $owner->id,
        'is_active' => true,
    ]);

    return [$owner, $organizer];
}

it('porta ognuno nella propria area dopo l accesso', function (): void {
    $s = $this->scenario;
    [$organizerOwner, $organizer] = destinationOrganizerOwner();

    $cases = [
        [$s->admin, url('/admin')],
        [$s->moderator, url('/admin')],
        [$s->ownerA, url('/gestione/'.$s->venueA->slug)],
        [$s->editorA, url('/gestione/'.$s->venueA->slug)],
        [$organizerOwner, url('/organizza/'.$organizer->slug)],
        [$s->plainUser, route('account.feed')],
    ];

    foreach ($cases as [$user, $expected]) {
        expect(PostLoginDestination::for($user))->toBe($expected);

        $this->post(route('account.login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($expected);
        $this->assertAuthenticatedAs($user);
        auth()->logout();
        session()->flush();
    }
});

it('preferisce il locale al pannello dell organizzatore per chi gestisce tutti e due', function (): void {
    $s = $this->scenario;
    Organizer::query()->create(['name' => 'Collettivo Aurora', 'city_id' => $s->city->id, 'owner_id' => $s->ownerA->id, 'is_active' => true]);

    expect(PostLoginDestination::for($s->ownerA))->toBe(url('/gestione/'.$s->venueA->slug));
});

it('rispetta la pagina da cui si era partiti', function (): void {
    $s = $this->scenario;

    $this->withSession(['url.intended' => url('/eventi')])
        ->post(route('account.login.store'), ['email' => $s->admin->email, 'password' => 'password'])
        ->assertRedirect(url('/eventi'));
});

it('scarta il ritorno verso un pannello o un locale che la persona non può aprire', function (User|string $user, string $intended, string $expected): void {
    $s = $this->scenario;
    $user = is_string($user) ? $s->{$user} : $user;
    $intended = str_replace('{B}', $s->venueB->slug, $intended);
    $expected = str_replace('{A}', $s->venueA->slug, $expected);

    $this->withSession(['url.intended' => url($intended)])
        ->post(route('account.login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(url($expected));
})->with([
    'utente verso la redazione' => ['plainUser', '/admin/venues', '/il-mio-feed'],
    'utente verso i locali' => ['plainUser', '/gestione', '/il-mio-feed'],
    'locale verso la redazione' => ['ownerA', '/admin', '/gestione/{A}'],
    'locale verso un altro locale' => ['ownerA', '/gestione/{B}/eventi', '/gestione/{A}'],
    'redazione verso un locale' => ['admin', '/gestione/{B}', '/admin'],
]);

it('scarta il ritorno verso un altro sito', function (string $intended): void {
    expect(PostLoginDestination::resolve($this->scenario->plainUser, $intended))->toBe(route('account.feed'))
        ->and(PostLoginDestination::allows($this->scenario->plainUser, $intended))->toBeFalse();
})->with([
    'https://evil.example/il-mio-feed',
    '//evil.example/x',
    '/\\evil.example',
    "/eventi\n",
    'javascript:alert(1)',
]);

it('accetta il ritorno verso una pagina qualunque di questo sito', function (): void {
    $user = $this->scenario->plainUser;

    expect(PostLoginDestination::allows($user, url('/eventi?pagina=2')))->toBeTrue()
        ->and(PostLoginDestination::allows($user, '/biglietti'))->toBeTrue()
        ->and(PostLoginDestination::allows($this->scenario->ownerA, url('/gestione/'.$this->scenario->venueA->slug.'/eventi')))->toBeTrue();
});

it('manda nella propria area chi apre /accedi quando è già dentro', function (): void {
    $s = $this->scenario;

    $this->actingAs($s->admin)->get(route('login'))->assertRedirect(url('/admin'));
    $this->actingAs($s->ownerA)->get(route('login'))->assertRedirect(url('/gestione/'.$s->venueA->slug));
    $this->actingAs($s->plainUser)->get(route('login'))->assertRedirect(route('account.feed'));
    $this->actingAs($s->plainUser)->get(route('login', ['intended' => url('/admin')]))->assertRedirect(route('account.feed'));
    $this->actingAs($s->plainUser)->get(route('login', ['intended' => url('/eventi')]))->assertRedirect(url('/eventi'));
});

it('accetta il ritorno scritto nell indirizzo di /accedi, come fa il piè di pagina', function (): void {
    $s = $this->scenario;

    $this->get(route('login', ['intended' => url('/gestione')]))->assertOk()->assertSee('data-login-panel="venue"', false);
    $this->post(route('account.login.store'), ['email' => $s->ownerA->email, 'password' => 'password'])
        ->assertRedirect(url('/gestione'));
    $this->get('/gestione')->assertRedirect();
});
