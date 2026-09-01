<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Sentry\Event;
use Sentry\Laravel\Integration;
use Sentry\State\HubInterface;
use Sentry\Transport\ResultStatus;

/**
 * §16 chiede il tracciamento degli errori. Il requisito verificato qui è
 * l'altro, quello che si scopre solo sbagliandolo: **senza DSN non deve
 * rompere niente**.
 *
 * Sviluppo, test e integrazione continua girano senza DSN. Se il pacchetto
 * tentasse comunque una connessione, ogni eccezione del progetto costerebbe un
 * timeout di rete, e ogni test che ne solleva una diventerebbe lento e
 * intermittente per una ragione che non ha nulla a che vedere col codice.
 */
it('nasce senza DSN', function (): void {
    expect(config('sentry.dsn'))->toBeEmpty()
        ->and(app(HubInterface::class)->getClient()?->getOptions()->getDsn())->toBeNull();
});

/**
 * La prova che non invia: il trasporto risponde «saltato» invece di aprire una
 * connessione. È il punto esatto in cui un evento uscirebbe dal processo.
 */
it('senza DSN nessun evento lascia il processo', function (): void {
    $transport = app(HubInterface::class)->getClient()?->getTransport();

    expect($transport)->not->toBeNull();

    $result = $transport->send(Event::createEvent());

    expect($result->getStatus())->toEqual(ResultStatus::skipped());
});

it('catturare un\'eccezione non solleva eccezioni', function (): void {
    Integration::captureUnhandledException(new RuntimeException('un guasto qualsiasi'));
})->throwsNoExceptions();

/**
 * `report()` è la strada che il codice del progetto usa davvero: passa dal
 * gestore delle eccezioni, quindi dalla riga `Integration::handles()` di
 * `bootstrap/app.php`.
 */
it('report() non solleva eccezioni con Sentry spento', function (): void {
    report(new RuntimeException('un guasto passato dal gestore'));
})->throwsNoExceptions();

/**
 * Il DSN arriva dall'ambiente: senza questa verifica, «spento» potrebbe voler
 * dire «non collegato a niente» anche quando qualcuno lo configura.
 */
it('legge il DSN dall\'ambiente', function (): void {
    /*
     * Si passa dal repository di `env()` e non da `$_ENV`.
     *
     * `env()` tiene i valori in un proprio archivio, riempito una volta sola
     * all'avvio leggendo il file `.env`: scrivere in `$_ENV` dopo non lo
     * cambia. Il test funzionava solo dove la variabile NON era dichiarata
     * — il caso di uno sviluppo qualunque — e falliva dove lo era, per esempio
     * in integrazione continua, che parte da `.env.example`. Un test che
     * dipende dall'assenza di una riga in un file non versionato non verifica
     * quello che dice di verificare.
     */
    $repository = Env::getRepository();
    $precedente = $repository->get('SENTRY_LARAVEL_DSN');

    $repository->set('SENTRY_LARAVEL_DSN', 'https://chiave@sentry.example.test/1');

    try {
        $config = require config_path('sentry.php');
    } finally {
        if ($precedente === null) {
            $repository->clear('SENTRY_LARAVEL_DSN');
        } else {
            $repository->set('SENTRY_LARAVEL_DSN', $precedente);
        }
    }

    expect($config['dsn'])->toBe('https://chiave@sentry.example.test/1');
});

/**
 * §16, privacy: «raccogliere il minimo». Indirizzo IP, cookie, corpo della
 * richiesta e identità di chi era collegato non escono da qui, e nemmeno i
 * valori dei parametri delle interrogazioni SQL.
 */
it('non manda dati personali', function (): void {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse();
});

it('non traccia le richieste del monitor di stato', function (): void {
    expect(config('sentry.ignore_transactions'))->toContain('/stato');
});
