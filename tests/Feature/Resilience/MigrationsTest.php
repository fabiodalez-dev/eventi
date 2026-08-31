<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Le migrazioni percorse su un database **vuoto davvero**, e poi disfatte.
 *
 * Il verso di andata lo copre già la suite, che a ogni avvio ricostruisce lo
 * schema. Il verso di ritorno no, e non lo copre nessuno: `RUNBOOK.md` indica
 * `migrate:rollback` come la manovra da fare dopo un rilascio andato male, ma
 * una `down()` mancante o sbagliata non fallisce mai finché non la si chiama —
 * e la si chiama esattamente nel momento in cui non ci si può permettere che
 * fallisca.
 *
 * **Perché in un processo a parte.** Non basta `migrate --database=...`: le
 * migrazioni pubblicate dai pacchetti dichiarano la propria connessione da
 * sole (`Schema::connection($model->getConnectionName())`), quindi
 * scriverebbero sul database della suite qualunque cosa dica l'opzione — che è
 * il modo in cui un test sulle migrazioni porta via lo schema a tutti gli
 * altri test. Un processo con `DB_DATABASE` diverso è l'unico isolamento che
 * tiene, ed è anche esattamente ciò che succede in produzione.
 */
function nomeDatabaseDiProva(): string
{
    return DB::connection()->getDatabaseName().'_mig';
}

function creaDatabaseVuoto(): void
{
    $base = config()->array('database.connections.'.config()->string('database.default'));
    config()->set('database.connections.prova_server', [...$base, 'database' => null]);
    config()->set('database.connections.prova', [...$base, 'database' => nomeDatabaseDiProva()]);

    DB::purge('prova_server');
    DB::purge('prova');

    DB::connection('prova_server')->statement('DROP DATABASE IF EXISTS `'.nomeDatabaseDiProva().'`');
    DB::connection('prova_server')->statement('CREATE DATABASE `'.nomeDatabaseDiProva().'`');
}

function eliminaDatabaseDiProva(): void
{
    DB::connection('prova_server')->statement('DROP DATABASE IF EXISTS `'.nomeDatabaseDiProva().'`');

    DB::purge('prova');
    DB::purge('prova_server');
}

/**
 * @return array{0: int, 1: string} codice di uscita e output
 */
function artisanFuoriProcesso(string $comando): array
{
    $riga = sprintf(
        'cd %s && DB_DATABASE=%s %s artisan %s 2>&1',
        escapeshellarg(base_path()),
        escapeshellarg(nomeDatabaseDiProva()),
        escapeshellarg(PHP_BINARY),
        $comando,
    );

    exec($riga, $output, $stato);

    return [$stato, implode("\n", $output)];
}

/**
 * @return list<string>
 */
function tabelleDiProva(): array
{
    return array_map(
        static fn (object $riga): string => (string) $riga->TABLE_NAME,
        DB::connection('prova')->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [nomeDatabaseDiProva(), 'BASE TABLE'],
        ),
    );
}

it('arriva in fondo su un database vuoto, e ci lascia lo schema intero', function (): void {
    creaDatabaseVuoto();

    try {
        expect(tabelleDiProva())->toBe([]);

        [$stato, $output] = artisanFuoriProcesso('migrate --force --no-interaction');

        expect($stato)->toBe(0, $output);

        expect(tabelleDiProva())
            ->toContain('cities', 'venues', 'events', 'event_occurrences', 'event_recurrences')
            ->toContain('users', 'saved_events', 'follows', 'devices', 'scheduled_notifications')
            ->toContain('import_sources', 'import_runs', 'pages', 'consent_logs')
            ->toContain('permissions', 'roles', 'media', 'activity_log', 'features', 'jobs', 'failed_jobs');

        [$stato, $output] = artisanFuoriProcesso('migrate:status --no-interaction');

        expect($stato)->toBe(0)
            ->and($output)->not->toContain('Pending');
    } finally {
        eliminaDatabaseDiProva();
    }
});

/**
 * La colonna geografica è il punto in cui una migrazione può passare su MySQL
 * e rompersi su MariaDB (§4 delle convenzioni): l'indice `SPATIAL` pretende
 * che la colonna sia `NOT NULL`, e la produzione è MariaDB.
 */
it('crea la colonna geografica con l indice spaziale che MariaDB pretende', function (): void {
    creaDatabaseVuoto();

    try {
        [$stato, $output] = artisanFuoriProcesso('migrate --force --no-interaction');
        expect($stato)->toBe(0, $output);

        $colonna = DB::connection('prova')->selectOne(
            'SELECT IS_NULLABLE, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [nomeDatabaseDiProva(), 'venues', 'location'],
        );

        $indici = DB::connection('prova')->select(
            'SELECT INDEX_TYPE FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [nomeDatabaseDiProva(), 'venues', 'location'],
        );

        expect($colonna?->IS_NULLABLE)->toBe('NO')
            ->and(strtolower((string) $colonna?->DATA_TYPE))->toBe('point')
            ->and(array_map(static fn (object $indice): string => (string) $indice->INDEX_TYPE, $indici))
            ->toContain('SPATIAL');
    } finally {
        eliminaDatabaseDiProva();
    }
});

/**
 * Il verso di ritorno, quello del runbook: disfare tutto e rifarlo.
 */
it('si disfa fino in fondo e si rifà, che è la manovra di rollback del runbook', function (): void {
    creaDatabaseVuoto();

    try {
        [$stato] = artisanFuoriProcesso('migrate --force --no-interaction');
        expect($stato)->toBe(0);

        [$stato, $output] = artisanFuoriProcesso('migrate:reset --force --no-interaction');
        expect($stato)->toBe(0, $output);

        /* Resta la sola tabella del registro: tutto il resto lo devono aver
           smontato le `down()`. Una tabella che sopravvive qui è una
           migrazione che, al rilascio successivo, non riparte. */
        expect(tabelleDiProva())->toBe(['migrations']);

        [$stato, $output] = artisanFuoriProcesso('migrate --force --no-interaction');

        expect($stato)->toBe(0, $output)
            ->and(tabelleDiProva())->toContain('event_occurrences');
    } finally {
        eliminaDatabaseDiProva();
    }
});

it('non ha una sola migrazione senza il proprio verso di ritorno', function (): void {
    $senzaDown = [];

    foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
        if (! str_contains((string) file_get_contents($file), 'public function down()')) {
            $senzaDown[] = basename($file);
        }
    }

    expect($senzaDown)->toBe([]);
});
