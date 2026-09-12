<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\DTOs\ImportedEventDto;
use App\DTOs\ImportMapping;
use App\Exceptions\ImportException;
use App\Models\ImportSource;
use App\Services\Http\BoundedStream;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as IcsDateTime;
use Sabre\VObject\Property\ICalendar\Duration as IcsDuration;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Il driver ICS di §14.2, il primo dei tre passi `fetch → parse → map`.
 *
 * **La parte difficile è il tempo, non il formato.** Un file iCalendar può
 * scrivere la stessa serata in tre modi, e sono tre significati diversi:
 *
 * | forma | esempio | significato |
 * |---|---|---|
 * | fluttuante | `DTSTART:20260905T213000` | «le 21:30, ovunque tu sia» |
 * | UTC | `DTSTART:20260905T193000Z` | un istante assoluto |
 * | con `TZID` | `DTSTART;TZID=Europe/Rome:20260905T213000` | le 21:30 **di Roma** |
 *
 * Le tre convergono qui su un unico istante UTC. La fluttuante prende il fuso
 * della città della sorgente (o quello dichiarato in `mapping.timezone`),
 * perché un calendario di un locale di Padova che scrive «21:30» intende le
 * 21:30 di Padova; le altre due portano già con sé la propria risposta.
 * `sabre/vobject` risolve anche i `VTIMEZONE` **incorporati** nel file, che è
 * il modo in cui Outlook e i CalDAV aziendali dichiarano fusi con nomi che il
 * database dei fusi non conosce.
 *
 * Le altre trappole del formato, tutte gestite:
 *
 * - `VALUE=DATE` è un evento di intera giornata (`is_all_day`), non un evento
 *   a mezzanotte;
 * - `DTEND` può mancare — allora la durata la decide la categoria (§8.3) — o
 *   essere sostituito da `DURATION`;
 * - `DTEND` di un evento di intera giornata è **esclusivo**: un evento di un
 *   giorno solo finisce il giorno dopo, e preso alla lettera diventerebbe di
 *   due giorni;
 * - `EXDATE` esclude date da una serie e va riscritto in **ora locale**,
 *   perché è così che `GenerateOccurrencesAction` lo rilegge;
 * - `RECURRENCE-ID` marca l'eccezione di una serie, che porta lo stesso `UID`
 *   della serie e va tenuta distinta da essa.
 */
final class IcsImportDriver implements ImportSourceDriver
{
    /**
     * Byte letti per volta dalla risposta. Un blocco piccolo non rende la
     * lettura più sicura, uno enorme vanificherebbe il limite: 64 KB è la
     * misura in cui un calendario normale arriva in pochi giri e uno abnorme
     * si ferma subito.
     */
    private const CHUNK_BYTES = 65536;

    public function __construct(private readonly ImportUrlGuard $guard) {}

    /*
     * Nota sulle annotazioni `VEvent<mixed>`: i nodi di `sabre/vobject` sono
     * attraversabili ma la libreria non dichiara che cosa restituisca
     * l'iterazione. Il tipo di valore va quindi scritto qui, ed è `mixed`
     * perché è la verità: nessuno lo garantisce. Per la stessa ragione le
     * proprietà si leggono con `select()` e non con `$event->DTSTART`, che
     * passa da `__get()` e non è verificabile da nessuna analisi.
     */

    /**
     * Il fuso già risolto, per sorgente: `map()` viene chiamato una volta per
     * voce e il fuso è lo stesso per tutte.
     *
     * @var array<int, string>
     */
    private array $timezones = [];

    /**
     * Le voci grezze sono `VEvent` di `sabre/vobject`; il tipo dichiarato resta
     * quello dell'interfaccia perché il contratto di `ImportSourceDriver` è che
     * `fetch()` restituisca voci opache e che sia `map()` a riconoscerle.
     *
     * @return list<mixed>
     */
    public function fetch(ImportSource $source): array
    {
        $body = $this->download($source);

        try {
            $document = Reader::read($body);
        } catch (Throwable $exception) {
            throw ImportException::unreadable($exception->getMessage());
        }

        if (! $document instanceof VCalendar) {
            throw ImportException::notACalendar();
        }

        $events = [];

        foreach ($document->select('VEVENT') as $component) {
            if ($component instanceof VEvent) {
                $events[] = $component;
            }
        }

        return $events;
    }

    public function map(mixed $raw, ImportSource $source): ?ImportedEventDto
    {
        if (! $raw instanceof VEvent) {
            return null;
        }

        $uid = $this->text($raw, 'UID');
        $title = $this->text($raw, 'SUMMARY');

        if ($uid === null || $title === null) {
            return null;
        }

        $timezone = $this->timezone($source);
        $zone = new DateTimeZone($timezone);

        $dtstart = $this->dateProperty($raw, 'DTSTART');

        if ($dtstart === null) {
            return null;
        }

        try {
            $start = $dtstart->getDateTime($zone);
        } catch (Throwable) {
            return null;
        }

        if (! $start instanceof DateTimeImmutable) {
            return null;
        }

        $isAllDay = ! $dtstart->hasTime();
        $startsAt = CarbonImmutable::instance($start);
        $endsAt = $this->endsAt($raw, $startsAt, $zone, $isAllDay);

        return new ImportedEventDto(
            uid: $uid,
            recurrenceId: $this->recurrenceId($raw, $zone, $timezone),
            title: $title,
            startsAt: $startsAt->utc(),
            endsAt: $endsAt?->utc(),
            isAllDay: $isAllDay,
            description: $this->text($raw, 'DESCRIPTION'),
            location: $this->text($raw, 'LOCATION'),
            url: $this->text($raw, 'URL'),
            rrule: $this->text($raw, 'RRULE'),
            exdates: $this->exdates($raw, $zone, $timezone),
            until: $this->until($raw, $zone),
            isCancelled: strtoupper((string) ($this->text($raw, 'STATUS') ?? '')) === 'CANCELLED',
        );
    }

    // ------------------------------------------------------------- il trasporto

    /**
     * Lo scaricamento, che è l'unico punto in cui questo progetto va a
     * prendere un indirizzo scritto da qualcun altro. Tre difese, e nessuna
     * sostituisce le altre:
     *
     * 1. `ImportUrlGuard` prima della richiesta — schema e rete di
     *    destinazione (SSRF);
     * 2. lo stesso controllo su **ogni redirect**, perché un server pubblico
     *    che risponde `302 Location: http://127.0.0.1/` aggirerebbe il primo,
     *    più un tetto al numero di salti;
     * 3. un tetto ai byte **mentre arrivano**: la risposta si legge a blocchi
     *    e ci si ferma appena supera il limite. Chiedere l'intero corpo e
     *    misurarlo dopo significa averlo già tenuto tutto in memoria, che è
     *    esattamente ciò da cui il limite dovrebbe difendere.
     */
    private function download(ImportSource $source): string
    {
        $url = $this->guard->assert((string) $source->url);

        try {
            $origin = parse_url($url);
            for ($hop = 0; ; $hop++) {
                $response = $this->request($source, $url, parse_url($url, PHP_URL_HOST) === ($origin['host'] ?? null)
                    && parse_url($url, PHP_URL_SCHEME) === ($origin['scheme'] ?? null)
                    && parse_url($url, PHP_URL_PORT) === ($origin['port'] ?? null))->get($url);
                if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    break;
                }
                if ($hop >= config()->integer('import.max_redirects') || $response->header('Location') === '') {
                    throw ImportException::httpStatus($response->status());
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
                $response->close();
            }
        } catch (ConnectionException $exception) {
            throw ImportException::unreachable($url, $exception->getMessage());
        } catch (TransferException $exception) {
            // Troppi redirect, o un trasferimento interrotto: non è un guasto
            // del calendario, è un guasto del trasporto, e si racconta come tale.
            throw ImportException::unreachable($url, $exception->getMessage());
        }

        if ($response->failed()) {
            throw ImportException::httpStatus($response->status());
        }

        $body = $this->read($response);

        /*
         * Un ICS comincia sempre con `BEGIN:VCALENDAR`. Il controllo sta qui e
         * non dopo il parsing perché una pagina di errore HTML servita con
         * stato 200 — la risposta tipica di un portale che ha spostato il
         * calendario — darebbe altrimenti un messaggio incomprensibile.
         */
        if (! str_contains(strtoupper($body), 'BEGIN:VCALENDAR')) {
            throw ImportException::notACalendar();
        }

        return $body;
    }

    /**
     * Il corpo della risposta letto a blocchi, con il limite applicato durante
     * la lettura. `Content-Length`, quando c'è, evita perfino di cominciare —
     * ma non ci si fida solo di quello: è un'intestazione, cioè una promessa
     * di chi risponde, e una risposta senza lunghezza dichiarata o con una
     * lunghezza falsa è proprio il caso da fermare.
     */
    private function read(Response $response): string
    {
        $limit = config()->integer('import.max_bytes');
        $declared = $response->header('Content-Length');

        if (is_numeric($declared) && (int) $declared > $limit) {
            throw ImportException::tooLarge($limit);
        }

        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';

        while (! $stream->eof()) {
            $chunk = $stream->read(self::CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $limit) {
                $stream->close();

                throw ImportException::tooLarge($limit);
            }
        }

        return $body;
    }

    /**
     * Le credenziali sono cifrate dal cast del model. La forma decide il modo:
     * `utente:password` è un'autenticazione di base, tutto il resto è un token
     * al portatore — che è come i calendari privati di Google e Nextcloud si
     * fanno leggere quando non usano un URL segreto.
     */
    private function request(ImportSource $source, string $url, bool $sendCredentials = true): PendingRequest
    {
        $address = $this->guard->resolvedAddress($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $request = Http::timeout(config()->integer('import.timeout'))
            ->connectTimeout(config()->integer('import.connect_timeout'))
            ->setHandler(new CurlHandler)
            ->withUserAgent(config()->string('app.name').' calendar import')
            ->withHeaders(['Accept' => 'text/calendar, text/plain;q=0.9'])
            ->withOptions([
                'stream' => false,
                'proxy' => '',
                'allow_redirects' => false,
                'sink' => new BoundedStream(config()->integer('import.max_bytes')),
                'curl' => $address === null ? [] : [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]],
            ]);
        if (! $sendCredentials) {
            return $request;
        }

        $credentials = $source->credentials;

        if (! is_string($credentials) || trim($credentials) === '') {
            return $request;
        }

        $mapping = ImportMapping::fromSource($source);
        $basic = $mapping->auth === 'basic' || ($mapping->auth === null && str_contains($credentials, ':'));

        if ($basic) {
            [$user, $password] = array_pad(explode(':', $credentials, 2), 2, '');

            return $request->withBasicAuth($user, $password);
        }

        return $request->withToken($credentials);
    }

    // ------------------------------------------------------------------ il tempo

    /**
     * Il fuso in cui leggere le date **fluttuanti**: quello dichiarato dalla
     * sorgente, altrimenti quello della città. Le date con `TZID` e quelle in
     * UTC non lo usano — portano già la propria risposta.
     */
    private function timezone(ImportSource $source): string
    {
        $key = (int) $source->getKey();

        if (isset($this->timezones[$key])) {
            return $this->timezones[$key];
        }

        $declared = ImportMapping::fromSource($source)->timezone;
        $city = $source->city;

        return $this->timezones[$key] = $declared
            ?? ($city !== null ? $city->timezone : config()->string('app.timezone'));
    }

    /**
     * L'istante di fine, con le due sostituzioni ammesse dall'RFC 5545:
     * `DTEND` esplicito oppure `DURATION` sommata all'inizio. Quando manca
     * l'una e l'altra la durata la decide la categoria (§8.3), quindi si
     * restituisce `null` e non un'ora inventata.
     *
     * @param  VEvent<mixed>  $event
     */
    private function endsAt(VEvent $event, CarbonImmutable $startsAt, DateTimeZone $zone, bool $isAllDay): ?CarbonImmutable
    {
        $endsAt = null;
        $dtend = $this->dateProperty($event, 'DTEND');
        $duration = $this->durationProperty($event);

        if ($dtend !== null) {
            try {
                $end = $dtend->getDateTime($zone);
            } catch (Throwable) {
                $end = null;
            }

            $endsAt = $end instanceof DateTimeImmutable ? CarbonImmutable::instance($end) : null;
        } elseif ($duration !== null) {
            try {
                $endsAt = $startsAt->add($duration->getDateInterval());
            } catch (Throwable) {
                $endsAt = null;
            }
        }

        if ($endsAt === null) {
            return null;
        }

        if ($isAllDay) {
            /*
             * `DTEND` di un evento di intera giornata è **esclusivo**: una
             * giornata sola si scrive dal 5 al 6. Presa alla lettera
             * diventerebbe un evento di due giorni; sottratto un secondo,
             * torna a finire dentro il proprio giorno. Per la giornata singola
             * si preferisce comunque `null`, così che §8.3 possa chiudere
             * l'evento sull'orario di apertura del locale invece che a
             * mezzanotte.
             */
            if ($endsAt->lessThanOrEqualTo($startsAt->addDay())) {
                return null;
            }

            $endsAt = $endsAt->subSecond();
        }

        return $endsAt->greaterThan($startsAt) ? $endsAt : null;
    }

    /**
     * La fine della serie, letta dentro la `RRULE`.
     *
     * `UNTIL` in forma di sola data vale **fino a tutto** quel giorno: preso a
     * mezzanotte taglierebbe via l'ultima data della serie senza che nessuno
     * se ne accorga.
     *
     * @param  VEvent<mixed>  $event
     */
    private function until(VEvent $event, DateTimeZone $zone): ?CarbonImmutable
    {
        $rrule = $this->text($event, 'RRULE');

        if ($rrule === null || preg_match('/UNTIL=([^;]+)/i', $rrule, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1]);

        try {
            if (preg_match('/^\d{8}$/', $value) === 1) {
                return CarbonImmutable::createFromFormat('Ymd', $value, $zone)->endOfDay()->utc();
            }

            return CarbonImmutable::parse($value, $zone)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Le esclusioni della serie, scritte in **ora locale** della città:
     * `GenerateOccurrencesAction` le rilegge con quel fuso, e una data pura
     * esclude l'intera giornata mentre un istante esclude la sola occorrenza.
     *
     * @param  VEvent<mixed>  $event
     * @return list<string>
     */
    private function exdates(VEvent $event, DateTimeZone $zone, string $timezone): array
    {
        $exdates = [];

        foreach ($event->select('EXDATE') as $property) {
            if (! $property instanceof IcsDateTime) {
                continue;
            }

            try {
                $values = $property->getDateTimes($zone);
            } catch (Throwable) {
                continue;
            }

            foreach ($values as $value) {
                if (! $value instanceof DateTimeInterface) {
                    continue;
                }

                $local = CarbonImmutable::instance($value)->setTimezone($timezone);
                $exdates[] = $property->hasTime()
                    ? $local->format('Y-m-d H:i:s')
                    : $local->format('Y-m-d');
            }
        }

        return array_values(array_unique($exdates));
    }

    /**
     * L'identificativo dell'istanza sostituita, normalizzato: è la seconda
     * metà della chiave di idempotenza, e deve valere lo stesso valore a ogni
     * esecuzione anche se il feed cambia il modo di scriverlo.
     *
     * @param  VEvent<mixed>  $event
     */
    private function recurrenceId(VEvent $event, DateTimeZone $zone, string $timezone): ?string
    {
        $property = $this->dateProperty($event, 'RECURRENCE-ID');

        if ($property === null) {
            return null;
        }

        try {
            $value = $property->getDateTime($zone);
        } catch (Throwable) {
            return null;
        }

        if (! $value instanceof DateTimeImmutable) {
            return null;
        }

        $moment = CarbonImmutable::instance($value);

        return $property->hasTime()
            ? $moment->utc()->format('Ymd\THis\Z')
            : $moment->setTimezone($timezone)->format('Ymd');
    }

    // ------------------------------------------------------------------ il testo

    /**
     * @param  VEvent<mixed>  $event
     */
    private function text(VEvent $event, string $name): ?string
    {
        foreach ($event->select($name) as $property) {
            if (! $property instanceof Property) {
                continue;
            }

            $value = trim((string) $property);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * La prima proprietà con questo nome, se è una data.
     *
     * L'accesso avviene per `select()` e non per proprietà magica: `$event->DTSTART`
     * passa da `__get()`, che nessuna analisi statica può verificare — e un
     * nome di proprietà scritto male diventerebbe un `null` silenzioso invece
     * di un errore.
     *
     * @param  VEvent<mixed>  $event
     * @return IcsDateTime<mixed>|null
     */
    private function dateProperty(VEvent $event, string $name): ?IcsDateTime
    {
        foreach ($event->select($name) as $property) {
            if ($property instanceof IcsDateTime) {
                return $property;
            }
        }

        return null;
    }

    /**
     * @param  VEvent<mixed>  $event
     * @return IcsDuration<mixed>|null
     */
    private function durationProperty(VEvent $event): ?IcsDuration
    {
        foreach ($event->select('DURATION') as $property) {
            if ($property instanceof IcsDuration) {
                return $property;
            }
        }

        return null;
    }
}
