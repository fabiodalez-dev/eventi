<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    testCity();
    config(['app.debug' => false]);

    Route::middleware('web')->post('/_prova/conflitto', fn () => abort(409, 'Il posto è già stato preso da qualcun altro.'));
    Route::middleware('web')->post('/_prova/rifiutato', fn () => abort(422));
    Route::middleware('web')->get('/_prova/conflitto', fn () => abort(409, 'Conflitto in lettura.'));
});

it('riporta un modulo HTML al punto di partenza con il messaggio e i dati', function (): void {
    $this->post('/_prova/conflitto', ['nome' => 'Giulia', 'password' => 'segreta'], ['referer' => url('/proponi-evento')])
        ->assertRedirect(url('/proponi-evento'))
        ->assertSessionHasErrors(['form' => 'Il posto è già stato preso da qualcun altro.'])
        ->assertSessionHasInput('nome', 'Giulia')
        ->assertSessionMissing('_old_input.password');
});

it('usa un messaggio predefinito quando l\'errore non ne porta uno', function (): void {
    $this->post('/_prova/rifiutato', [], ['referer' => url('/proponi-evento')])
        ->assertRedirect(url('/proponi-evento'))
        ->assertSessionHasErrors(['form' => __('errors.form.unprocessable')]);
});

it('non segue un Referer esterno', function (): void {
    $this->post('/_prova/conflitto', [], ['referer' => 'https://altro-sito.test/modulo'])
        ->assertRedirect(url('/'));
    $this->post('/_prova/conflitto')->assertRedirect(url('/'));
});

it('lascia il 409 alle richieste JSON', function (): void {
    $this->postJson('/_prova/conflitto', [], ['referer' => url('/proponi-evento')])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Il posto è già stato preso da qualcun altro.');
});

it('non intercetta le richieste di Livewire', function (): void {
    $this->post('/_prova/conflitto', [], ['referer' => url('/proponi-evento'), 'X-Livewire' => 'true'])
        ->assertStatus(409);
});

it('non intercetta le letture', function (): void {
    $this->get('/_prova/conflitto', ['referer' => url('/proponi-evento')])
        ->assertStatus(409)
        ->assertSee('data-error-page="409"', false);
});
