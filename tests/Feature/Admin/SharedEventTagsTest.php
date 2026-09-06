<?php

declare(strict_types=1);

use App\Actions\CreateSharedEventTag;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->venue = Venue::factory()->approved()->create();
    $this->owner->venues()->attach($this->venue, ['role' => 'owner']);
});

it('creates globally reusable approved tags and reuses normalized names', function (): void {
    $action = app(CreateSharedEventTag::class);
    $tag = $action->execute($this->owner, '  Cinema   indipendente ', venue: $this->venue);
    $same = $action->execute($this->owner, 'CINEMA indipendente', venue: $this->venue);
    expect($tag->name)->toBe('Cinema indipendente')->and($tag->is_approved)->toBeTrue()
        ->and($same->id)->toBe($tag->id);
    $otherVenue = Venue::factory()->approved()->create();
    $event = Event::factory()->create(['venue_id' => $otherVenue->id]);
    $event->tags()->attach($tag);
    expect($event->tags->first()->id)->toBe($tag->id);
    expect(Tag::approved()->whereKey($tag->id)->exists())->toBeTrue();
});

it('authorizes both editing an event and creating for the current venue', function (): void {
    $action = app(CreateSharedEventTag::class);
    $event = Event::factory()->create(['venue_id' => $this->venue->id]);
    expect($action->execute($this->owner, 'Jazz', $event)->is_approved)->toBeTrue();
    $foreign = Venue::factory()->approved()->create();
    expect(fn () => $action->execute($this->owner, 'Forbidden', venue: $foreign))->toThrow(AuthorizationException::class);
    $event->update(['venue_id' => $foreign->id]);
    expect(fn () => $action->execute($this->owner, 'Forbidden', $event))->toThrow(AuthorizationException::class);
    $ordinary = User::factory()->create();
    expect(fn () => $action->execute($ordinary, 'Forbidden', venue: $this->venue))->toThrow(AuthorizationException::class);
    expect(Tag::where('name', 'Forbidden')->exists())->toBeFalse();
});

it('does not publish previously hidden tags or accept empty names', function (): void {
    $tag = Tag::factory()->create(['name' => 'Riservato', 'is_approved' => false]);
    $action = app(CreateSharedEventTag::class);
    expect(fn () => $action->execute($this->owner, 'Riservato', venue: $this->venue))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($this->owner, '   ', venue: $this->venue))->toThrow(ValidationException::class);
    expect($tag->fresh()->is_approved)->toBeFalse();
});
