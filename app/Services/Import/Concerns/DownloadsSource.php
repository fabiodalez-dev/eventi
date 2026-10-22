<?php

declare(strict_types=1);

namespace App\Services\Import\Concerns;

use App\DTOs\ImportMapping;
use App\Exceptions\ImportException;
use App\Models\ImportSource;
use App\Services\Http\BoundedStream;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Il modo in cui un driver scarica la propria sorgente: la guardia sugli
 * indirizzi, i redirect contati a mano, il limite di byte letto a pezzi, le
 * credenziali. Era dentro il driver ICS; il CSV ne ha bisogno identico, e
 * due copie di un controllo di sicurezza sono una che prima o poi diverge.
 *
 * Chi lo usa dichiara `$guard` (ImportUrlGuard) e `acceptHeader()`.
 */
trait DownloadsSource
{
    private const CHUNK_BYTES = 65536;

    abstract protected function acceptHeader(): string;

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
    protected function download(ImportSource $source): string
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

        return $body;
    }

    /**
     * Le credenziali sono cifrate dal cast del model. La forma decide il modo:
     * `utente:password` è un'autenticazione di base, tutto il resto è un token
     * al portatore — che è come i calendari privati di Google e Nextcloud si
     * fanno leggere quando non usano un URL segreto.
     */
    protected function request(ImportSource $source, string $url, bool $sendCredentials = true): PendingRequest
    {
        $address = $this->guard->resolvedAddress($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $request = Http::timeout(config()->integer('import.timeout'))
            ->connectTimeout(config()->integer('import.connect_timeout'))
            ->setHandler(new CurlHandler)
            ->withUserAgent(config()->string('app.name').' calendar import')
            ->withHeaders(['Accept' => $this->acceptHeader()])
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

    /**
     * Il corpo della risposta letto a blocchi, con il limite applicato durante
     * la lettura. `Content-Length`, quando c'è, evita perfino di cominciare —
     * ma non ci si fida solo di quello: è un'intestazione, cioè una promessa
     * di chi risponde, e una risposta senza lunghezza dichiarata o con una
     * lunghezza falsa è proprio il caso da fermare.
     */
    protected function read(Response $response): string
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
}
