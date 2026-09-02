<?php

declare(strict_types=1);

use App\Support\Backup\SpazioSufficiente;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/*
 * Il 2 settembre 2026 il backup notturno ha esaurito la quota dell'account
 * mentre scriveva ed e' morto a meta': da quel momento nessun processo e'
 * riuscito a scrivere: la home rispondeva 500 mentre le altre pagine, che
 * avevano gia' la propria cache, continuavano a funzionare. A mettere giu' il
 * sito non e' stato un guasto del sito, ma il suo backup.
 */

/*
 * **Storage isolato, e non e' pignoleria.** Questi test scrivono e cancellano
 * dentro `storage/app/backup-temp`, che e' la cartella di lavoro di Spatie: in
 * parallelo con `BackupTest` uno spazzava via la cartella mentre l'altro ci
 * stava scrivendo il dump, e il fallimento compariva nel test innocente.
 *
 * Che due processi si contendano quella cartella e' vero anche in produzione —
 * ed e' il motivo per cui la pulizia guarda l'orologio prima di cancellare —
 * ma un test non deve dimostrarlo rompendo il vicino.
 */
beforeEach(function (): void {
    $this->storageIsolato = sys_get_temp_dir().'/eventi-spazio-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($this->storageIsolato.'/app');
    app()->useStoragePath($this->storageIsolato);
});

afterEach(function (): void {
    File::deleteDirectory($this->storageIsolato);
});

it('lascia passare il backup quando c e spazio', function (): void {
    expect(SpazioSufficiente::verifica())->toBeTrue();
});

it('non lascia dietro il proprio file di prova', function (): void {
    SpazioSufficiente::verifica();

    expect(File::exists(storage_path('app/backup-temp/.prova-spazio')))->toBeFalse();
});

it('spazza via i resti di un backup morto a meta', function (): void {
    /*
     * Spatie pulisce la propria cartella temporanea quando finisce, in un modo
     * o nell'altro — ma non quando muore proprio mentre scrive. Quel giro ne
     * aveva lasciati 128 MB, che sono rimasti a occupare la quota gia'
     * esaurita e a tenere il sito a terra.
     */
    $temp = storage_path('app/backup-temp/temp');
    File::ensureDirectoryExists($temp.'/db-dumps');
    File::put($temp.'/db-dumps/finto-dump.sql', 'i resti di ieri notte');

    /* Fermi da ieri: nessuno ci sta piu' lavorando. */
    touch($temp, now()->subDay()->getTimestamp());

    Log::spy();

    expect(SpazioSufficiente::verifica())->toBeTrue()
        ->and(File::isDirectory($temp))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m): bool => str_contains($m, 'backup interrotto'));
});

it('non tocca la cartella di un backup che sta lavorando adesso', function (): void {
    /*
     * La prima versione cancellava quella cartella senza guardare l'ora, e in
     * prova ha distrutto un backup in corso: `withoutOverlapping` protegge dal
     * secondo giro dello scheduler, non da un `backup:run` lanciato a mano
     * mentre questo controllo passa di li'. Fare spazio distruggendo il lavoro
     * di qualcun altro e' il danno che questa classe esiste per evitare.
     */
    $temp = storage_path('app/backup-temp/temp');
    File::ensureDirectoryExists($temp.'/db-dumps');
    File::put($temp.'/db-dumps/dump-in-corso.sql', 'lo sto scrivendo ora');

    SpazioSufficiente::verifica();

    expect(File::exists($temp.'/db-dumps/dump-in-corso.sql'))
        ->toBeTrue('un backup in corso non si cancella per fare posto');

    File::deleteDirectory($temp);
});

it('e appeso al backup notturno, non solo disponibile', function (): void {
    /* Una salvaguardia scritta e non collegata non ha salvato niente. */
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'backup:run'));

    expect($evento)->not->toBeNull();

    /* `filters` e' protetta: si guarda da dentro, non si aggira. */
    $filtri = (new ReflectionProperty($evento, 'filters'))->getValue($evento);

    expect($filtri)->not->toBeEmpty('senza il filtro, un disco pieno rimette giu il sito')
        ->and($evento->filtersPass(app()))->toBeTrue('con spazio disponibile il backup deve poter partire');
});
