<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config as BackupConfig;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Notifications\Notifiable as BackupNotifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;

/**
 * Il backup di §16. Non si verifica leggendo la configurazione: si verifica
 * facendo girare il comando e guardando che cosa è arrivato sul disco.
 */
function backupSourceDirectory(): string
{
    $directory = storage_path('framework/testing/backup-source');

    File::ensureDirectoryExists($directory);
    File::put($directory.'/nota.txt', 'contenuto da conservare');

    return $directory;
}

beforeEach(function (): void {
    Storage::fake('local');

    config([
        'backup.backup.name' => 'eventi',
        'backup.backup.source.files.include' => [backupSourceDirectory()],
        'backup.backup.source.files.exclude' => [],
        'backup.backup.destination.disks' => ['local'],
    ]);

    BackupConfig::rebind();
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/backup-source'));
});

it('crea davvero un archivio sul disco di destinazione', function (): void {
    $this->artisan('backup:run', ['--only-files' => true])->assertSuccessful();

    $files = Storage::disk('local')->allFiles('eventi');

    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.zip')
        ->and(Storage::disk('local')->size($files[0]))->toBeGreaterThan(0);
});

it('mette il database nell\'archivio', function (): void {
    if (shellCommandExists('mariadb-dump') === false && shellCommandExists('mysqldump') === false) {
        $this->markTestSkipped('Senza mysqldump il dump del database non è producibile su questa macchina.');
    }

    /*
     * L'esito del comando si legge, non si constata soltanto.
     *
     * `assertSuccessful()` da solo dice «atteso 0, ricevuto 1» e nient'altro:
     * su una macchina remota, dove il comando non si puo' rilanciare a mano,
     * quella riga non basta a capire se manchi il client, se il dump sia
     * incompatibile o se il disco sia pieno. L'output del comando lo dice.
     */
    $codice = $this->artisan('backup:run')->run();

    expect($codice)->toBe(
        0,
        'backup:run è fallito. Output del comando: '.Artisan::output()
    );

    $files = Storage::disk('local')->allFiles('eventi');

    expect($files)->toHaveCount(1);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($files[0]));

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }

    $zip->close();

    expect(collect($names)->contains(fn (string $name): bool => str_contains($name, 'db-dumps')))->toBeTrue();
});

/**
 * §16: conservazione **30 giorni**. La prova non è il valore in configurazione
 * ma il comportamento: una copia di 40 giorni fa sparisce, una di 10 resta.
 */
it('conserva trenta giorni di backup e butta il resto', function (): void {
    $now = CarbonImmutable::parse('2026-06-15 12:00:00');
    Carbon\Carbon::setTestNow($now);

    Storage::disk('local')->put('eventi/'.$now->subDays(40)->format('Y-m-d-H-i-s').'.zip', 'vecchio');
    Storage::disk('local')->put('eventi/'.$now->subDays(10)->format('Y-m-d-H-i-s').'.zip', 'recente');
    Storage::disk('local')->put('eventi/'.$now->subDay()->format('Y-m-d-H-i-s').'.zip', 'di ieri');

    $this->artisan('backup:clean')->assertSuccessful();

    $remaining = Storage::disk('local')->allFiles('eventi');

    expect($remaining)->toHaveCount(2)
        ->and(collect($remaining)->contains(fn (string $file): bool => str_contains($file, $now->subDays(40)->format('Y-m-d'))))->toBeFalse()
        ->and(collect($remaining)->contains(fn (string $file): bool => str_contains($file, $now->subDays(10)->format('Y-m-d'))))->toBeTrue();
});

/**
 * §16 in una riga: «un backup che non gira e non avvisa è peggio di nessun
 * backup».
 */
it('avvisa via email quando il backup fallisce', function (): void {
    Notification::fake();

    config(['backup.notifications.mail.to' => ['esercizio@example.test']]);
    BackupConfig::rebind();

    event(new BackupHasFailed(new RuntimeException('il disco non risponde')));

    Notification::assertSentTo(
        new BackupNotifiable,
        BackupHasFailedNotification::class,
        fn (BackupHasFailedNotification $notification): bool => in_array('mail', $notification->via(), true),
    );
});

/**
 * Il successo, invece, non avvisa: un messaggio ogni notte per dire che è
 * andato tutto bene si smette di leggere in una settimana, e da quel momento
 * non si legge più nemmeno quello che dice il contrario.
 */
it('non avvisa quando il backup riesce', function (): void {
    /*
     * È la stessa lettura che fa `BaseNotification::via()`: la voce esiste —
     * senza, il pacchetto morirebbe di chiave mancante — ma non ha canali.
     */
    $channels = app(BackupConfig::class)->notifications->notifications;

    expect($channels)->toHaveKey(BackupWasSuccessfulNotification::class)
        ->and($channels[BackupWasSuccessfulNotification::class])->toBe([])
        ->and($channels[BackupHasFailedNotification::class])->toBe(['mail']);
});

/**
 * Senza indirizzo configurato nessun canale ha destinazione: Laravel salta
 * l'invio invece di sollevare un errore all'avvio.
 */
it('senza indirizzo di esercizio non ha nessuno da avvisare', function (): void {
    config(['backup.notifications.mail.to' => []]);
    BackupConfig::rebind();

    expect((new BackupNotifiable)->routeNotificationFor('mail'))->toBe([]);
});
