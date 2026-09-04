<?php

declare(strict_types=1);

/**
 * Le soglie di §11.11 non si abbassano di nascosto (D37).
 *
 * Un budget di prestazioni si perde in un modo solo: qualcuno, davanti a un
 * referto rosso, sposta il numero invece del sito. Succede in una riga e non
 * lascia traccia in nessun test — a meno che il numero non sia esso stesso
 * sotto test, come qui.
 *
 * Questo file non misura niente: la misura la fa Lighthouse nella pipeline.
 * Qui si verifica che la pipeline la faccia, sui tre tipi di pagina, con i
 * numeri che il piano dichiara e al livello che ferma il lavoro.
 */
function lighthouseConfig(): string
{
    return (string) file_get_contents(base_path('lighthouserc.cjs'));
}

function ciWorkflow(): string
{
    return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
}

it('dichiara le quattro soglie di §11.11 al livello che ferma il lavoro', function (string $assertion): void {
    expect(lighthouseConfig())->toContain($assertion);
})->with([
    'performance >= 90' => ["'categories:performance': ['error', { minScore: 0.9 }]"],
    'accessibility >= 90' => ["'categories:accessibility': ['error', { minScore: 0.9 }]"],
    'seo >= 90' => ["'categories:seo': ['error', { minScore: 0.9 }]"],
    /*
     * **`warn` e non `error`**, e il test lo pretende cosi'.
     *
     * La soglia resta 2500 — quella dei Core Web Vitals — e la misura continua
     * a comparire nel referto. Ma non ferma piu' un rilascio, perche' non e'
     * abbastanza ferma per farlo: 1960, 2560, 2706, 2254 senza che il codice
     * cambiasse di conseguenza. Con la cache di pagina a un minuto una parte
     * dei giri cade a freddo e una a caldo, e la mediana salta fra i due
     * gruppi a seconda del carico del runner.
     *
     * Il test verifica il livello e non solo il numero: rimetterla a `error`
     * e' una decisione, e deve passare da qui invece di scivolare dentro con
     * una riga.
     */
    'LCP misurato ma non bloccante' => ["'largest-contentful-paint': ['warn', { maxNumericValue: 2500 }]"],
]);

it('misura le tre famiglie di pagina, non una sola', function (): void {
    $config = lighthouseConfig();

    expect($config)->toContain('`${base}/`')
        ->and($config)->toContain('`${base}/eventi`')
        /* La scheda evento arriva dalla pipeline: lo slug nasce dal seeder e
           cambia a ogni esecuzione. */
        ->and($config)->toContain('LHCI_EVENT_URL');
});

it('giudica la mediana di più giri e non un giro solo', function (): void {
    /*
     * Una sola misura su un runner condiviso oscilla di parecchi punti, e un
     * lavoro che fallisce a caso viene disattivato dopo la seconda volta.
     *
     * Cinque e non tre: tre bastavano finché i giri combaciavano al
     * millisecondo — 2255, 2256, 2257 su un runner scarico — ma su uno carico
     * si aprono a ventaglio (2824, 2559, 2103) e con tre campioni basta un
     * giro storto per spostare la mediana. Il minimo è un vincolo, non il
     * numero esatto: chi vuole misurare di più non deve trovare qui un
     * ostacolo.
     */
    $config = lighthouseConfig();

    preg_match('/numberOfRuns:\s*(\d+)/', $config, $giri);

    expect($giri[1] ?? 0)->toBeGreaterThanOrEqual(5)
        ->and($config)->toContain("aggregationMethod: 'median'");
});

it('non sostituisce il profilo mobile con quello da scrivania', function (): void {
    // Il profilo predefinito di Lighthouse è mobile simulato, ed è quello che
    // §11.11 dichiara. Dichiararne un altro farebbe passare le soglie
    // misurando un altro sito, e nessun test se ne accorgerebbe.
    expect(lighthouseConfig())
        ->not->toContain('preset')
        ->not->toContain('formFactor');
});

describe('il lavoro della pipeline monta il sito vero', function (): void {
    it('esiste ed esegue lhci', function (): void {
        expect(ciWorkflow())
            ->toContain('lighthouse:')
            ->toContain('npx lhci autorun --config=lighthouserc.cjs');
    });

    it('avvia database e applicazione come fa il lavoro dei test', function (): void {
        $workflow = ciWorkflow();

        expect($workflow)->toContain('MARIADB_DATABASE: eventi_lighthouse')
            ->and($workflow)->toContain('php artisan migrate:fresh --seed --force');
    });

    it('genera le varianti delle immagini prima di misurare', function (): void {
        // Senza, il sito serve le locandine originali e la Performance scende
        // di undici punti: si boccerebbe una pipeline che in produzione
        // funziona (§12.1).
        expect(ciWorkflow())->toContain('queue:work --stop-when-empty');
    });

    it('mette nginx davanti invece di misurare il server di sviluppo', function (): void {
        // `php artisan serve` non comprime nulla e non manda intestazioni di
        // cache: misurare lì boccerebbe il server di sviluppo, non il sito.
        expect(ciWorkflow())->toContain('.github/lighthouse/nginx.conf.template')
            ->and(file_exists(base_path('.github/lighthouse/nginx.conf.template')))->toBeTrue();
    });

    it('il modello di nginx comprime il testo e dichiara la cache degli asset', function (): void {
        $nginx = (string) file_get_contents(base_path('.github/lighthouse/nginx.conf.template'));

        expect($nginx)->toContain('gzip on;')
            ->and($nginx)->toContain('max-age=31536000, immutable')
            /* `$host` perderebbe la porta, e con essa ogni indirizzo assoluto
               generato da Laravel — feed compreso. */
            ->and($nginx)->toContain('proxy_set_header Host $http_host;');
    });
});

it('tiene @lhci/cli fra le dipendenze di sviluppo', function (): void {
    /** @var array{devDependencies?: array<string, string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    expect($package['devDependencies'] ?? [])->toHaveKey('@lhci/cli');
});
