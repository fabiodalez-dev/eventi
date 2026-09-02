<?php

declare(strict_types=1);

use App\Http\Requests\Web\Account\RegisterRequest;
use App\Http\Requests\Web\StoreEventSubmissionRequest;
use App\Http\Requests\Web\StoreReportRequest;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Support\Health\BackupFreshnessCheck;
use App\Support\Health\CalendarCoverageCheck;
use App\Support\Health\MediaWeightCheck;
use App\Support\Health\ProductionSecretsCheck;
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

    /* Un file con nome e data perfetti che zip non riesce ad aprire: e'
       esattamente cio' che resta di un archivio morto mentre scriveva. */
    $troncato = $cartella.'/'.now()->format('Y-m-d-H-i-s').'.zip';
    File::put($troncato, 'PK'.str_repeat('x', 4096));

    $esito = (new BackupFreshnessCheck)->run();

    expect($esito->status)->toBe(Status::failed())
        ->and($esito->getShortSummary())->not->toBeEmpty();

    File::delete($troncato);
});

it('accetta un backup recente e di peso credibile', function (): void {
    $cartella = storage_path('app/private/'.config()->string('backup.backup.name'));
    File::ensureDirectoryExists($cartella);

    foreach (File::glob($cartella.'/*.zip') as $vecchio) {
        File::delete($vecchio);
    }

    /*
     * Uno zip vero, e piccolo di proposito: un `backup:run --only-db` produce
     * un archivio da 256 KB perfettamente valido. La prima versione del
     * controllo lo bocciava perche' giudicava il peso — l'ho scoperto
     * creandone uno a mano in produzione e vedendolo dichiarare troncato.
     */
    $buono = $cartella.'/'.now()->format('Y-m-d-H-i-s').'-buono.zip';

    $zip = new ZipArchive;
    $zip->open($buono, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('dump.sql', str_repeat('-- riga di dump'.PHP_EOL, 200));
    $zip->close();

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

it('non protesta per le chiavi di produzione fuori dalla produzione', function (): void {
    /* Un controllo che si lamenta sulla macchina di chi scrive codice viene
       spento il primo giorno, e con lui sparisce anche in produzione. */
    $esito = (new ProductionSecretsCheck)->run();

    expect($esito->status)->toBe(Status::ok());
});

it('in produzione si accorge che Turnstile non e configurato', function (): void {
    /*
     * Il caso vero: `TURNSTILE_SITE_KEY` e `SECRET_KEY` sono rimaste vuote in
     * produzione per settimane. `Turnstile::rules()` restituisce un array
     * vuoto quando non e' configurato — scelta giusta, perche' un modulo che
     * rifiuta tutti perche' manca una chiave sarebbe peggio — ma il risultato
     * e' che «proponi evento» e «registra il tuo locale» accettavano invii da
     * qualunque script, e niente lo segnalava.
     */
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('services.turnstile.site_key', '');
    config()->set('services.turnstile.secret_key', '');

    $esito = (new ProductionSecretsCheck)->run();

    expect($esito->status)->toBe(Status::failed())
        ->and($esito->meta['mancanti'])->toContain('TURNSTILE_SITE_KEY / SECRET_KEY');
});

it('distingue cio che espone da cio che fa perdere qualcosa', function (): void {
    /*
     * Senza Sentry si perdono gli errori; senza Turnstile si e' esposti a
     * quello che arriva da fuori. Un controllo che tratta le due cose allo
     * stesso modo insegna a rimandarle entrambe.
     */
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('services.turnstile.site_key', 'una-chiave');
    config()->set('services.turnstile.secret_key', 'un-segreto');
    config()->set('sentry.dsn', '');

    $esito = (new ProductionSecretsCheck)->run();

    expect($esito->status)->toBe(Status::warning())
        ->and($esito->meta['mancanti'])->toContain('SENTRY_LARAVEL_DSN');
});

it('protegge tutti e quattro i moduli pubblici quando e configurato', function (): void {
    /*
     * Non basta che la classe sappia validare: le regole devono essere
     * agganciate a ogni modulo che accetta invii da chiunque. Uno dimenticato
     * e' una porta aperta che nessuno nota, perche' gli altri tre funzionano.
     */
    foreach ([
        StoreEventSubmissionRequest::class,
        StoreVenueApplicationRequest::class,
        StoreReportRequest::class,
        RegisterRequest::class,
    ] as $richiesta) {
        $sorgente = (string) file_get_contents(
            base_path(str_replace(['App\\', '\\'], ['app/', '/'], $richiesta).'.php'),
        );

        /* Il nome della richiesta nel messaggio e non in `toContain`: il
           secondo argomento di quel metodo e' un altro valore da cercare, non
           una spiegazione — e il test falliva dicendo che il file non contiene
           la propria descrizione. */
        /* `Turnstile::rules(` e non `rules()`: da quando ogni modulo dichiara
           la propria azione la firma porta un argomento, e cercare quella
           vuota faceva fallire il test su una modifica che migliorava proprio
           la cosa che sorveglia. Il controllo sulle azioni sta in
           `TurnstileVerificationTest`. */
        expect(str_contains($sorgente, 'Turnstile::rules('))
            ->toBeTrue($richiesta.' non chiede il gettone: e una porta aperta che nessuno nota, perche gli altri moduli funzionano');
    }
});
