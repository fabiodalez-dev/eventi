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
