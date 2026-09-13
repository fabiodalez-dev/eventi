<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Http\BoundedStream;
use DOMDocument;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FacebookHttpSource
{
    public const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly ImportUrlGuard $guard) {}

    /** @return list<string> */
    public function scripts(string $url): array
    {
        if (! preg_match('~^https://www\.facebook\.com/events/[0-9]+/$~D', $url)) {
            throw new RuntimeException('Link Facebook non valido.');
        }
        $requestUrl = $url.'?locale=it_IT';
        $address = $this->guard->resolvedAddress($requestUrl);
        $response = Http::timeout(25)->connectTimeout(5)->withHeaders([
            'User-Agent' => 'inCitta-event-import/1.0 (+https://eventi.fabiodalez.it)',
            'Accept-Language' => 'it-IT,it;q=0.9',
            'Accept' => 'text/html,application/xhtml+xml',
            'DPR' => '3',
        ])->setHandler(new CurlHandler)->withOptions([
            'proxy' => '', 'allow_redirects' => false, 'cookies' => false,
            'sink' => new BoundedStream(self::MAX_BYTES),
            'curl' => $address === null ? [] : [CURLOPT_RESOLVE => ['www.facebook.com:443:'.(str_contains($address, ':') ? '['.$address.']' : $address)]],
        ])->get($requestUrl)->throw();
        if (! $response->successful()) {
            throw new RuntimeException('Facebook non ha restituito la pagina pubblica senza reindirizzamenti.');
        }
        $html = $response->body();
        if (strlen($html) > self::MAX_BYTES) {
            throw new RuntimeException('La pagina Facebook supera la dimensione massima consentita.');
        }

        return $this->extractScripts($html);
    }

    /** Read JSON text only: never execute page scripts or load external resources.
     * @return list<string>
     */
    public function extractScripts(string $html): array
    {
        if ($html === '' || strlen($html) > self::MAX_BYTES) {
            throw new RuntimeException('La pagina Facebook è vuota o troppo grande.');
        }
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $scripts = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            if (count($scripts) >= 512) {
                throw new RuntimeException('La pagina Facebook contiene troppi blocchi dati.');
            }
            $text = trim($script->textContent);
            if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
                $scripts[] = $text;
            }
        }

        return $scripts;
    }
}
