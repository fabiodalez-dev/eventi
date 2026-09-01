<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

/**
 * «File che non vanno mai sincronizzati» (`RUNBOOK.md`).
 *
 * Tre percorsi esistono su entrambe le macchine, ma il loro contenuto corretto
 * dipende da **dove** si trovano:
 *
 * - `public/storage` è un symlink assoluto: quello creato sul Mac punta a una
 *   cartella che sul server non esiste → nessuna immagine si carica;
 * - `bootstrap/cache/*` elenca anche i provider delle dipendenze di sviluppo,
 *   assenti in produzione → HTTP 500 su tutto;
 * - `storage/app/private/` è dove vivono i backup di §16 → una pubblicazione
 *   che li sovrascrivesse cancellerebbe proprio ciò che serve di più subito
 *   dopo una pubblicazione.
 *
 * **Il presidio è cambiato insieme al meccanismo.** Prima il rilascio spingeva
 * i file con `rsync --delete` e la garanzia stava in tre righe di `--exclude`;
 * ora è il server a tirare con `git reset --hard`, e la garanzia è che quei
 * percorsi **non stiano nel repository**. Se ci finissero, il reset li
 * riscriverebbe con la versione di un'altra macchina — lo stesso guasto, per
 * una strada diversa.
 */
it('tiene fuori dal repository i percorsi il cui contenuto dipende dalla macchina', function (string $percorso): void {
    $processo = Process::fromShellCommandline(
        'git check-ignore -q '.escapeshellarg($percorso).' && echo ignorato || echo tracciato',
        base_path(),
    );

    $processo->run();

    expect(trim($processo->getOutput()))->toBe(
        'ignorato',
        "«{$percorso}» deve restare fuori dal repository: il rilascio fa `git reset --hard` e lo sovrascriverebbe"
    );
})->with([
    'il symlink delle immagini' => 'public/storage',
    'la cache dei provider' => 'bootstrap/cache/packages.php',
    /* Il PERCORSO di un backup, non la cartella: Laravel versiona lo
       scheletro di `storage` — un `.gitignore` che esclude il contenuto — e
       chiedere che la cartella intera sia ignorata verificherebbe la cosa
       sbagliata. Cio' che non deve viaggiare e' quello che ci sta dentro. */
    'i backup di §16' => 'storage/app/private/eventi/2026-01-01-00-00-00.zip',
    'i segreti di questa installazione' => '.env',
    'le dipendenze' => 'vendor',
    'i media caricati' => 'storage/media-library',
]);

it('versiona invece il file che forza la versione di PHP sul server', function (): void {
    /* Questo INVECE deve viaggiare: senza, il server serve il sito con il PHP
       predefinito della shared hosting, che non è quello che l'applicazione
       pretende. */
    $processo = Process::fromShellCommandline(
        'git check-ignore -q public/.htaccess && echo ignorato || echo tracciato',
        base_path(),
    );

    $processo->run();

    expect(trim($processo->getOutput()))->toBe('tracciato')
        ->and(file_exists(base_path('public/.htaccess')))->toBeTrue();
});

/**
 * Il rilascio non passa più da SSH, e non è una preferenza: la porta 22 di
 * quell'host non accetta connessioni da fuori, e ogni `rsync` moriva in
 * `Connection timed out`. Il workflow manda un segnale HTTPS e il server tira.
 */
it('non prova più a spingere i file via SSH', function (): void {
    $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($ci)
        ->not->toContain('rsync -az --delete')
        ->toContain('Chiedi al server di aggiornarsi');
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
