<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;

/*
 * Il prossimo evento accanto al calendario della pagina iniziale, con «a
 * seguire» sotto. Segue le preferenze di chi è collegato: i suoi interessi
 * scelgono le date, le categorie nascoste le tolgono — e la cache non deve
 * mescolare una persona con l'altra.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

it('mostra il prossimo evento della città e quelli a seguire a chi non è collegato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 20:30:00', event: ['title' => 'Primo della sera']);
    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Il giorno dopo']);

    $this->get('/')
        ->assertOk()
        ->assertSeeHtml('data-home-next-mode="city"')
        ->assertSee(__('calendar.home.next.eyebrow.city'))
        ->assertSee(__('calendar.home.next.title.soon'))
        ->assertSeeInOrder(['Primo della sera', __('calendar.home.next.following'), 'Il giorno dopo']);
});

it('dice «il prossimo evento» e non «inizia tra poco» quando manca ancora molto', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 08:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Domani sera']);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('calendar.home.next.title.next'))
        ->assertSee('Domani sera');
});

it('per chi segue un locale mostra il prossimo dei suoi, anche se in città ce n\'è uno prima', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 20:15:00', event: ['title' => 'Prima ma non suo']);
    $suo = occurrenceAtLocal($city, $category, '2026-09-05 22:00:00', event: ['title' => 'Il suo locale']);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->follows()->create(['followable_type' => 'venue', 'followable_id' => $suo->event->venue_id, 'notify' => true]);

    $response = $this->actingAs($user)->get('/')->assertOk();

    $response->assertSeeHtml('data-home-next-mode="personal"')
        ->assertSee(__('calendar.home.next.eyebrow.personal'));

    $blocco = str($response->getContent())->betweenFirst('data-home-next ', '</aside>')->toString();
    expect($blocco)->toContain('Il suo locale')->not->toContain('Prima ma non suo');
});

it('non mostra le categorie che la persona ha nascosto, e la sua scelta non passa agli altri', function (): void {
    $city = testCity();
    $musica = testCategory();
    $teatro = testCategory(['name' => 'Teatro', 'slug' => 'teatro']);

    freezeLocal($city, '2026-09-05 20:00:00');

    occurrenceAtLocal($city, $musica, '2026-09-05 20:15:00', event: ['title' => 'Concerto nascosto']);
    occurrenceAtLocal($city, $teatro, '2026-09-05 21:00:00', event: ['title' => 'Spettacolo visibile']);

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'content_preferences' => ['hidden_categories' => [$musica->getKey()]],
    ]);

    $suo = str($this->actingAs($user)->get('/')->assertOk()->getContent())->betweenFirst('data-home-next ', '</aside>')->toString();
    expect($suo)->toContain('Spettacolo visibile')->not->toContain('Concerto nascosto');

    auth()->logout();

    $anonimo = str($this->get('/')->assertOk()->getContent())->betweenFirst('data-home-next ', '</aside>')->toString();
    expect($anonimo)->toContain('Concerto nascosto');
});

it('non ripete nella sezione «inizia tra poco» le date già accanto al calendario', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 20:00:00');

    occurrenceAtLocal($city, $category, '2026-09-05 20:30:00', event: ['title' => 'Una volta sola']);

    $this->get('/')
        ->assertOk()
        ->assertSeeHtml('data-home-next ')
        ->assertDontSeeHtml('id="sezione-starting-soon"');
});
