<?php

declare(strict_types=1);

use App\Enums\FollowableType;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;

/*
 * Flussi del «segui» (§15.3, §15.7): una persona sceglie che cosa seguire —
 * un locale, un organizzatore, una categoria, un tag, una serie — e il suo
 * feed, sul sito e nell'app, si riempie solo di quello. Poi cambia idea.
 */

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

/** I titoli delle date restituite dal feed dell'API, nell'ordine. */
function flowsFollowingFeedTitles(object $test): array
{
    return $test->getJson('/api/v1/me/feed')->assertOk()->json('data.*.title');
}

it('segue un locale dalla sua scheda, ne ritrova le date nel feed web e API e poi smette di seguirlo', function (): void {
    $circolo = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Arci Padova']);
    $altrove = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria Lontana']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', venue: $circolo, event: ['title' => 'Jazz al Circolo']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-15 21:00', venue: $altrove, event: ['title' => 'Serata altrove']);
    occurrenceAtLocal($this->city, $this->category, '2026-10-01 21:00', venue: $circolo, event: ['title' => 'Concerto di ottobre']);

    // Prima di seguire qualcosa: avvio guidato, mai una pagina vuota.
    $this->actingAs($this->user)->get('/il-mio-feed')->assertOk()->assertSee(__('account.feed.onboarding_title'))->assertDontSee('Jazz al Circolo');
    $vuoto = $this->getJson('/api/v1/me/feed')->assertOk()->assertJsonCount(0, 'data');
    expect($vuoto->json('meta.onboarding.venues'))->not->toBeEmpty();

    $this->get(route('venues.show', $circolo->slug))->assertOk()->assertSee(__('account.follow.venue'));
    $this->from(route('venues.show', $circolo->slug))
        ->post('/segui', ['type' => 'venue', 'id' => $circolo->id])
        ->assertRedirect(route('venues.show', $circolo->slug))
        ->assertSessionHas('status', __('account.follow.stored'));

    $follow = Follow::query()->where('user_id', $this->user->id)->sole();
    expect($follow->followable_type)->toBe('venue')
        ->and((int) $follow->followable_id)->toBe($circolo->id)
        ->and($follow->notify)->toBeTrue();

    $this->get(route('venues.show', $circolo->slug))->assertOk()->assertSee(__('account.follow.following'));
    $this->get('/il-mio-feed')->assertOk()->assertSee('Jazz al Circolo')->assertDontSee('Serata altrove')->assertDontSee('Concerto di ottobre');

    $api = $this->getJson('/api/v1/me/feed')->assertOk()->assertJsonPath('meta.onboarding', null);
    expect($api->json('data.*.title'))->toBe(['Jazz al Circolo'])
        ->and($api->json('data.0.recommendation_reasons'))->toContain(__('decision.because_venue'));

    // Cambia idea: dal sito smette di seguirlo e il feed torna all'avvio guidato.
    $this->from('/il-mio-feed')->delete('/segui/venue/'.$circolo->id)
        ->assertRedirect('/il-mio-feed')->assertSessionHas('status', __('account.follow.removed'));
    expect(Follow::query()->where('user_id', $this->user->id)->count())->toBe(0);
    $this->get('/il-mio-feed')->assertOk()->assertSee(__('account.feed.onboarding_title'))->assertDontSee('Jazz al Circolo');
    expect(flowsFollowingFeedTitles($this))->toBe([]);
});

it('segue un organizzatore dall app, vede le sue date in qualunque locale e lo toglie', function (): void {
    $proLoco = Organizer::create(['name' => 'Pro Loco Padova', 'city_id' => $this->city->id, 'is_active' => true]);
    $altro = Organizer::create(['name' => 'Altri organizzatori', 'city_id' => $this->city->id, 'is_active' => true]);
    occurrenceAtLocal($this->city, $this->category, '2026-11-20 18:00', event: ['title' => 'Mercatino della Pro Loco', 'organizer_id' => $proLoco->id]);
    occurrenceAtLocal($this->city, $this->category, '2026-11-21 18:00', event: ['title' => 'Castagnata della Pro Loco', 'organizer_id' => $proLoco->id]);
    occurrenceAtLocal($this->city, $this->category, '2026-11-21 20:00', event: ['title' => 'Festa di altri', 'organizer_id' => $altro->id]);

    $this->actingAs($this->user);
    $this->getJson('/api/v1/me/follows/organizer/'.$proLoco->id)->assertOk()->assertJsonPath('data.following', false);

    $this->postJson('/api/v1/me/follows', ['type' => 'organizer', 'id' => $proLoco->id])
        ->assertCreated()
        ->assertJsonPath('data.type', 'organizer')
        ->assertJsonPath('data.name', 'Pro Loco Padova')
        // Un organizzatore alimenta il feed ma, di suo, non manda riepiloghi.
        ->assertJsonPath('data.notify', false)
        ->assertJsonPath('data.notification_mode', 'none');

    $this->getJson('/api/v1/me/follows/organizer/'.$proLoco->id)->assertOk()
        ->assertJsonPath('data.following', true)->assertJsonPath('data.notification_mode', 'none');

    $feed = $this->getJson('/api/v1/me/feed')->assertOk();
    expect($feed->json('data.*.title'))->toBe(['Mercatino della Pro Loco', 'Castagnata della Pro Loco'])
        ->and($feed->json('data.0.recommendation_reasons'))->toBe([__('decision.because_organizer')]);
    $this->get('/il-mio-feed')->assertOk()->assertSee('Castagnata della Pro Loco')->assertDontSee('Festa di altri');

    // Un organizzatore disattivato smette di alimentare il feed anche se seguito.
    $proLoco->update(['is_active' => false]);
    expect(flowsFollowingFeedTitles($this))->toBe([]);
    $proLoco->update(['is_active' => true]);

    $this->deleteJson('/api/v1/me/follows/organizer/'.$proLoco->id)->assertOk()->assertJsonPath('data.message', __('account.api.unfollowed'));
    $this->getJson('/api/v1/me/follows/organizer/'.$proLoco->id)->assertOk()->assertJsonPath('data.following', false);
    $this->deleteJson('/api/v1/me/follows/organizer/'.$proLoco->id)->assertNotFound();
    expect(flowsFollowingFeedTitles($this))->toBe([]);
});

it('segue una categoria e un tag dall app, li ritrova in elenco e nel feed, e ne toglie uno', function (): void {
    $teatro = testCategory(['name' => 'Teatro']);
    $tag = Tag::factory()->approved()->create(['name' => 'all-aperto']);
    occurrenceAtLocal($this->city, $teatro, '2026-11-13 21:00', event: ['title' => 'Prosa al Verdi']);
    $conTag = occurrenceAtLocal($this->city, $this->category, '2026-11-16 18:00', event: ['title' => 'Concerto in giardino']);
    $conTag->event->tags()->attach($tag);
    occurrenceAtLocal($this->city, $this->category, '2026-11-17 21:00', event: ['title' => 'Concerto al chiuso']);

    $this->actingAs($this->user);
    $this->postJson('/api/v1/me/follows', ['type' => 'category', 'id' => $teatro->id])->assertCreated()->assertJsonPath('data.name', 'Teatro');
    $this->postJson('/api/v1/me/follows', ['type' => 'tag', 'id' => $tag->id])->assertCreated();
    // Seguire due volte non crea una seconda riga.
    $this->postJson('/api/v1/me/follows', ['type' => 'tag', 'id' => $tag->id])->assertCreated();
    expect(Follow::query()->where('user_id', $this->user->id)->count())->toBe(2);

    $elenco = $this->getJson('/api/v1/me/follows')->assertOk()->assertJsonCount(2, 'data');
    expect($elenco->json('data.*.type'))->toEqualCanonicalizing(['category', 'tag']);

    expect(flowsFollowingFeedTitles($this))->toBe(['Prosa al Verdi', 'Concerto in giardino']);
    $this->get('/il-mio-feed')->assertOk()->assertSee('Prosa al Verdi')->assertSee('Concerto in giardino')->assertDontSee('Concerto al chiuso');

    $this->deleteJson('/api/v1/me/follows/category/'.$teatro->id)->assertOk();
    expect(flowsFollowingFeedTitles($this))->toBe(['Concerto in giardino']);
    $this->getJson('/api/v1/me/follows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'tag');
});

it('segue una serie ricorrente dal sito: le date future finiscono nei salvati e restano dopo aver smesso di seguirla', function (): void {
    $prima = occurrenceAtLocal($this->city, $this->category, '2026-11-18 21:00', event: ['title' => 'Jam session del mercoledì']);
    $seconda = EventOccurrence::factory()->create(['event_id' => $prima->event_id, 'starts_at' => localInstant($this->city, '2026-11-25 21:00')->utc(), 'ends_at' => null, 'doors_at' => null]);
    EventOccurrence::factory()->create(['event_id' => $prima->event_id, 'starts_at' => localInstant($this->city, '2026-11-04 21:00')->utc(), 'ends_at' => null, 'doors_at' => null]);

    $this->actingAs($this->user)
        ->from(route('events.show', $prima->event))
        ->post('/segui', ['type' => FollowableType::Event->value, 'id' => $prima->event_id])
        ->assertRedirect(route('events.show', $prima->event));

    // Le date future entrano subito nei salvati, la passata no.
    expect($this->user->savedEvents()->pluck('occurrence_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([$prima->id, $seconda->id]);
    $this->getJson('/salvataggi/stato')->assertOk()->assertJsonCount(2, 'ids');
    $this->get('/i-miei-salvataggi')->assertOk()->assertSee('Jam session del mercoledì');

    // La serie non è una sorgente del feed: si segue per i salvati, non per il feed.
    $this->get('/il-mio-feed')->assertOk()->assertSee(__('account.feed.onboarding_title'));

    $this->delete('/segui/event/'.$prima->event_id)->assertRedirect();
    expect(Follow::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and($this->user->savedEvents()->count())->toBe(2);
});

it('nasconde un locale seguito: le sue date spariscono dal feed web e API e tornano quando lo rimostra', function (): void {
    $circolo = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo rumoroso']);
    $teatro = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro tranquillo']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', venue: $circolo, event: ['title' => 'Serata rumorosa']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-15 21:00', venue: $teatro, event: ['title' => 'Serata tranquilla']);

    $this->actingAs($this->user);
    // Segue tutta la categoria: il feed contiene entrambi i locali.
    $this->post('/segui', ['type' => 'category', 'id' => $this->category->id])->assertRedirect();
    expect(flowsFollowingFeedTitles($this))->toBe(['Serata rumorosa', 'Serata tranquilla']);

    $this->from(route('venues.show', $circolo->slug))->post('/locali-nascosti/'.$circolo->id)
        ->assertRedirect(route('venues.show', $circolo->slug))
        ->assertSessionHas('status', __('account.hidden_venues.hidden'));
    expect($this->user->fresh()->content_preferences['hidden_venues'])->toBe([$circolo->id]);

    $this->get('/il-mio-feed')->assertOk()->assertSee('Serata tranquilla')->assertDontSee('Serata rumorosa');
    expect(flowsFollowingFeedTitles($this))->toBe(['Serata tranquilla']);
    // La scheda del locale resta raggiungibile, con il pulsante per rimostrarlo.
    $this->get(route('venues.show', $circolo->slug))->assertOk()->assertSee(__('account.hidden_venues.show'));

    // Un'altra persona continua a vedere tutto.
    $altra = User::factory()->create();
    $altra->follows()->create(['followable_type' => 'category', 'followable_id' => $this->category->id, 'notify' => true]);
    $this->actingAs($altra);
    expect(flowsFollowingFeedTitles($this))->toBe(['Serata rumorosa', 'Serata tranquilla']);

    $this->actingAs($this->user)->deleteJson('/api/v1/me/hidden-venues/'.$circolo->id)->assertNoContent();
    expect($this->user->fresh()->content_preferences['hidden_venues'])->toBe([]);
    expect(flowsFollowingFeedTitles($this))->toBe(['Serata rumorosa', 'Serata tranquilla']);

    // Lo stesso andata e ritorno con i verbi opposti: nasconde dall'app, rimostra dal sito.
    $this->postJson('/api/v1/me/hidden-venues/'.$circolo->id)->assertNoContent();
    expect(flowsFollowingFeedTitles($this))->toBe(['Serata tranquilla']);
    $this->from('/profilo/interessi')->delete('/locali-nascosti/'.$circolo->id)
        ->assertRedirect('/profilo/interessi')->assertSessionHas('status', __('account.hidden_venues.shown'));
    $this->get('/il-mio-feed')->assertOk()->assertSee('Serata rumorosa');
});

it('rifiuta il segui a chi non è collegato, su soggetti non pubblici o inesistenti e con tipi sconosciuti', function (): void {
    $bozza = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $approvato = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $spenta = testCategory(['name' => 'Categoria spenta', 'is_active' => false]);

    // Ospite: il sito rimanda all'accesso, l'API risponde 401.
    $this->post('/segui', ['type' => 'venue', 'id' => $approvato->id])->assertRedirect(route('login'));
    $this->delete('/segui/venue/'.$approvato->id)->assertRedirect(route('login'));
    $this->get('/il-mio-feed')->assertRedirect(route('login'));
    $this->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => $approvato->id])->assertUnauthorized();
    $this->getJson('/api/v1/me/feed')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->post('/locali-nascosti/'.$approvato->id)->assertRedirect(route('login'));

    $this->actingAs($this->user);
    $this->post('/segui', ['type' => 'venue', 'id' => $bozza->id])->assertNotFound();
    $this->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => $bozza->id])->assertNotFound();
    $this->postJson('/api/v1/me/follows', ['type' => 'category', 'id' => $spenta->id])->assertNotFound();
    $this->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => 999999])->assertNotFound();
    $this->postJson('/segui', ['type' => 'pianeta', 'id' => $approvato->id])->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->postJson('/api/v1/me/follows', ['type' => 'venue'])->assertUnprocessable();
    $this->delete('/segui/pianeta/'.$approvato->id)->assertNotFound();
    $this->deleteJson('/api/v1/me/follows/pianeta/1')->assertNotFound();
    // Nascondere un locale non pubblico non è possibile.
    $this->postJson('/api/v1/me/hidden-venues/'.$bozza->id)->assertNotFound();

    // Togliere il follow di un altro non tocca la sua riga.
    $altra = User::factory()->create();
    $altra->follows()->create(['followable_type' => 'venue', 'followable_id' => $approvato->id, 'notify' => true]);
    $this->deleteJson('/api/v1/me/follows/venue/'.$approvato->id)->assertNotFound();
    expect(Follow::query()->where('user_id', $altra->id)->count())->toBe(1)
        ->and(Follow::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and($this->user->fresh()->content_preferences['hidden_venues'] ?? [])->toBe([]);
});
