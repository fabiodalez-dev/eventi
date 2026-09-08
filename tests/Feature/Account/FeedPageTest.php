<?php

declare(strict_types=1);

use App\Enums\FollowableType;
use App\Models\Category;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;

/**
 * `/il-mio-feed` (§15.7): le prossime date di ciò che si segue, in ordine di
 * data, con evidenza sui salvati — e **mai una pagina vuota**.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    freezeLocal($this->city, '2026-09-10 18:00');

    $this->user = User::factory()->create();
    $this->seguito = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo seguito']);
    $this->altro = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo ignorato']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('mostra le prossime date del locale seguito e non quelle degli altri', function (): void {
    $atteso = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', venue: $this->seguito, event: ['title' => 'Concerto atteso']);
    occurrenceAt($this->city, $this->category, '2026-09-13 19:00:00', venue: $this->altro, event: ['title' => 'Concerto ignorato']);

    /* Una data passata dello stesso locale seguito: la finestra la decide il
       motore con `upcoming()`, e il passato non è nel feed. */
    occurrenceAt($this->city, $this->category, '2026-08-20 19:00:00', venue: $this->seguito, event: ['title' => 'Concerto finito']);

    Follow::query()->create([
        'user_id' => $this->user->getKey(),
        'followable_type' => FollowableType::Venue->value,
        'followable_id' => $this->seguito->getKey(),
    ]);

    $this->actingAs($this->user)
        ->get('/il-mio-feed')
        ->assertOk()
        ->assertSee('Concerto atteso')
        ->assertDontSee('Concerto ignorato')
        ->assertDontSee('Concerto finito');

    expect($atteso->event->venue?->getKey())->toBe($this->seguito->getKey());
});

it('mette in evidenza le date già in agenda', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', venue: $this->seguito);

    Follow::query()->create([
        'user_id' => $this->user->getKey(),
        'followable_type' => FollowableType::Venue->value,
        'followable_id' => $this->seguito->getKey(),
    ]);

    SavedEvent::query()->create(['user_id' => $this->user->getKey(), 'occurrence_id' => $occurrence->getKey()]);

    $this->actingAs($this->user)
        ->get('/il-mio-feed')
        ->assertOk()
        /* Il cuore nasce acceso: è così che si distingue ciò che è in agenda
           dal resto del feed. */
        ->assertSee('aria-pressed="true"', false);
});

it('raccoglie anche ciò che arriva da categorie e tag seguiti', function (): void {
    $altraCategoria = Category::factory()->create(['name' => 'Teatro', 'supports_ongoing' => true]);
    $tag = Tag::factory()->create();

    occurrenceAt($this->city, $altraCategoria, '2026-09-12 19:00:00', venue: $this->altro, event: ['title' => 'Spettacolo di categoria']);

    $conTag = occurrenceAt($this->city, $this->category, '2026-09-13 19:00:00', venue: $this->altro, event: ['title' => 'Serata con tag']);
    $conTag->event->tags()->attach($tag);

    Follow::query()->create([
        'user_id' => $this->user->getKey(),
        'followable_type' => FollowableType::Category->value,
        'followable_id' => $altraCategoria->getKey(),
    ]);

    Follow::query()->create([
        'user_id' => $this->user->getKey(),
        'followable_type' => FollowableType::Tag->value,
        'followable_id' => $tag->getKey(),
    ]);

    $this->actingAs($this->user)
        ->get('/il-mio-feed')
        ->assertOk()
        ->assertSee('Spettacolo di categoria')
        ->assertSee('Serata con tag');
});

it('non mostra mai una pagina vuota a chi non segue ancora niente', function (): void {
    occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', venue: $this->seguito);
    occurrenceAt($this->city, $this->category, '2026-09-13 19:00:00', venue: $this->seguito);
    occurrenceAt($this->city, $this->category, '2026-09-14 19:00:00', venue: $this->altro);

    $this->actingAs($this->user)
        ->get('/il-mio-feed')
        ->assertOk()
        ->assertSee(__('account.feed.onboarding_title'))
        /* I locali più attivi: «attivi» significa «con date future», ed è un
           conteggio del motore. */
        ->assertSee('Circolo seguito')
        ->assertSee($this->category->name);
});

it('non è una pagina pubblica', function (): void {
    $this->get('/il-mio-feed')->assertRedirect(route('login'));
});

it('mantiene la lista e permette altri follow dopo il primo locale', function (): void {
    $this->actingAs($this->user)->postJson('/segui', ['type' => 'venue', 'id' => $this->seguito->id])->assertOk();
    $this->get('/il-mio-feed')->assertOk()->assertSee('data-feed-venues', false)->assertSee('Circolo ignorato')->assertSee('Gestisci locali e categorie seguiti');
    $this->postJson('/segui', ['type' => 'venue', 'id' => $this->altro->id])->assertOk();
    expect($this->user->followedIds(FollowableType::Venue))->toContain($this->seguito->id, $this->altro->id);
    $response = $this->get('/il-mio-feed')->assertOk()->assertSee('data-live-sponsorship', false);
    expect(strpos($response->getContent(), 'data-live-sponsorship'))->toBeLessThan(strpos($response->getContent(), 'id="feed-events"'));
});

it('pagina locali e categorie e permette di cercare un locale fuori dalla prima pagina', function (): void {
    Venue::factory()->approved()->count(12)->create(['city_id' => $this->city->id]);
    Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Zzz Teatro nascosto']);
    $first = $this->actingAs($this->user)->get('/il-mio-feed')->assertOk();
    expect($first->viewData('venues')->count())->toBe(6)
        ->and($first->viewData('venues')->total())->toBe(15);
    $second = $this->get('/il-mio-feed?venues_page=2')->assertOk();
    expect($second->viewData('venues')->currentPage())->toBe(2);
    $search = $this->get('/il-mio-feed?venue_q=Teatro%20nascosto')->assertOk()->assertSee('Zzz Teatro nascosto');
    expect($search->viewData('venues')->total())->toBe(1);
});

it('usa pagine esplicite di dodici eventi invece della lista infinita', function (): void {
    Follow::create(['user_id' => $this->user->id, 'followable_type' => 'venue', 'followable_id' => $this->seguito->id]);
    for ($i = 0; $i < 13; $i++) {
        occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', venue: $this->seguito);
    }
    $response = $this->actingAs($this->user)->get('/il-mio-feed')->assertOk()->assertSee('data-feed-pagination', false)->assertDontSee('data-results', false);
    expect($response->viewData('occurrences')->count())->toBe(12)->and($response->viewData('occurrences')->total())->toBe(13);
    expect($this->get('/il-mio-feed?page=2')->assertOk()->viewData('occurrences')->count())->toBe(1);
});

it('seguire un evento non lo fa comparire nel feed come sorgente', function (): void {
    /*
     * §15.3: seguire un evento significa salvarne le date, non seguire una
     * sorgente. Il feed guarda locali, tag e categorie — e chi ha solo un
     * evento seguito riceve quindi l'avvio guidato.
     */
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', venue: $this->seguito);

    Follow::query()->create([
        'user_id' => $this->user->getKey(),
        'followable_type' => FollowableType::Event->value,
        'followable_id' => $occurrence->event_id,
    ]);

    expect($this->user->followsAnything())->toBeFalse();

    $this->actingAs($this->user)
        ->get('/il-mio-feed')
        ->assertOk()
        ->assertSee(__('account.feed.onboarding_title'));
});
