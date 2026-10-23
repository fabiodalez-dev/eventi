<?php

declare(strict_types=1);

use App\Models\EventCollection;
use App\Models\User;

afterEach(fn () => Carbon\Carbon::setTestNow());

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-10-23 12:00');
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->first = occurrenceAtLocal($this->city, $this->category, '2026-10-30 21:00', event: ['title' => 'Venerdì al circolo']);
    $this->second = occurrenceAtLocal($this->city, $this->category, '2026-10-31 22:00', event: ['title' => 'Sabato in piazza']);
});

it('creates a collection from the event page, fills it, shares it by link and closes it again', function (): void {
    $this->actingAs($this->user)->get(route('events.show', $this->first->event))->assertOk()->assertSee(__('collections.add'));
    $this->actingAs($this->user)->post(route('account.collections.store'), ['name' => 'Weekend con gli ospiti', 'occurrence_id' => $this->first->id])
        ->assertRedirect();
    $collection = EventCollection::query()->sole();
    expect($collection->items()->count())->toBe(1)->and($collection->is_public)->toBeFalse()->and(strlen($collection->token))->toBe(24);

    $this->actingAs($this->user)->post(route('account.collections.items.store', $collection), ['occurrence_id' => $this->second->id, 'note' => 'Portare la giacca'])->assertRedirect();
    $this->actingAs($this->user)->post(route('account.collections.items.store', $collection), ['occurrence_id' => $this->second->id])->assertRedirect();
    expect($collection->items()->count())->toBe(2);
    $this->actingAs($this->user)->post(route('account.collections.items.store', $collection), ['occurrence_id' => 999999])->assertSessionHasErrors('occurrence_id');

    // Private: the link answers 404 to everyone else, even with the right token.
    auth()->logout();
    $this->get(route('collections.show', $collection->token))->assertNotFound();
    $this->actingAs($this->user)->get(route('collections.show', $collection->token))->assertOk()->assertSee('Venerdì al circolo');
    $this->actingAs($this->user)->get(route('account.collections.show', $collection))->assertOk()->assertSee('Sabato in piazza');

    $this->actingAs($this->user)->patch(route('account.collections.update', $collection), ['name' => 'Weekend', 'is_public' => 1])->assertRedirect();
    auth()->logout();
    $this->get(route('collections.show', $collection->token))->assertOk()->assertSee('Weekend')->assertSee('Portare la giacca')->assertSee('Sabato in piazza')
        ->assertDontSee(__('collections.manage'));
    $this->getJson('/api/v1/collections/'.$collection->token)->assertOk()->assertJsonPath('data.items.1.note', 'Portare la giacca');

    $other = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($other)->get(route('account.collections.show', $collection))->assertForbidden();
    $this->actingAs($other)->post(route('account.collections.items.store', $collection), ['occurrence_id' => $this->first->id])->assertForbidden();
    $this->actingAs($other)->delete(route('account.collections.destroy', $collection))->assertForbidden();

    $this->actingAs($this->user)->delete(route('account.collections.items.destroy', [$collection, $this->first->id]))->assertRedirect();
    expect($collection->items()->count())->toBe(1);
    $this->actingAs($this->user)->patch(route('account.collections.update', $collection), ['name' => 'Weekend', 'is_public' => 0])->assertRedirect();
    auth()->logout();
    $this->get(route('collections.show', $collection->token))->assertNotFound();
    $this->actingAs($this->user)->delete(route('account.collections.destroy', $collection))->assertRedirect(route('account.collections.index'));
    expect(EventCollection::query()->count())->toBe(0);
});

it('is available through the API with the same limits', function (): void {
    $this->actingAs($this->user)->postJson('/api/v1/me/collections', ['name' => 'Dal telefono', 'is_public' => true])->assertCreated()->assertJsonPath('data.is_public', true);
    $collection = EventCollection::query()->sole();
    $this->actingAs($this->user)->postJson('/api/v1/me/collections/'.$collection->id.'/items', ['occurrence_id' => $this->first->id])->assertCreated()->assertJsonPath('data.items_count', 1);
    $this->actingAs($this->user)->getJson('/api/v1/me/collections')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_url', $collection->publicUrl());
    $this->actingAs($this->user)->getJson('/api/v1/me/collections/'.$collection->id)->assertOk()->assertJsonPath('data.items.0.title', 'Venerdì al circolo');
    for ($i = 1; $i < EventCollection::MAX_PER_USER; $i++) {
        $this->actingAs($this->user)->postJson('/api/v1/me/collections', ['name' => 'Raccolta '.$i])->assertCreated();
    }
    $this->actingAs($this->user)->postJson('/api/v1/me/collections', ['name' => 'Una di troppo'])->assertUnprocessable();
    $this->actingAs($this->user)->deleteJson('/api/v1/me/collections/'.$collection->id.'/items/'.$this->first->id)->assertNoContent();
    $this->actingAs($this->user)->deleteJson('/api/v1/me/collections/'.$collection->id)->assertNoContent();
});
