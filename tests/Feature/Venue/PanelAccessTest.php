<?php

declare(strict_types=1);

use App\Filament\Venue\Pages\Collaborators;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

/**
 * §10 e §18 scenario F: chi entra in `/gestione`, e soprattutto **dove non
 * riesce a entrare nemmeno riscrivendo l'indirizzo**.
 *
 * È il test che il piano dichiara non negoziabile: la tenancy di Filament
 * nasconde le righe altrui, ma la domanda vera è se un locale possa
 * raggiungere i dati di un altro forzando la mano all'URL.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
});

function venueUrl(string $path = ''): string
{
    return '/gestione/'.test()->scenario->venueA->slug.$path;
}

it('manda chi non è entrato alla pagina di accesso', function (): void {
    $this->get(venueUrl())->assertRedirect('/gestione/login');
});

it('chiude il pannello a chi non gestisce alcun locale', function (): void {
    $this->actingAs($this->scenario->plainUser)
        ->get(venueUrl())
        ->assertForbidden();
});

it('chiude il pannello anche alla redazione, che ha il suo', function (User $user): void {
    // Amministratori e moderatori non hanno righe in `venue_user`: il loro
    // pannello è /admin. Qui non entrano, e non è una dimenticanza.
    $this->actingAs($user)->get(venueUrl())->assertForbidden();
})->with([
    'moderatore' => fn () => test()->scenario->moderator,
    'amministratore' => fn () => test()->scenario->admin,
]);

it('apre il pannello a chi gestisce il locale', function (User $user): void {
    $this->actingAs($user)->get(venueUrl())->assertOk();
})->with([
    'referente' => fn () => test()->scenario->ownerA,
    'collaboratore' => fn () => test()->scenario->editorA,
]);

it('apre le pagine del locale a chi lo gestisce', function (string $path): void {
    $this->actingAs($this->scenario->ownerA)->get(venueUrl($path))->assertOk();
})->with([
    'eventi' => '/eventi',
    'nuovo evento' => '/eventi/nuovo',
    'statistiche' => '/statistiche',
    'collaboratori' => '/collaboratori',
]);

/*
 * ------------------------------------------------------------------ isolamento
 */

it('risponde 404 a chi scrive nell’indirizzo il locale di un altro', function (string $path): void {
    $other = $this->scenario->venueB->slug;

    $this->actingAs($this->scenario->ownerA)
        ->get('/gestione/'.$other.$path)
        ->assertNotFound();
})->with([
    'riepilogo' => '',
    'eventi' => '/eventi',
    'statistiche' => '/statistiche',
    'collaboratori' => '/collaboratori',
]);

it('risponde 404 a chi apre, dal proprio locale, l’evento di un altro', function (): void {
    // L'indirizzo è formalmente valido: locale proprio, evento altrui. È
    // esattamente il caso in cui la sola tenancy dell'URL non basterebbe.
    //
    // La stessa richiesta sul proprio evento risponde 200: senza questo
    // controllo il 404 potrebbe arrivare da un indirizzo sbagliato invece che
    // dall'isolamento, e il test direbbe di sorvegliare qualcosa che non
    // sorveglia.
    $this->actingAs($this->scenario->ownerA)
        ->get(venueUrl('/eventi/'.$this->scenario->publishedEventA->getRouteKey().'/modifica'))
        ->assertOk();

    $this->actingAs($this->scenario->ownerA)
        ->get(venueUrl('/eventi/'.$this->scenario->publishedEventB->getRouteKey().'/modifica'))
        ->assertNotFound();

    $this->actingAs($this->scenario->ownerA)
        ->get(venueUrl('/eventi/'.$this->scenario->draftEventB->getRouteKey().'/modifica'))
        ->assertNotFound();
});

it('non mostra nell’elenco gli eventi di un altro locale', function (): void {
    $response = $this->actingAs($this->scenario->ownerA)->get(venueUrl('/eventi'));

    $response->assertOk();
    $response->assertSee($this->scenario->publishedEventA->title);
    $response->assertSee($this->scenario->draftEventA->title);
    $response->assertDontSee($this->scenario->publishedEventB->title);
    $response->assertDontSee($this->scenario->draftEventB->title);
});

/*
 * -------------------------------------------------------------- collaboratori
 */

it('nasconde i collaboratori a chi è solo collaboratore', function (): void {
    $this->actingAs($this->scenario->editorA);

    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);

    expect(Collaborators::canAccess())->toBeFalse();

    $this->get(venueUrl('/collaboratori'))->assertForbidden();
});

it('non mostra al collaboratore l’indirizzo email del referente', function (): void {
    // §3: «l'editor non può toccare dati sensibili del locale, proprietari,
    // inviti». L'email del referente è il dato che quella pagina espone.
    $response = $this->actingAs($this->scenario->editorA)->get(venueUrl());

    $response->assertOk();
    $response->assertDontSee($this->scenario->ownerA->email);
});
