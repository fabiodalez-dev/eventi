<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Tags\Pages\ListTags;
use App\Models\Tag;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Flusso: due tag che dicono la stessa cosa. Il pubblico li vede come due
 * pagine distinte; la moderatrice non può unirli, l'amministratrice sì, e dopo
 * l'unione gli eventi stanno tutti sotto il tag che resta, il doppione sparisce
 * dal sito e il suo nome diventa un sinonimo.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('unisce un tag doppione dal pannello e il sito mostra tutti gli eventi sotto quello che resta', function (): void {
    $keep = Tag::factory()->create(['name' => 'Musica dal vivo', 'is_approved' => true, 'synonyms' => null]);
    $absorbed = Tag::factory()->create(['name' => 'Live music', 'is_approved' => true]);

    $soloKeep = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['title' => 'Quartetto in cortile'])->event;
    $soloAbsorbed = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00', event: ['title' => 'Jam session al porto'])->event;
    $entrambi = occurrenceAtLocal($this->city, $this->category, '2026-09-14 21:00', event: ['title' => 'Coro gospel in piazza'])->event;
    $soloKeep->tags()->attach($keep);
    $soloAbsorbed->tags()->attach($absorbed);
    $entrambi->tags()->attach([$keep->getKey(), $absorbed->getKey()]);

    // Prima: due pagine pubbliche, ognuna con i suoi eventi.
    $this->get(route('events.tag', $absorbed))->assertOk()
        ->assertSee('Jam session al porto')->assertDontSee('Quartetto in cortile');
    $this->get(route('events.tag', $keep))->assertOk()
        ->assertSee('Quartetto in cortile')->assertDontSee('Jam session al porto');

    // La moderatrice vede l'elenco ma l'unione non è un suo gesto.
    $moderator = User::factory()->create();
    $moderator->assignRole(UserRole::Moderator->value);
    $this->actingAs($moderator)->get('/admin/tags')->assertOk()->assertSee('Live music');
    Filament::setCurrentPanel('admin');
    Livewire::test(ListTags::class)->assertTableActionHidden('merge', $keep);

    // L'amministratrice unisce «Live music» dentro «Musica dal vivo».
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);
    $this->actingAs($admin)->get('/admin/tags')->assertOk();
    Livewire::test(ListTags::class)
        ->assertTableActionVisible('merge', $keep)
        ->callTableAction('merge', $keep, ['absorbed' => $absorbed->getKey()])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('admin.notifications.merged', ['count' => 1]));

    $keep->refresh();
    expect(Tag::query()->whereKey($absorbed->getKey())->exists())->toBeFalse()
        ->and($soloAbsorbed->fresh()->tags->pluck('id')->all())->toBe([$keep->getKey()])
        ->and($entrambi->fresh()->tags->pluck('id')->all())->toBe([$keep->getKey()])
        ->and($soloKeep->fresh()->tags->pluck('id')->all())->toBe([$keep->getKey()])
        ->and($keep->synonyms)->toContain('Live music')
        ->and($keep->usage_count)->toBe(3);

    // Dopo: una pagina sola con tutti e tre, il doppione non risponde più.
    auth()->logout();
    $this->get(route('events.tag', $keep))->assertOk()
        ->assertSee('Quartetto in cortile')
        ->assertSee('Jam session al porto')
        ->assertSee('Coro gospel in piazza');
    $this->get('/eventi/tag/'.$absorbed->slug)->assertNotFound();
});
