<?php

declare(strict_types=1);

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
