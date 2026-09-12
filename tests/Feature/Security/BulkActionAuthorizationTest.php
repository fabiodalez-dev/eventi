<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Categories\Pages\ListCategories;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Venues\Pages\ListVenues;
use App\Models\Category;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Users without delete permission cannot obtain it through table bulk actions. */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->moderatore = User::factory()->create();
    $this->moderatore->assignRole(UserRole::Moderator->value);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
});

it('nega al moderatore la cancellazione in blocco degli utenti, che pure vede', function (): void {
    $bersagli = User::factory()->count(3)->create();
    $this->actingAs($this->moderatore);

    /* Il moderatore la tabella la vede: è esattamente da qui che nasceva il
       difetto. Se un giorno `viewAny` cambiasse, questa riga lo direbbe. */
    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$bersagli->first()])
        ->callTableBulkAction('delete', $bersagli->all());

    foreach ($bersagli as $bersaglio) {
        expect(User::query()->whereKey($bersaglio->getKey())->exists())->toBeTrue();
    }
});

it('nega al moderatore la cancellazione in blocco di locali e categorie', function (): void {
    $locali = Venue::factory()->count(2)->approved()->create();
    $categorie = Category::factory()->count(2)->create();
    $this->actingAs($this->moderatore);

    Livewire::test(ListVenues::class)->callTableBulkAction('delete', $locali->all());
    Livewire::test(ListCategories::class)->callTableBulkAction('delete', $categorie->all());

    expect(Venue::query()->whereKey($locali->modelKeys())->count())->toBe(2)
        ->and(Category::query()->whereKey($categorie->modelKeys())->count())->toBe(2);
});

/*
 * Il contrappeso: la correzione non deve togliere il lavoro a chi il permesso
 * ce l'ha. Una difesa che blocca anche gli autorizzati viene disattivata al
 * primo reclamo, e allora non difende più niente.
 */
it('lascia cancellare in blocco a chi ha il permesso', function (): void {
    $categorie = Category::factory()->count(2)->create();
    $this->actingAs($this->admin);

    Livewire::test(ListCategories::class)->callTableBulkAction('delete', $categorie->all());

    expect(Category::query()->whereKey($categorie->modelKeys())->count())->toBe(0);
});
