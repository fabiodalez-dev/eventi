<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    testCity();
    config(['app.debug' => false]);
});

it('mostra il 404 in italiano, nel tema del sito e con un pulsante vero', function (): void {
    $this->get('/pagina-che-non-esiste')
        ->assertNotFound()
        ->assertSee('Questa pagina non esiste')
        ->assertSee('Errore 404')
        ->assertSee('data-theme="dark"', false)
        ->assertSee('data-theme-auto', false)
        ->assertSee('Vai agli eventi')
        ->assertSee('ui-action', false)
        ->assertDontSee('Oops')
        ->assertDontSee('Not Found');
});

it('mostra il 405 con il proprio testo invece della pagina di Symfony', function (): void {
    $this->get('/biglietti/1/annulla')
        ->assertStatus(405)
        ->assertSee('Questo indirizzo non si apre così')
        ->assertSee('data-theme', false)
        ->assertDontSee('Oops! An Error Occurred');
});

it('offre il ritorno solo verso lo stesso sito', function (): void {
    $this->get('/pagina-che-non-esiste', ['referer' => url('/eventi')])->assertSee('Torna indietro');
    $this->get('/pagina-che-non-esiste', ['referer' => 'https://esempio.test/x'])->assertDontSee('Torna indietro')->assertDontSee('esempio.test');
});

it('mostra il 403 con la via d\'uscita e il tema scelto nel profilo', function (): void {
    $user = User::factory()->create(['appearance' => 'light']);

    $this->actingAs($user)->get('/admin')
        ->assertForbidden()
        ->assertSee('Non puoi aprire questa pagina')
        ->assertSee('data-theme="light"', false)
        ->assertDontSee('data-theme-auto', false)
        ->assertSee('Vai alla tua area')
        ->assertSee(route('account.logout'), false)
        ->assertSee('Esci e cambia account');
});

it('mostra nel 403 la spiegazione data da chi lo solleva, non il testo predefinito', function (): void {
    Route::middleware('web')->get('/_prova/riservata', fn () => abort(403, 'Quest\'area è riservata alla redazione.'));
    Route::middleware('web')->get('/_prova/vietata', fn () => abort(403));

    $this->get('/_prova/riservata')->assertForbidden()->assertSee('Quest&#039;area è riservata alla redazione.', false);
    $this->get('/_prova/vietata')->assertForbidden()->assertDontSee('This action is unauthorized');
});

it('non mostra mai il messaggio interno di un 500 e non interroga il database', function (): void {
    Route::get('/_prova/rotto', fn () => throw new RuntimeException('dettaglio interno segreto'));

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->get('/_prova/rotto');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertStatus(500)
        ->assertSee('Qualcosa non ha funzionato')
        ->assertSee('Riprova')
        ->assertDontSee('dettaglio interno segreto');

    expect($queries)->toBe([]);
});

it('indica dopo quanto riprovare quando le richieste sono troppe', function (): void {
    Route::get('/_prova/troppe', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 42]));

    $this->get('/_prova/troppe')
        ->assertStatus(429)
        ->assertSee('Troppi tentativi')
        ->assertSee('Puoi riprovare fra 42 secondi.');
});

it('rimanda al modulo quando il file inviato è troppo grande', function (): void {
    Route::get('/_prova/grande', fn () => throw new PostTooLargeException);

    $this->get('/_prova/grande', ['referer' => url('/proponi-evento')])
        ->assertStatus(413)
        ->assertSee('Il file è troppo grande')
        ->assertSee('Torna al modulo')
        ->assertSee(url('/proponi-evento'), false);
});
