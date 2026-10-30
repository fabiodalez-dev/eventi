<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\VenueStatus;
use App\Models\Event;
use App\Models\Follow;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Notification;

/**
 * Seguire per id non deve diventare un modo per leggere titolo e slug di ciò
 * che il pubblico non vede: bozze, proposte in moderazione, eventi rifiutati,
 * locali non approvati, tag non approvati, categorie spente.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Telefono')->plainTextToken;
});

it('non segue dall API un evento che il pubblico non vede', function (string $status): void {
    $event = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'status' => $status,
        'title' => 'Titolo sotto embargo',
    ]);

    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'event', 'id' => $event->getKey(), 'notify' => false])
        ->assertNotFound()
        ->assertDontSee('Titolo sotto embargo');

    expect(Follow::query()->where('user_id', $this->user->getKey())->exists())->toBeFalse();
})->with([
    EventStatus::Draft->value,
    EventStatus::Pending->value,
    EventStatus::Rejected->value,
    EventStatus::Cancelled->value,
]);

it('non segue dall API locali, tag e categorie non pubblici', function (): void {
    foreach ([VenueStatus::Draft, VenueStatus::Pending, VenueStatus::Rejected] as $status) {
        $venue = Venue::factory()->create(['city_id' => $this->city->getKey(), 'name' => 'Locale nascosto', 'status' => $status]);

        $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => $venue->getKey()])
            ->assertNotFound()
            ->assertDontSee('Locale nascosto');
    }

    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'tag', 'id' => Tag::factory()->create()->getKey()])
        ->assertNotFound();
    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'category', 'id' => testCategory(['is_active' => false])->getKey()])
        ->assertNotFound();

    expect(Follow::query()->where('user_id', $this->user->getKey())->exists())->toBeFalse();
});

it('continua a seguire eventi pubblicati e archiviati e locali approvati', function (): void {
    $published = Event::factory()->published()->create(['city_id' => $this->city->getKey(), 'category_id' => $this->category->getKey(), 'title' => 'Serata pubblica']);
    $archived = Event::factory()->archived()->create(['city_id' => $this->city->getKey(), 'category_id' => $this->category->getKey()]);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'event', 'id' => $published->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Serata pubblica');
    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'event', 'id' => $archived->getKey()])->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'venue', 'id' => $venue->getKey()])->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'tag', 'id' => Tag::factory()->approved()->create()->getKey()])->assertCreated();
});

it('non mostra nome e slug di un soggetto tornato in bozza dopo il follow', function (): void {
    $event = Event::factory()->published()->create(['city_id' => $this->city->getKey(), 'category_id' => $this->category->getKey(), 'title' => 'Poi ritirato']);

    $this->withToken($this->token)->postJson('/api/v1/me/follows', ['type' => 'event', 'id' => $event->getKey()])->assertCreated();

    $event->forceFill(['status' => EventStatus::Draft])->save();

    $this->withToken($this->token)->getJson('/api/v1/me/follows')
        ->assertOk()
        ->assertJsonPath('data.0.type', FollowableType::Event->value)
        ->assertJsonPath('data.0.id', (int) $event->getKey())
        ->assertJsonPath('data.0.name', null)
        ->assertJsonPath('data.0.slug', null);
});

it('non segue dal sito un evento in bozza', function (): void {
    $event = Event::factory()->create(['city_id' => $this->city->getKey(), 'category_id' => $this->category->getKey(), 'status' => EventStatus::Draft]);
    $venue = Venue::factory()->pending()->create(['city_id' => $this->city->getKey()]);

    $this->actingAs($this->user)->post('/segui', ['type' => 'event', 'id' => $event->getKey()])->assertNotFound();
    $this->actingAs($this->user)->post('/segui', ['type' => 'venue', 'id' => $venue->getKey()])->assertNotFound();

    expect(Follow::query()->where('user_id', $this->user->getKey())->exists())->toBeFalse();
});
