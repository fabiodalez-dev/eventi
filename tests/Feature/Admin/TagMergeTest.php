<?php

declare(strict_types=1);

use App\Actions\MergeTagsAction;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Tags\Pages\ListTags;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * §9.2 — «Tag, con merge dei duplicati».
 */
function eventForTags(): Event
{
    $city = City::factory()->padova()->create();
    $category = Category::factory()->create();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    return Event::factory()->create([
        'city_id' => $city->getKey(),
        'category_id' => $category->getKey(),
        'venue_id' => $venue->getKey(),
    ]);
}

it('sposta gli eventi sul tag che resta e cancella il doppione', function (): void {
    $keep = Tag::factory()->create(['name' => 'Musica dal vivo', 'synonyms' => null]);
    $absorbed = Tag::factory()->create(['name' => 'Live music']);

    $event = eventForTags();
    $event->tags()->attach($absorbed);

    $moved = app(MergeTagsAction::class)->execute($keep, $absorbed);

    expect($moved)->toBe(1)
        ->and(Tag::query()->whereKey($absorbed->getKey())->exists())->toBeFalse()
        ->and($event->refresh()->tags->pluck('id')->all())->toBe([$keep->getKey()])
        ->and($keep->refresh()->synonyms)->toContain('Live music')
        ->and($keep->usage_count)->toBe(1);
});

it('non fallisce quando un evento porta entrambi i tag', function (): void {
    // La pivot ha chiave composta: un UPDATE cieco violerebbe il vincolo.
    $keep = Tag::factory()->create(['name' => 'Teatro']);
    $absorbed = Tag::factory()->create(['name' => 'Teatro contemporaneo']);

    $event = eventForTags();
    $event->tags()->attach([$keep->getKey(), $absorbed->getKey()]);

    $moved = app(MergeTagsAction::class)->execute($keep, $absorbed);

    expect($moved)->toBe(0)
        ->and($event->refresh()->tags->pluck('id')->all())->toBe([$keep->getKey()])
        ->and(Tag::query()->whereKey($absorbed->getKey())->exists())->toBeFalse();
});

it('non fa nulla se il tag da assorbire è quello stesso', function (): void {
    $tag = Tag::factory()->create();

    expect(app(MergeTagsAction::class)->execute($tag, $tag))->toBe(0)
        ->and(Tag::query()->whereKey($tag->getKey())->exists())->toBeTrue();
});

it('espone l\'unione come azione del pannello', function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $this->actingAs($admin);

    $keep = Tag::factory()->create(['name' => 'Concerti']);
    $absorbed = Tag::factory()->create(['name' => 'concerto']);

    Livewire::test(ListTags::class)
        ->callTableAction('merge', $keep, ['absorbed' => $absorbed->getKey()])
        ->assertHasNoTableActionErrors();

    expect(Tag::query()->whereKey($absorbed->getKey())->exists())->toBeFalse();
});
