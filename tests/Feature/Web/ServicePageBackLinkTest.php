<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Da ogni pagina di servizio si torna indietro: le pagine dei passaggi
 * hanno le schede per muoversi fra loro, ma senza il ritorno al profilo
 * chi ci arrivava restava chiuso dentro la sezione.
 */

it('mette il ritorno al profilo in tutte le pagine dei passaggi', function (string $route): void {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route($route))
        ->assertOk()
        ->assertSeeHtml('data-profile-back')
        ->assertSeeHtml('href="'.route('account.profile').'"');
})->with(['carpool.index', 'carpool.chats', 'carpool.cases', 'carpool.requirements', 'carpool.terms']);
