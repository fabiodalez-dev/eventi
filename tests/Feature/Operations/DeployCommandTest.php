<?php

declare(strict_types=1);
use Illuminate\Console\Scheduling\Schedule;

/**
 * Il comando di rilascio, e soprattutto cosa rifiuta.
 *
 * Lo innesca una rotta HTTP raggiungibile da internet: il nome del ramo arriva
 * da fuori, e va trattato come ostile.
 */
it('rifiuta un ramo che comincia con un trattino', function (): void {
    /*
     * `--upload-pack` è fatto di sole lettere e trattini: una whitelist di
     * caratteri lo lascia passare, e `git` lo legge come OPZIONE invece che
     * come ramo. È l'opzione con cui si fa eseguire un comando arbitrario
     * dall'altra parte della connessione.
     */
    $this->artisan('deploy:pull', ['--branch' => '--upload-pack'])
        ->expectsOutputToContain('Nome di ramo non valido.')
        ->assertExitCode(1);
});

it('rifiuta un ramo con un intervallo o una risalita', function (): void {
    /* `..` nei riferimenti git è un intervallo, nei percorsi una risalita. */
    $this->artisan('deploy:pull', ['--branch' => 'main..../etc'])
        ->expectsOutputToContain('Nome di ramo non valido.')
        ->assertExitCode(1);
});

it('rifiuta un ramo con caratteri che la shell interpreta', function (): void {
    foreach (['main;id', 'main$(id)', 'main|id', 'main origin', "main\nid"] as $ostile) {
        $this->artisan('deploy:pull', ['--branch' => $ostile])
            ->expectsOutputToContain('Nome di ramo non valido.')
            ->assertExitCode(1);
    }
});

/*
 * `--if-behind`: il rilascio si controlla da se'.
 *
 * Serve perche' l'innesco dall'esterno non e' garantito — questo host non
 * accetta connessioni dal runner ne' sulla 22 ne' sulla 443, e il firewall e'
 * dell'hosting. Un rilascio che dipende da una porta in entrata su una
 * macchina condivisa e' un rilascio che un giorno smette senza preavviso.
 */
it('non fa niente quando non c e niente di nuovo', function (): void {
    /*
     * Qui non c'è un `origin` da interrogare: il comando deve accorgersene e
     * fermarsi senza toccare il progetto — non provare a rilasciare al buio.
     */
    $this->artisan('deploy:pull', ['--if-behind' => true])
        ->assertExitCode(0);
});

it('e schedulato, cosi il rilascio avviene anche se nessuno lo chiede', function (): void {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'deploy:pull'));

    expect($evento)->not->toBeNull('senza questo, un push non arriva in produzione se la chiamata non passa')
        ->and($evento?->expression)->toBe('*/5 * * * *');
});
