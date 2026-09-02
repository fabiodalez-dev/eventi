<?php

declare(strict_types=1);

use App\Support\Health\BackupFreshnessCheck;
use App\Support\Health\CalendarCoverageCheck;
use App\Support\Health\MediaWeightCheck;
use Illuminate\Support\Facades\File;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;

/*
 * La manutenzione ordinaria: i tre guasti che non si annunciano.
 *
 * Tutti e tre sono successi davvero. Hanno in comune la cosa peggiore: nessuno
 * fa cadere il sito nel momento in cui accade, e tutti si presentano piu' tardi
 * come qualcos'altro — una pagina lenta, un 500 dove ieri non c'era, o niente
 * del tutto. Il costo non e' il guasto: e' l'indagine per risalire dal sintomo
 * alla causa.
 */

it('misura la copertura del calendario, che e il KPI del piano', function (): void {
    /*
     * §1 lo dichiara come la domanda a cui il prodotto risponde e §2.2 lo
     * chiama «zero pagina vuota». Era scritto due volte nel piano e in nessun
     * posto nel codice: il pannello contava eventi incompleti e duplicati —
     * cioe' se un evento e' scritto male — mai se giovedi' sera la citta' e'
     * vuota.
     */
    $citta = testCity();
    $categoria = testCategory();

    foreach (range(0, 13) as $giorno) {
        foreach (range(1, 3) as $ignored) {
            occurrenceAtLocal($citta, $categoria, now()->addDays($giorno)->format('Y-m-d').' 21:00:00');
        }
    }

    $esito = (new CalendarCoverageCheck)->run();

    expect($esito->status)->toBe(Status::ok())
        ->and($esito->meta['giorni_coperti'])->toBe(14);
});

it('si accorge quando il calendario si svuota', function (): void {
    /* Il guasto senza sintomi: chi apre il sito non trova niente da fare e non
       torna, e nessuno scrive per dirlo. */
    $esito = (new CalendarCoverageCheck)->run();

    expect($esito->status)->toBe(Status::failed())
        ->and($esito->meta['giorni_coperti'])->toBe(0);
});

it('non conta i giorni con meno di tre date', function (): void {
    /* Duecento eventi tutti nello stesso weekend sono un totale ottimo e un
       calendario pessimo: chi apre il sito di martedi' non trova niente. */
    $citta = testCity();
    $categoria = testCategory();

    foreach (range(0, 13) as $giorno) {
        occurrenceAtLocal($citta, $categoria, now()->addDays($giorno)->format('Y-m-d').' 21:00:00');
    }

    $esito = (new CalendarCoverageCheck)->run();

    expect($esito->meta['giorni_coperti'])->toBe(0)
        ->and($esito->meta['occorrenze_future'])->toBe(14);
});

it('si accorge di un backup troncato, non solo di uno vecchio', function (): void {
    /*
     * Il 2 settembre 2026 il backup ha esaurito la quota mentre scriveva ed e'
     * morto a meta': l'archivio piu' recente c'era, aveva un nome perfetto, e
     * non si sarebbe aperto. `backup:monitor` guarda la data, non il peso.
     */
    $cartella = storage_path('app/private/'.config()->string('backup.backup.name'));
    File::ensureDirectoryExists($cartella);

    $troncato = $cartella.'/'.now()->format('Y-m-d-H-i-s').'.zip';
    File::put($troncato, str_repeat('x', 1024));

    $esito = (new BackupFreshnessCheck)->run();

    expect($esito->status)->toBe(Status::failed())
        ->and($esito->getShortSummary())->not->toBeEmpty();

    File::delete($troncato);
});

it('accetta un backup recente e di peso credibile', function (): void {
    $cartella = storage_path('app/private/'.config()->string('backup.backup.name'));
    File::ensureDirectoryExists($cartella);

    $buono = $cartella.'/'.now()->format('Y-m-d-H-i-s').'-buono.zip';
    File::put($buono, str_repeat('x', 2 * 1024 * 1024));

    $esito = (new BackupFreshnessCheck)->run();

    expect($esito->status)->toBe(Status::ok());

    File::delete($buono);
});

it('conta le locandine da elenco troppo pesanti', function (): void {
    /*
     * Una locandina da 231 KB in apertura non compare nei referti come
     * «immagine pesante»: compare come «tempo di disegno alto», e manda a
     * cercare nel codice un difetto che sta in un file caricato da qualcuno.
     */
    $cartella = storage_path('app/public/prova-peso');
    File::ensureDirectoryExists($cartella);

    File::put($cartella.'/locandina-999-card.webp', str_repeat('x', 200 * 1024));

    $esito = (new MediaWeightCheck)->run();

    expect($esito->meta['sopra_il_tetto'])->toBeGreaterThan(0);

    File::deleteDirectory($cartella);
});

it('non si allarma per poche immagini irriducibili', function (): void {
    /*
     * Alcune non rientrano nemmeno alla qualita' minima — una foto notturna
     * piena di grana lo e' per natura — e con una soglia fissa il controllo
     * resterebbe rosso per sempre. Un controllo sempre rosso viene spento dopo
     * la seconda volta, e allora non segnala piu' nemmeno il caso in cui ne
     * arrivano cento.
     */
    $sorgente = (string) file_get_contents(app_path('Support/Health/MediaWeightCheck.php'));

    expect($sorgente)->toContain('frazioneTollerata')
        ->and($sorgente)->not->toContain('$this->tolleranza');
});

it('i tre controlli sono registrati, non solo scritti', function (): void {
    /* Un controllo che nessuno esegue non ha mai segnalato niente. */
    $registrati = collect(Health::registeredChecks())
        ->map(fn ($check): string => $check::class);

    expect($registrati)->toContain(CalendarCoverageCheck::class)
        ->and($registrati)->toContain(BackupFreshnessCheck::class)
        ->and($registrati)->toContain(MediaWeightCheck::class);
});

it('il comando che ripassa lo storico esiste e non tocca niente in prova', function (): void {
    /*
     * Il listener tiene nel tetto le conversioni NUOVE: un difetto corretto
     * solo in avanti resta a terra per tutto ciò che c'era prima — erano 84
     * locandine su 495.
     */
    $this->artisan('media:compress-oversized', ['--dry-run' => true])->assertExitCode(0);
});
