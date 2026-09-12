<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Import\HostResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * I calendari di `tests/Fixtures/ics/`, serviti come li servirebbe un server.
 *
 * Sono **file veri**, non stringhe costruite nel test: un ICS scritto al volo
 * dentro un'asserzione tende a somigliare a ciò che il codice si aspetta,
 * mentre un file su disco somiglia a quello che manda Google Calendar. Le
 * pieghe del formato — il `VTIMEZONE` incorporato, le virgole con l'escape
 * dentro `LOCATION`, l'ordine delle proprietà — si conservano solo in un file.
 *
 * **Il calendario che cambia fra un'esecuzione e l'altra.** Metà delle prove
 * sull'idempotenza consiste nel servire due versioni diverse dello stesso
 * indirizzo. `Http::fake()` però **somma** gli stub e serve il primo che
 * risponde: chiamarlo due volte lascerebbe in vigore la prima versione, e i
 * test passerebbero raccontando una cosa che non è successa. La risposta vive
 * quindi in uno stato che questa classe riscrive, e lo stub registrato è
 * sempre lo stesso e la va a leggere.
 */
final class IcsFixtures
{
    public const URL = 'https://calendario.test/eventi.ics';

    private static string $body = '';

    private static int $status = 200;

    private static ?Throwable $failure = null;

    public static function body(string $name): string
    {
        return (string) file_get_contents(self::path($name));
    }

    public static function path(string $name): string
    {
        return __DIR__.'/../Fixtures/ics/'.$name.'.ics';
    }

    /**
     * Il calendario servito con lo stato e le intestazioni di un server reale.
     */
    public static function fake(string $name, int $status = 200): void
    {
        self::serve(self::body($name), $status);
    }

    /**
     * Un calendario cucito su misura a partire dalle singole voci: serve ai
     * casi in cui è il **cambiamento** fra un'esecuzione e l'altra a essere
     * sotto esame, non il formato.
     *
     * @param  list<string>  $events  corpi `VEVENT` senza `BEGIN`/`END`
     */
    public static function fakeEvents(array $events): void
    {
        $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//inCitta//test//IT\r\nCALSCALE:GREGORIAN\r\n";

        foreach ($events as $event) {
            $body .= "BEGIN:VEVENT\r\n".str_replace("\n", "\r\n", trim($event))."\r\nEND:VEVENT\r\n";
        }

        self::serve($body.'END:VCALENDAR', 200);
    }

    /**
     * La rete che non risponde affatto: non uno stato HTTP, un'eccezione di
     * connessione. È il caso in cui una cancellazione automatica
     * distruggerebbe dati veri.
     */
    public static function fakeFailure(Throwable $failure): void
    {
        self::$failure = $failure;
        self::register();
    }

    private static function serve(string $body, int $status): void
    {
        self::$body = $body;
        self::$status = $status;
        self::$failure = null;

        self::register();
    }

    private static function register(): void
    {
        app()->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake([
            self::URL => function () {
                if (self::$failure !== null) {
                    throw self::$failure;
                }

                return Http::response(self::$body, self::$status, [
                    'Content-Type' => 'text/calendar; charset=utf-8',
                ]);
            },
        ]);
    }
}
