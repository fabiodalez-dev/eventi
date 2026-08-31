<?php

declare(strict_types=1);

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;

/**
 * L'endpoint di stato di §16: protetto, e onesto sullo stato del sistema.
 *
 * I controlli veri (spazio su disco, coda, scheduler) dipendono dalla macchina
 * su cui gira la suite: qui se ne dichiarano di propri, così che «degradato»
 * sia una condizione che il test produce e non una che capita.
 */
final class ControlloSempreRosso extends Check
{
    public function run(): Result
    {
        return Result::make()->failed('il pezzo che conta si è rotto');
    }
}

beforeEach(function (): void {
    config(['health.secret_token' => 'chiave-di-prova']);

    /*
     * I controlli registrati dal provider si tolgono di mezzo: dipendono dalla
     * macchina — lo spazio libero del disco su cui gira la suite — e
     * renderebbero il risultato di questi test una proprietà del computer.
     */
    Health::clearChecks();
});

it('non risponde a chi non porta la chiave', function (): void {
    Health::checks([DatabaseCheck::new()]);
    $this->artisan('health:check')->assertSuccessful();

    $this->get('/stato')->assertNotFound();
    $this->get('/stato/completo')->assertNotFound();
});

it('non risponde a chi porta la chiave sbagliata', function (): void {
    Health::checks([DatabaseCheck::new()]);
    $this->artisan('health:check')->assertSuccessful();

    $this->withHeader('X-Secret-Token', 'quasi-giusta')->get('/stato')->assertNotFound();
    $this->get('/stato?token=quasi-giusta')->assertNotFound();
});

/**
 * Senza chiave configurata l'endpoint non si apre a tutti: si chiude a tutti.
 * È la differenza con il middleware del pacchetto, che senza chiave lascia
 * passare chiunque.
 */
it('senza chiave configurata non risponde a nessuno', function (): void {
    config(['health.secret_token' => null]);

    $this->withHeader('X-Secret-Token', 'chiave-di-prova')->get('/stato')->assertNotFound();
});

it('risponde a chi porta la chiave, nell\'intestazione o nell\'indirizzo', function (): void {
    Health::checks([DatabaseCheck::new()]);
    $this->artisan('health:check')->assertSuccessful();

    $this->withHeader('X-Secret-Token', 'chiave-di-prova')
        ->get('/stato')
        ->assertOk()
        ->assertJson(['healthy' => true]);

    $this->get('/stato?token=chiave-di-prova')->assertOk();
});

it('segnala 503 quando un controllo è rosso', function (): void {
    Health::checks([DatabaseCheck::new(), ControlloSempreRosso::new()]);
    $this->artisan('health:check')->assertSuccessful();

    $this->withHeader('X-Secret-Token', 'chiave-di-prova')
        ->get('/stato')
        ->assertStatus(503);
});

it('dice quale controllo è rosso sull\'indirizzo di dettaglio', function (): void {
    Health::checks([DatabaseCheck::new(), ControlloSempreRosso::new()]);
    $this->artisan('health:check')->assertSuccessful();

    $response = $this->withHeader('X-Secret-Token', 'chiave-di-prova')->get('/stato/completo');

    $response->assertStatus(503);

    $results = json_decode((string) $response->getContent(), true);

    expect($results)->toBeArray()->toHaveKey('checkResults');

    $rosso = collect($results['checkResults'])->firstWhere('name', 'ControlloSempreRosso');

    expect($rosso)->not->toBeNull()
        ->and($rosso['status'])->toBe('failed')
        ->and($rosso['notificationMessage'])->toBe('il pezzo che conta si è rotto');
});

/**
 * L'endpoint **non** riesegue i controlli a ogni richiesta: rilegge l'ultimo
 * esito salvato. Con un monitor che interroga ogni minuto, la differenza fra le
 * due cose è sette controlli al minuto per sempre.
 */
it('rilegge l\'ultimo esito invece di rieseguire i controlli', function (): void {
    Health::checks([DatabaseCheck::new()]);
    $this->artisan('health:check')->assertSuccessful();

    Health::checks([ControlloSempreRosso::new()]);

    $this->withHeader('X-Secret-Token', 'chiave-di-prova')->get('/stato')->assertOk();
});
