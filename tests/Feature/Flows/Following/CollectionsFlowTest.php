<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\EventCollection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Flussi delle raccolte: una persona mette insieme alcune serate, le annota,
 * decide se renderle visibili con un collegamento e alla fine le richiude.
 * Lo stesso percorso dal sito e dall'app, e i rifiuti verso chi non è il
 * proprietario.
 */

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->user = User::factory()->create(['name' => 'Giulia Rossi']);
    $this->venerdi = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', event: ['title' => 'Venerdì al circolo']);
    $this->sabato = occurrenceAtLocal($this->city, $this->category, '2026-11-21 22:00', event: ['title' => 'Sabato in piazza']);
});

afterEach(fn () => Carbon::setTestNow());

it('crea una raccolta dal sito, la riempie, la rinomina rendendola pubblica, la condivide e la cancella', function (): void {
    $this->actingAs($this->user)->get(route('events.show', $this->venerdi->event))->assertOk()->assertSee(__('collections.add'));

    $this->post(route('account.collections.store'), ['name' => 'Weekend con gli ospiti', 'occurrence_id' => $this->venerdi->id])
        ->assertSessionHas('status', __('collections.created'));
    $raccolta = EventCollection::query()->sole();
    expect($raccolta->user_id)->toBe($this->user->id)
        ->and($raccolta->is_public)->toBeFalse()
        ->and($raccolta->items()->pluck('occurrence_id')->all())->toBe([$this->venerdi->id]);

    $this->from(route('events.show', $this->sabato->event))
        ->post(route('account.collections.items.store', $raccolta), ['occurrence_id' => $this->sabato->id, 'note' => 'Portare la giacca'])
        ->assertRedirect(route('events.show', $this->sabato->event))
        ->assertSessionHas('status', __('collections.item_added', ['name' => 'Weekend con gli ospiti']));
    expect($raccolta->items()->pluck('occurrence_id')->all())->toBe([$this->venerdi->id, $this->sabato->id]);

    $this->get(route('account.collections.index'))->assertOk()->assertSee('Weekend con gli ospiti');
    $this->get(route('account.collections.show', $raccolta))->assertOk()->assertSee('Venerdì al circolo')->assertSee('Sabato in piazza');

    // Privata: il collegamento risponde solo al proprietario.
    $this->get(route('collections.show', $raccolta->token))->assertOk()->assertSee('Sabato in piazza');
    auth()->logout();
    $this->get(route('collections.show', $raccolta->token))->assertNotFound();
    $this->getJson('/api/v1/collections/'.$raccolta->token)->assertNotFound();

    $this->actingAs($this->user)->from(route('account.collections.show', $raccolta))
        ->patch(route('account.collections.update', $raccolta), ['name' => 'Weekend a Padova', 'description' => 'Due serate in centro', 'is_public' => 1])
        ->assertRedirect(route('account.collections.show', $raccolta))
        ->assertSessionHas('status', __('collections.updated'));
    expect($raccolta->fresh()->name)->toBe('Weekend a Padova')->and($raccolta->fresh()->is_public)->toBeTrue();

    auth()->logout();
    $this->get(route('collections.show', $raccolta->token))->assertOk()
        ->assertSee('Weekend a Padova')->assertSee('Due serate in centro')->assertSee('Portare la giacca')
        ->assertDontSee(__('collections.manage'));
    $this->getJson('/api/v1/collections/'.$raccolta->token)->assertOk()
        ->assertJsonPath('data.name', 'Weekend a Padova')
        ->assertJsonPath('data.items.0.title', 'Venerdì al circolo')
        ->assertJsonPath('data.items.1.note', 'Portare la giacca');

    // Toglie una data: la pagina pubblica si aggiorna.
    $this->actingAs($this->user)->delete(route('account.collections.items.destroy', [$raccolta, $this->venerdi->id]))
        ->assertSessionHas('status', __('collections.item_removed'));
    auth()->logout();
    $this->get(route('collections.show', $raccolta->token))->assertOk()->assertDontSee('Venerdì al circolo')->assertSee('Sabato in piazza');

    $this->actingAs($this->user)->delete(route('account.collections.destroy', $raccolta))
        ->assertRedirect(route('account.collections.index'))->assertSessionHas('status', __('collections.deleted'));
    expect(EventCollection::query()->count())->toBe(0)
        ->and(DB::table('event_collection_items')->count())->toBe(0);
    auth()->logout();
    $this->get(route('collections.show', $raccolta->token))->assertNotFound();
});

it('gestisce una raccolta dall app: crea, aggiunge, legge, rende pubblica, toglie e cancella', function (): void {
    $token = $this->user->createToken('Telefono')->plainTextToken;

    $creata = $this->withToken($token)->postJson('/api/v1/me/collections', ['name' => 'Dal telefono', 'occurrence_id' => $this->sabato->id])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Dal telefono')
        ->assertJsonPath('data.is_public', false)
        ->assertJsonPath('data.public_url', null)
        ->assertJsonPath('data.items_count', 1);
    $id = $creata->json('data.id');

    $this->withToken($token)->postJson('/api/v1/me/collections/'.$id.'/items', ['occurrence_id' => $this->venerdi->id, 'note' => 'Prima la cena'])
        ->assertCreated()->assertJsonPath('data.items_count', 2);
    // La stessa data due volte resta una sola.
    $this->withToken($token)->postJson('/api/v1/me/collections/'.$id.'/items', ['occurrence_id' => $this->venerdi->id])
        ->assertCreated()->assertJsonPath('data.items_count', 2);

    $this->withToken($token)->getJson('/api/v1/me/collections/'.$id)->assertOk()
        ->assertJsonPath('data.items.0.title', 'Sabato in piazza')
        ->assertJsonPath('data.items.1.title', 'Venerdì al circolo')
        ->assertJsonPath('data.items.1.note', 'Prima la cena');
    $this->withToken($token)->getJson('/api/v1/me/collections')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.items_count', 2);

    $raccolta = EventCollection::query()->findOrFail($id);
    // Chi apre il collegamento senza essere il proprietario non vede una raccolta privata.
    $this->app['auth']->forgetGuards();
    $this->withoutToken()->getJson('/api/v1/collections/'.$raccolta->token)->assertNotFound();

    $this->withToken($token)->patchJson('/api/v1/me/collections/'.$id, ['name' => 'Da condividere', 'is_public' => true])->assertOk()
        ->assertJsonPath('data.is_public', true)
        ->assertJsonPath('data.public_url', $raccolta->publicUrl());

    $this->app['auth']->forgetGuards();
    $pubblica = $this->withoutToken()->getJson('/api/v1/collections/'.$raccolta->token)->assertOk()->assertJsonPath('data.name', 'Da condividere');
    expect($pubblica->json('data.items.*.occurrence_id'))->toBe([$this->sabato->id, $this->venerdi->id]);

    $this->withToken($token)->deleteJson('/api/v1/me/collections/'.$id.'/items/'.$this->sabato->id)->assertNoContent();
    $this->app['auth']->forgetGuards();
    expect($this->withoutToken()->getJson('/api/v1/collections/'.$raccolta->token)->json('data.items.*.occurrence_id'))->toBe([$this->venerdi->id]);

    $this->withToken($token)->deleteJson('/api/v1/me/collections/'.$id)->assertNoContent();
    $this->withToken($token)->getJson('/api/v1/me/collections')->assertOk()->assertJsonCount(0, 'data');
    $this->app['auth']->forgetGuards();
    $this->withoutToken()->getJson('/api/v1/collections/'.$raccolta->token)->assertNotFound();
});

it('non lascia leggere né toccare la raccolta di un altro, sul sito e nell app', function (): void {
    $raccolta = $this->user->eventCollections()->create(['city_id' => $this->city->id, 'name' => 'Solo mia', 'is_public' => false]);
    $raccolta->items()->create(['occurrence_id' => $this->venerdi->id, 'position' => 1]);
    $intruso = User::factory()->create();

    $this->actingAs($intruso);
    $this->get(route('account.collections.show', $raccolta))->assertForbidden();
    $this->patch(route('account.collections.update', $raccolta), ['name' => 'Rubata', 'is_public' => 1])->assertForbidden();
    $this->post(route('account.collections.items.store', $raccolta), ['occurrence_id' => $this->sabato->id])->assertForbidden();
    $this->delete(route('account.collections.items.destroy', [$raccolta, $this->venerdi->id]))->assertForbidden();
    $this->delete(route('account.collections.destroy', $raccolta))->assertForbidden();
    // Anche conoscendo il token, una raccolta privata non si apre.
    $this->get(route('collections.show', $raccolta->token))->assertNotFound();

    $this->getJson('/api/v1/me/collections/'.$raccolta->id)->assertForbidden();
    $this->patchJson('/api/v1/me/collections/'.$raccolta->id, ['name' => 'Rubata'])->assertForbidden();
    $this->deleteJson('/api/v1/me/collections/'.$raccolta->id)->assertForbidden();
    $this->getJson('/api/v1/me/collections')->assertOk()->assertJsonCount(0, 'data');
    $this->get(route('account.collections.index'))->assertOk()->assertDontSee('Solo mia');

    $raccolta->refresh();
    expect($raccolta->name)->toBe('Solo mia')
        ->and($raccolta->is_public)->toBeFalse()
        ->and($raccolta->items()->count())->toBe(1);

    // Raccolta inesistente e token malformato.
    $this->getJson('/api/v1/me/collections/999999')->assertNotFound();
    $this->get('/raccolte/'.str_repeat('a', 24))->assertNotFound();
    $this->get('/raccolte/corto')->assertNotFound();
});

it('valida nomi e date delle raccolte, chiede l accesso agli ospiti e rispetta il tetto per persona', function (): void {
    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-11-22 21:00', event: ['status' => EventStatus::Draft, 'published_at' => null]);

    $this->get(route('account.collections.index'))->assertRedirect(route('login'));
    $this->post(route('account.collections.store'), ['name' => 'Ospite'])->assertRedirect(route('login'));
    $this->getJson('/api/v1/me/collections')->assertUnauthorized();
    expect(EventCollection::query()->count())->toBe(0);

    $this->actingAs($this->user);
    $this->post(route('account.collections.store'), ['name' => ''])->assertSessionHasErrors('name');
    $this->post(route('account.collections.store'), ['name' => '   '])->assertSessionHasErrors('name');
    $this->postJson('/api/v1/me/collections', ['name' => str_repeat('x', 300)])->assertUnprocessable()->assertJsonValidationErrors('name', 'error.fields');
    expect(EventCollection::query()->count())->toBe(0);

    $raccolta = $this->user->eventCollections()->create(['city_id' => $this->city->id, 'name' => 'Valida']);
    $this->post(route('account.collections.items.store', $raccolta), ['occurrence_id' => 999999])->assertSessionHasErrors('occurrence_id');
    $this->postJson('/api/v1/me/collections/'.$raccolta->id.'/items', ['occurrence_id' => $bozza->id])->assertUnprocessable()->assertJsonValidationErrors('occurrence_id', 'error.fields');
    $this->postJson('/api/v1/me/collections/'.$raccolta->id.'/items', [])->assertUnprocessable();
    expect($raccolta->items()->count())->toBe(0);

    // Il tetto: venti raccolte per persona, poi un rifiuto sul sito e nell'app.
    for ($i = 2; $i <= EventCollection::MAX_PER_USER; $i++) {
        $this->postJson('/api/v1/me/collections', ['name' => 'Raccolta '.$i])->assertCreated();
    }
    $this->postJson('/api/v1/me/collections', ['name' => 'Una di troppo'])->assertUnprocessable()
        ->assertJsonPath('error.fields.name.0', __('collections.limit', ['max' => EventCollection::MAX_PER_USER]));
    $this->post(route('account.collections.store'), ['name' => 'Dal sito, di troppo'])->assertSessionHasErrors('name');

    // Il tetto è per persona: un'altra persona ne crea liberamente.
    $this->actingAs(User::factory()->create())->postJson('/api/v1/me/collections', ['name' => 'La mia prima'])->assertCreated();

    // Cancellandone una si libera il posto.
    $this->actingAs($this->user)->delete(route('account.collections.destroy', $raccolta))->assertRedirect(route('account.collections.index'));
    $this->postJson('/api/v1/me/collections', ['name' => 'Al posto della prima'])->assertCreated();
    expect(EventCollection::query()->where('user_id', $this->user->id)->count())->toBe(EventCollection::MAX_PER_USER)
        ->and($this->getJson('/api/v1/me/collections')->json('data.0.name'))->toBe('Al posto della prima');
});

it('non lascia dietro di sé una raccolta quando la creazione viene rifiutata per una data non valida', function (): void {
    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-11-22 21:00', event: ['status' => EventStatus::Draft, 'published_at' => null]);

    $this->actingAs($this->user);
    // Dall'app: la risposta dice 422, quindi non deve esistere nessuna raccolta.
    $this->postJson('/api/v1/me/collections', ['name' => 'Con bozza', 'occurrence_id' => $bozza->id])
        ->assertUnprocessable()->assertJsonValidationErrors('occurrence_id', 'error.fields');
    // Dal sito: il modulo torna indietro con l'errore.
    $this->from(route('events.show', $this->venerdi->event))
        ->post(route('account.collections.store'), ['name' => 'Data inesistente', 'occurrence_id' => 999999])
        ->assertSessionHasErrors('occurrence_id');

    // Un nuovo tentativo dopo l'errore non deve produrre doppioni né consumare il tetto.
    expect(EventCollection::query()->where('user_id', $this->user->id)->count())->toBe(0);
});
