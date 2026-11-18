<?php

declare(strict_types=1);

use App\Enums\FollowableType;
use App\Models\City;
use App\Models\Follow;
use App\Models\SavedSearch;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;

/*
 * Flussi delle ricerche salvate e degli interessi di notifica: una persona
 * dice «avvisami quando esce qualcosa così», ritrova le sue ricerche nella
 * pagina degli interessi, le toglie; e sceglie da un'unica pagina quali
 * categorie e locali seguire per le notifiche, con effetto sul feed.
 */

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory(['slug' => 'musica-dal-vivo']);
    freezeLocal($this->city, '2026-11-12 09:00');
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

it('salva una ricerca dal catalogo, la ritrova negli interessi, riceve l avviso per una data nuova e poi la toglie', function (): void {
    occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', event: ['title' => 'Già in catalogo']);

    $this->actingAs($this->user)->get(route('events.index', ['category' => 'musica-dal-vivo']))->assertOk()->assertSee(__('search.saved.cta'));
    $this->from(route('events.index', ['category' => 'musica-dal-vivo']))
        ->post(route('account.searches.store'), ['name' => 'Concerti a Padova', 'filters' => ['category' => 'musica-dal-vivo', 'date' => 'weekend']])
        ->assertRedirect(route('events.index', ['category' => 'musica-dal-vivo']))
        ->assertSessionHas('status', __('search.saved.stored'));

    $ricerca = SavedSearch::query()->sole();
    // La finestra temporale si scarta: una ricerca salvata guarda sempre avanti.
    expect($ricerca->filters)->toBe(['category' => 'musica-dal-vivo'])
        ->and($ricerca->name)->toBe('Concerti a Padova')
        ->and($ricerca->user_id)->toBe($this->user->id);

    // La stessa ricerca una seconda volta non si duplica.
    $this->post(route('account.searches.store'), ['filters' => ['category' => 'musica-dal-vivo']])->assertSessionHas('status', __('search.saved.exists'));
    expect(SavedSearch::query()->count())->toBe(1);

    $this->get(route('account.notifications.interests'))->assertOk()->assertSee('Concerti a Padova');
    $this->getJson('/api/v1/me/searches')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Concerti a Padova')
        ->assertJsonPath('data.0.url', route('events.index', ['category' => 'musica-dal-vivo']));

    // Ciò che era già pubblicato non fa partire niente; una data nuova sì.
    $this->artisan('searches:notify')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('saved_search_match')->count())->toBe(0);
    freezeLocal($this->city, '2026-11-12 11:00');
    $nuova = occurrenceAtLocal($this->city, $this->category, '2026-11-27 21:00', event: ['title' => 'Appena annunciato']);
    $this->artisan('searches:notify')->assertSuccessful();
    $avviso = ScheduledNotification::query()->ofType('saved_search_match')->sole();
    expect($avviso->user_id)->toBe($this->user->id)
        ->and($avviso->context('occurrence_ids'))->toBe([$nuova->id]);

    $this->from(route('account.notifications.interests'))->delete(route('account.searches.destroy', $ricerca))
        ->assertRedirect(route('account.notifications.interests'))
        ->assertSessionHas('status', __('search.saved.removed'));
    expect(SavedSearch::query()->count())->toBe(0);
    $this->get(route('account.notifications.interests'))->assertOk()->assertDontSee('Concerti a Padova');
});

it('gestisce le ricerche salvate dall app con nome automatico, duplicati, filtri vuoti e ricerche altrui', function (): void {
    $token = $this->user->createToken('Telefono')->plainTextToken;

    $creata = $this->withToken($token)->postJson('/api/v1/me/searches', ['filters' => ['q' => 'jazz', 'price' => 'free']])->assertCreated()
        ->assertJsonPath('data.filters.q', 'jazz')
        ->assertJsonPath('data.filters.price', 'free');
    expect($creata->json('data.name'))->toContain('jazz')
        ->and($creata->json('data.last_checked_at'))->not->toBeNull();

    // Ripetere la stessa ricerca restituisce quella esistente, non una nuova.
    $this->withToken($token)->postJson('/api/v1/me/searches', ['filters' => ['price' => 'free', 'q' => 'jazz']])->assertOk()
        ->assertJsonPath('data.id', $creata->json('data.id'));
    $this->withToken($token)->postJson('/api/v1/me/searches', ['filters' => ['date' => 'today']])->assertUnprocessable()
        ->assertJsonPath('error.fields.filters.0', __('search.saved.empty_filters'));
    $this->withToken($token)->postJson('/api/v1/me/searches', [])->assertUnprocessable();

    $altra = User::factory()->create();
    $altrui = $altra->savedSearches()->create(['city_id' => $this->city->id, 'name' => 'Di qualcun altro', 'filters' => ['q' => 'teatro'], 'last_checked_at' => now()]);
    $this->withToken($token)->getJson('/api/v1/me/searches')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->deleteJson('/api/v1/me/searches/'.$altrui->id)->assertForbidden();
    expect($altrui->fresh())->not->toBeNull();

    $this->withToken($token)->deleteJson('/api/v1/me/searches/'.$creata->json('data.id'))->assertNoContent();
    $this->withToken($token)->getJson('/api/v1/me/searches')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($token)->deleteJson('/api/v1/me/searches/'.$creata->json('data.id'))->assertNotFound();
});

it('sceglie gli interessi di notifica dal sito, li vede nel feed e nell app e li azzera dall app', function (): void {
    $teatro = testCategory(['name' => 'Teatro', 'slug' => 'teatro']);
    $circolo = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo degli interessi']);
    occurrenceAtLocal($this->city, $teatro, '2026-11-14 21:00', event: ['title' => 'Commedia del sabato']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-15 21:00', venue: $circolo, event: ['title' => 'Concerto al circolo']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-16 21:00', event: ['title' => 'Concerto non scelto']);

    $this->actingAs($this->user)->get('/notifiche/interessi')->assertOk()->assertSee('Teatro')->assertSee('Circolo degli interessi');
    $this->patch('/notifiche/interessi', ['categories' => [$teatro->id], 'venues' => [$circolo->id]])
        ->assertRedirect(route('account.notifications.interests'))
        ->assertSessionHas('status', __('notifications.preferences.saved'));

    $follows = Follow::query()->where('user_id', $this->user->id)->orderBy('followable_type')->get();
    expect($follows->map(fn (Follow $f): array => [$f->followable_type, (int) $f->followable_id, (bool) $f->notify])->all())
        ->toBe([['category', $teatro->id, true], ['venue', $circolo->id, true]]);

    // Gli stessi interessi alimentano il feed.
    $this->get('/il-mio-feed')->assertOk()->assertSee('Commedia del sabato')->assertSee('Concerto al circolo')->assertDontSee('Concerto non scelto');

    $api = $this->getJson('/api/v1/me/notification-interests')->assertOk();
    expect(collect($api->json('data.categories'))->firstWhere('id', $teatro->id)['selected'])->toBeTrue()
        ->and(collect($api->json('data.categories'))->firstWhere('id', $this->category->id)['selected'])->toBeFalse()
        ->and(collect($api->json('data.venues'))->firstWhere('id', $circolo->id)['selected'])->toBeTrue();

    // Dall'app toglie il teatro e tiene il circolo.
    $this->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => [$circolo->id]])->assertOk();
    expect($this->user->followedIds(FollowableType::Category))->toBe([])
        ->and($this->user->followedIds(FollowableType::Venue))->toBe([$circolo->id]);
    expect($this->getJson('/api/v1/me/feed')->assertOk()->json('data.*.title'))->toBe(['Concerto al circolo']);

    $this->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => []])->assertOk();
    expect(Follow::query()->where('user_id', $this->user->id)->count())->toBe(0);
    $this->get('/il-mio-feed')->assertOk()->assertSee(__('account.feed.onboarding_title'));
});

it('rifiuta interessi e ricerche a ospiti, locali di altre città o non approvati, e non tocca i follow altrui', function (): void {
    $this->get('/notifiche/interessi')->assertRedirect(route('login'));
    $this->patch('/notifiche/interessi', ['categories' => [], 'venues' => []])->assertRedirect(route('login'));
    $this->getJson('/api/v1/me/notification-interests')->assertUnauthorized();
    $this->post(route('account.searches.store'), ['filters' => ['q' => 'jazz']])->assertRedirect(route('login'));
    $this->getJson('/api/v1/me/searches')->assertUnauthorized();

    $vicenza = City::factory()->padova()->create(['slug' => 'vicenza', 'name' => 'Vicenza']);
    $straniero = Venue::factory()->approved()->create(['city_id' => $vicenza->id]);
    $bozza = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $altra = User::factory()->create();
    $altra->follows()->create(['followable_type' => 'category', 'followable_id' => $this->category->id, 'notify' => true]);

    $this->actingAs($this->user);
    $this->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => [$straniero->id]])->assertUnprocessable();
    $this->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => [$bozza->id]])->assertUnprocessable();
    $this->patchJson('/api/v1/me/notification-interests', ['categories' => [999999], 'venues' => []])->assertUnprocessable();
    $this->patchJson('/api/v1/me/notification-interests', ['venues' => []])->assertUnprocessable();
    $this->from('/notifiche/interessi')->patch('/notifiche/interessi', ['categories' => [$this->category->id, $this->category->id]])
        ->assertRedirect('/notifiche/interessi')->assertSessionHasErrors('categories.0');
    expect(Follow::query()->where('user_id', $this->user->id)->count())->toBe(0);

    // Azzerare i propri interessi non tocca quelli di un'altra persona.
    $this->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => []])->assertOk();
    expect(Follow::query()->where('user_id', $altra->id)->count())->toBe(1);
});
