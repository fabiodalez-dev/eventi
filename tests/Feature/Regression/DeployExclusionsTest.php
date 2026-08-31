<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * «File che non vanno mai sincronizzati» (`RUNBOOK.md`).
 *
 * Tre percorsi esistono su entrambe le macchine, ma il loro contenuto corretto
 * dipende da **dove** si trovano. Il `rsync --delete` del rilascio li esclude;
 * togliere una di quelle righe non fa fallire niente in CI e rompe la
 * produzione in modi che non assomigliano alla causa:
 *
 * - `public/storage` è un symlink assoluto a un percorso del Mac → nessuna
 *   immagine si carica;
 * - `bootstrap/cache/*` elenca i provider delle dipendenze di sviluppo,
 *   assenti in produzione → HTTP 500 su tutto;
 * - `storage/app/private/` è dove vivono i backup di §16 → **ogni
 *   pubblicazione li cancellerebbe**, cioè proprio il gesto dopo il quale un
 *   backup serve di più.
 *
 * Sono tre righe di YAML che nessun test tocca: questo è il loro presidio.
 */
function passoDiSincronizzazione(): string
{
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    $inizio = strpos($ci, 'rsync -az --delete');

    expect($inizio)->not->toBeFalse();

    $fine = strpos($ci, '- name:', (int) $inizio);

    return substr($ci, (int) $inizio, ($fine === false ? strlen($ci) : $fine) - (int) $inizio);
}

it('esclude dal rilascio i percorsi il cui contenuto dipende dalla macchina', function (string $percorso): void {
    expect(passoDiSincronizzazione())->toContain("--exclude '".$percorso."'");
})->with([
    'il symlink delle immagini' => 'public/storage',
    'la cache dei provider' => 'bootstrap/cache/*',
    'i backup di §16' => 'storage/app/private/*',
]);

it('sincronizza invece il file che forza la versione di PHP sul server', function (): void {
    expect(passoDiSincronizzazione())->not->toContain('public/.htaccess')
        ->and(file_exists(base_path('public/.htaccess')))->toBeTrue();
});

it('non sincronizza i test né i segreti', function (): void {
    expect(passoDiSincronizzazione())
        ->toContain("--exclude 'tests'")
        ->toContain("--exclude '.env'")
        ->toContain("--exclude '.git'");
});

/**
 * L'altra trappola già scattata una volta: `/stato` dichiarato **dopo** il
 * gruppo con il prefisso `/{city}` veniva letto come la città «stato», e
 * rispondeva 404 esattamente come una rotta inesistente — indistinguibile
 * finché non si guardava `route:list`.
 */
it('legge /stato come lo stato del sistema e non come una città', function (): void {
    $rotta = Route::getRoutes()->getByName('ops.health');

    expect($rotta)->not->toBeNull()
        ->and($rotta?->uri())->toBe('stato');

    config(['health.secret_token' => 'chiave-di-prova']);

    $risposta = $this->withHeader('X-Secret-Token', 'chiave-di-prova')->get('/stato');

    expect($risposta->baseResponse->headers->get('Content-Type'))->toContain('json');
});

it('tiene lo stato del sistema fuori dalla portata di chi non ha la chiave', function (): void {
    config(['health.secret_token' => 'chiave-di-prova']);

    $this->get('/stato')->assertNotFound();
    $this->get('/stato/completo')->assertNotFound();
    $this->get('/stato?token=sbagliata')->assertNotFound();
});
