<?php

declare(strict_types=1);

use App\Services\Installer\DatabaseInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * La prova di connessione (D42, punto 3). Due tentativi e non uno: senza il
 * primo, «credenziali sbagliate» e «database inesistente» sarebbero lo stesso
 * messaggio inutile.
 */

function inspector(): DatabaseInspector
{
    return new DatabaseInspector(database_path('migrations'));
}

function connectionParameters(): array
{
    return [
        config()->string('database.connections.mariadb.host'),
        (int) config('database.connections.mariadb.port'),
        config()->string('database.connections.mariadb.database'),
        config()->string('database.connections.mariadb.username'),
        (string) config('database.connections.mariadb.password'),
    ];
}

it('accetta le credenziali giuste', function (): void {
    [$host, $port, $database, $username, $password] = connectionParameters();

    $probe = inspector()->probe($host, $port, $database, $username, $password);

    expect($probe->ok)->toBeTrue()
        ->and($probe->databaseCreated)->toBeFalse();
});

it('dice «non risponde nessuno» quando l’host o la porta sono sbagliati', function (): void {
    [$host, , $database, $username, $password] = connectionParameters();

    $probe = inspector()->probe($host, 1, $database, $username, $password);

    expect($probe->ok)->toBeFalse()
        ->and($probe->message())->toBe(__('installer.database.errors.unreachable'));
});

it('dice «credenziali rifiutate», che è un altro guasto con un altro rimedio', function (): void {
    [$host, $port, $database] = connectionParameters();

    $probe = inspector()->probe($host, $port, $database, 'utente-inesistente-'.bin2hex(random_bytes(3)), 'no');

    expect($probe->ok)->toBeFalse()
        ->and($probe->message())->toBe(__('installer.database.errors.credentials'));
});

it('crea il database mancante quando le credenziali lo permettono', function (): void {
    [$host, $port, , $username, $password] = connectionParameters();
    $name = 'eventi_probe_'.bin2hex(random_bytes(4));

    try {
        $probe = inspector()->probe($host, $port, $name, $username, $password);

        expect($probe->ok)->toBeTrue()
            ->and($probe->databaseCreated)->toBeTrue();
    } finally {
        DB::statement('DROP DATABASE IF EXISTS `'.$name.'`');
    }
});

it('rifiuta un nome di database che non potrebbe scrivere in CREATE DATABASE senza rischi', function (): void {
    [$host, $port, , $username, $password] = connectionParameters();

    $probe = inspector()->probe($host, $port, 'nome`; DROP DATABASE x', $username, $password);

    expect($probe->ok)->toBeFalse()
        ->and($probe->message())->toBe(__('installer.database.errors.database_name_invalid'));
});

it('scrive il dettaglio tecnico nel log e non nel messaggio', function (): void {
    Log::spy();

    [$host, , $database, $username, $password] = connectionParameters();

    $probe = inspector()->probe($host, 1, $database, $username, $password);

    expect($probe->message())->not->toContain('SQLSTATE');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'Installer:')
            && array_key_exists('errore', $context))
        ->once();
});

it('deriva le tabelle attese dai file di migrazione', function (): void {
    $expected = inspector()->expectedTables();

    expect($expected)->toContain('cities', 'venues', 'events', 'event_occurrences', 'users', 'pages')
        ->and($expected)->not->toContain('migrations');
});

it('non trova tabelle mancanti su un database migrato', function (): void {
    expect(inspector()->missingTables())->toBe([]);
});
