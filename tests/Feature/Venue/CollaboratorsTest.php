<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\VenueRole;
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

/*
 * Il messaggio si COMPONE davvero, non si limita a partire.
 *
 * Gli altri test di questo file usano `Notification::fake()`, che registra
 * l'invio senza costruire il testo: un errore dentro `toMail()` non li tocca.
 * E' cosi' che e' passata inosservata una chiamata a `createToken()` sul
 * contratto `PasswordBroker`, che quel metodo non dichiara — funzionava a
 * runtime per via dell'implementazione concreta, e nessuna prova la
 * attraversava.
 */
it('compone l\'invito con il collegamento per scegliere la password', function (): void {
    $invitato = User::factory()->create(['email' => 'nuovo@collaboratore.test']);

    $messaggio = (new VenueAccessGranted($this->venue, VenueRole::Editor, needsPassword: true))
        ->toMail($invitato);

    $reso = $messaggio->render()->toHtml();

    expect($reso)
        ->toContain(__('manage.invitation.password_action'))
        /* Il gettone finisce nell'indirizzo: se `createToken()` non fosse
           stato chiamato, il collegamento porterebbe a un modulo che rifiuta
           chiunque. */
        ->toMatch('/password-reset|reimposta|reset/i');
});

it('compone l\'invito senza collegamento per chi ha gia\' un account', function (): void {
    $esistente = User::factory()->create();

    $messaggio = (new VenueAccessGranted($this->venue, VenueRole::Editor, needsPassword: false))
        ->toMail($esistente);

    expect($messaggio->render()->toHtml())
        ->toContain(__('manage.invitation.open_action'))
        ->not->toContain(__('manage.invitation.password_action'));
});
