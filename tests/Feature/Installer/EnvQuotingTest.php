<?php

declare(strict_types=1);

use App\Services\Installer\EnvWriter;
use Tests\Support\InstallerSandbox;

/*
 * Il giro completo del quoting del `.env` (D42, punto 3).
 *
 * Ogni caso qui sotto scrive un valore, rilegge il file **con il parser di
 * Dotenv** — lo stesso che usa Laravel all'avvio, non un'espressione regolare
 * scritta per l'occasione — e pretende il valore identico a quello di
 * partenza. È l'unico modo di verificare il quoting che non rischi di
 * sbagliare esattamente dove sbaglia il codice che sta controllando.
 *
 * Perché tanti casi: ciascuno di questi caratteri, scritto nudo, cambia il
 * significato della riga in un modo diverso. Il cancelletto apre un commento e
 * tronca il valore; il dollaro innesca l'interpolazione di un'altra variabile;
 * l'apice doppio chiude la stringa a metà; la barra rovescia si mangia il
 * carattere che segue. E il sintomo è sempre lo stesso e sempre tardivo — «il
 * sito non si connette più al database» — perché la password sbagliata arriva
 * al server solo al primo riavvio utile.
 */

beforeEach(function (): void {
    $this->sandbox = InstallerSandbox::make($this->app);
});

afterEach(function (): void {
    $this->sandbox->cleanup();
});

it('scrive e rilegge identico un valore che contiene', function (string $value): void {
    app(EnvWriter::class)->write([
        'DB_PASSWORD' => $value,
        /* Una seconda chiave dopo la prima: se il valore rompesse la riga, questa ne pagherebbe il conto. */
        'DB_DATABASE' => 'eventi',
    ]);

    expect($this->sandbox->envValue('DB_PASSWORD'))->toBe($value)
        ->and($this->sandbox->envValue('DB_DATABASE'))->toBe('eventi');
})->with([
    'un cancelletto' => 'pa#ssword',
    'uno spazio' => 'pass word',
    'un apice singolo' => "pass'word",
    'un apice doppio' => 'pass"word',
    'un dollaro' => 'pa$$word',
    'una barra rovescia' => 'pass\\word',
    'un accento circonflesso rovesciato' => 'pass`word',
    'un uguale' => 'pass=word',
    'un punto esclamativo' => 'pass!word',
    'niente, perché è vuoto' => '',
    'lettere accentate' => 'passwòrd-èàùé',
    'uno spazio in coda, che conta' => 'password ',
    'la sintassi di interpolazione' => '${APP_NAME}',
    'un a capo' => "prima\nseconda",
    'la sequenza \\n scritta a mano' => 'pass\\nword',
    'tutti insieme' => 'p a#s\'"$\\`=!{}word',
]);

it('non permette a una password con un a capo di dichiarare una seconda variabile', function (): void {
    /*
     * Il valore arriva da un modulo pubblico e non autenticato. Se una riga
     * scritta dentro la password diventasse una riga del `.env`, chiunque
     * potesse aprire l'installer potrebbe dirottare il database dell'invio
     * della posta — o riaccendere `APP_DEBUG`.
     */
    app(EnvWriter::class)->write([
        'DB_PASSWORD' => "segreta\nDB_HOST=dirottato.example\nAPP_DEBUG=true",
        'DB_HOST' => '127.0.0.1',
        /* Scritta a mano subito dopo: se l'iniezione passasse, vincerebbe la riga infilata nella password. */
        'APP_DEBUG' => 'false',
    ]);

    expect($this->sandbox->envValue('DB_HOST'))->toBe('127.0.0.1')
        ->and($this->sandbox->envValue('APP_DEBUG'))->toBe('false')
        ->and($this->sandbox->envValue('DB_PASSWORD'))
        ->toBe("segreta\nDB_HOST=dirottato.example\nAPP_DEBUG=true");
});

it('non lascia che un a capo dentro un valore faccia sostituire la riga sbagliata', function (): void {
    /*
     * La stessa iniezione, ma con la variabile bersaglio dichiarata nel
     * modello **dopo** quella che porta l'a capo (`APP_NAME` è la prima riga di
     * `.env.example`, `DB_HOST` sta alla venticinquesima).
     *
     * È il caso che ha rotto davvero: cercando `DB_HOST` nel testo già
     * sostituito, la ricerca trovava per prima la riga infilata dentro
     * `APP_NAME`, la riscriveva, e la stringa fra apici restava aperta. Il
     * `.env` che ne usciva non era leggibile da Dotenv — non «una variabile
     * sbagliata», ma l'applicazione che non si avvia più, installer compreso.
     */
    app(EnvWriter::class)->write([
        'APP_NAME' => "Prova\nDB_HOST=dirottato.example",
        'DB_HOST' => '127.0.0.1',
    ]);

    expect($this->sandbox->envValue('APP_NAME'))->toBe("Prova\nDB_HOST=dirottato.example")
        ->and($this->sandbox->envValue('DB_HOST'))->toBe('127.0.0.1');
});

it('non tronca al cancelletto: il valore riletto è lungo quanto quello scritto', function (): void {
    /*
     * La verifica sulla lunghezza e non solo sull'uguaglianza è voluta: un
     * valore troncato dopo il cancelletto resterebbe una password valida a
     * vedersi, e il confronto con l'originale è l'unica cosa che se ne accorge.
     */
    $password = 'prima#dopo#ancora';

    app(EnvWriter::class)->write(['DB_PASSWORD' => $password]);

    expect(strlen((string) $this->sandbox->envValue('DB_PASSWORD')))->toBe(strlen($password));
});

it('non interpola una variabile nominata dentro un altro valore', function (): void {
    /*
     * Dotenv sostituisce `${ALTRA}` con il valore di `ALTRA` **dentro** i
     * valori fra apici doppi. Una password che contenesse `${APP_NAME}`
     * diventerebbe il nome del sito, e nessuno se ne accorgerebbe leggendo il
     * file.
     */
    app(EnvWriter::class)->write([
        'APP_NAME' => 'Eventi in città',
        'DB_PASSWORD' => 'x${APP_NAME}y',
    ]);

    expect($this->sandbox->envValue('DB_PASSWORD'))->toBe('x${APP_NAME}y')
        ->and($this->sandbox->envValue('DB_PASSWORD'))->not->toContain('Eventi in città');
});

it('aggiorna una chiave con un valore pieno di caratteri difficili e la rilegge identica', function (): void {
    /* `update()` percorre un'altra strada di `write()`: quoting sì, modello no. */
    $password = 'nu#ova "chiave" $con \\tutto`';

    $writer = app(EnvWriter::class);
    $writer->write(['DB_PASSWORD' => 'vecchia', 'APP_NAME' => 'Prova']);
    $writer->update(['DB_PASSWORD' => $password]);

    expect($this->sandbox->envValue('DB_PASSWORD'))->toBe($password)
        ->and($this->sandbox->envValue('APP_NAME'))->toBe('Prova');
});

it('non lascia due definizioni della stessa variabile nel file', function (): void {
    /*
     * Con due righe `DB_PASSWORD=` vince l'ultima, e chi legge il file a
     * occhio vede la prima: è il modo più veloce per perdere un pomeriggio.
     */
    app(EnvWriter::class)->write(['DB_PASSWORD' => 'segreta', 'APP_NAME' => 'Prova']);

    $lines = preg_grep('/^DB_PASSWORD[ \t]*=/m', explode("\n", $this->sandbox->envContents()));

    expect($lines)->toHaveCount(1);
});

it('sostituisce una variabile che nel modello è commentata, senza aggiungerne una seconda', function (): void {
    /*
     * `.env.example` tiene commentate le variabili che di norma restano al
     * valore predefinito (`# CACHE_PREFIX=`). Scriverne una senza riconoscere
     * la riga commentata produrrebbe un file con la chiave sia spenta sia
     * accesa, e la lettura a occhio direbbe la cosa sbagliata.
     */
    app(EnvWriter::class)->write(['CACHE_PREFIX' => 'eventi_']);

    $lines = preg_grep('/CACHE_PREFIX[ \t]*=/', explode("\n", $this->sandbox->envContents()));

    expect($lines)->toHaveCount(1)
        ->and($this->sandbox->envValue('CACHE_PREFIX'))->toBe('eventi_');
});

it('rilegge identiche tutte le variabili che il wizard scrive davvero', function (): void {
    /*
     * Il caso d'insieme: tredici valori scritti in una volta sola, come li
     * scrive l'installer, ciascuno con dentro un carattere che il parser
     * potrebbe interpretare. Non basta che ognuno funzioni da solo — devono
     * funzionare tutti nello stesso file.
     */
    $values = [
        'APP_NAME' => 'Eventi "in città" & dintorni',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'https://eventi.example.test',
        'DB_CONNECTION' => 'mariadb',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3307',
        'DB_DATABASE' => 'eventi_prod',
        'DB_USERNAME' => 'utente_con_prefisso',
        'DB_PASSWORD' => 'p#a$s\\s"w\'o`rd =',
        'MAIL_MAILER' => 'smtp',
        'MAIL_PASSWORD' => 'posta #1 $segreta',
        'CITY_DEFAULT_SLUG' => 'padova',
    ];

    app(EnvWriter::class)->write($values);

    foreach ($values as $key => $value) {
        expect($this->sandbox->envValue($key))->toBe($value);
    }
});
