<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Venue\Pages\Collaborators;
use App\Models\User;
use App\Notifications\VenueAccessGranted;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * §10.6 — l'invito di un collaboratore, e la clausola di §3 che gli impedisce
 * di vedere questa pagina.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;

    Notification::fake();

    $this->actingAs($this->scenario->ownerA);

    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

it('crea l’account, il ruolo e l’appartenenza con un solo invito', function (): void {
    Livewire::test(Collaborators::class)->callTableAction('invite', data: [
        'name' => 'Marta Bianchi',
        'email' => 'MARTA@esempio.test',
    ]);

    $invited = User::query()->where('email', 'marta@esempio.test')->sole();

    expect($invited->name)->toBe('Marta Bianchi')
        ->and($invited->hasRole(UserRole::VenueEditor->value))->toBeTrue()
        ->and($this->venue->editors()->whereKey($invited->getKey())->exists())->toBeTrue()
        ->and($invited->venues()->whereKey($this->scenario->venueB->getKey())->exists())->toBeFalse();

    Notification::assertSentTo($invited, VenueAccessGranted::class);
});

it('non crea un secondo account per chi è già registrato', function (): void {
    $existing = $this->scenario->plainUser;

    Livewire::test(Collaborators::class)->callTableAction('invite', data: [
        'name' => 'Nome diverso',
        'email' => $existing->email,
    ]);

    expect(User::query()->where('email', $existing->email)->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe($existing->name)
        ->and($this->venue->editors()->whereKey($existing->getKey())->exists())->toBeTrue();
});

it('non declassa il ruolo globale di chi era già qualcosa d’altro', function (): void {
    $moderator = $this->scenario->moderator;

    Livewire::test(Collaborators::class)->callTableAction('invite', data: [
        'name' => $moderator->name,
        'email' => $moderator->email,
    ]);

    expect($moderator->fresh()->hasRole(UserRole::Moderator->value))->toBeTrue();
});

it('mostra al referente le persone del solo locale corrente', function (): void {
    Livewire::test(Collaborators::class)
        ->assertSee($this->scenario->ownerA->email)
        ->assertSee($this->scenario->editorA->email)
        ->assertDontSee($this->scenario->ownerB->email);
});

it('non lascia togliere un referente', function (): void {
    Livewire::test(Collaborators::class)
        ->assertTableActionHidden('remove', $this->scenario->ownerA)
        ->assertTableActionVisible('remove', $this->scenario->editorA);
});

it('toglie un collaboratore senza cancellarne l’account', function (): void {
    Livewire::test(Collaborators::class)->callTableAction('remove', $this->scenario->editorA);

    expect($this->venue->members()->whereKey($this->scenario->editorA->getKey())->exists())->toBeFalse()
        ->and(User::query()->whereKey($this->scenario->editorA->getKey())->exists())->toBeTrue();
});

it('chiude la pagina a un collaboratore', function (): void {
    $this->actingAs($this->scenario->editorA);

    expect(Collaborators::canAccess())->toBeFalse();
});
