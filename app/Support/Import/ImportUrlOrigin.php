<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\ImportSource;

/**
 * L'origine di un indirizzo di import: schema, host e porta (quella
 * predefinita dello schema se non è scritta). È la stessa terna che
 * `DownloadsSource` guarda per decidere se le credenziali partono con una
 * richiesta: le credenziali di una sorgente valgono solo per la sua origine.
 *
 * Da qui la regola della revisione di sicurezza dell'ondata 3: chi cambia
 * l'origine di una sorgente con credenziali le manderebbe al nuovo host alla
 * prima anteprima. Dall'app il cambio si rifiuta (le credenziali si gestiscono
 * dal sito); nel pannello, se le credenziali non vengono riscritte insieme al
 * nuovo indirizzo, si cancellano.
 */
final class ImportUrlOrigin
{
    /** `schema://host:porta` in minuscolo, oppure `null` per un indirizzo vuoto o senza host. */
    public static function of(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = rtrim(strtolower($parts['host']), '.');
        $port = $parts['port'] ?? match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    public static function same(?string $first, ?string $second): bool
    {
        return self::of($first) === self::of($second);
    }

    /** Vero se `$url` porterebbe la sorgente su un'altra origine. */
    public static function changes(ImportSource $source, mixed $url): bool
    {
        return ! self::same($source->url, is_string($url) ? $url : null);
    }

    /** Vero se la sorgente ha credenziali salvate (senza decifrarle). */
    public static function hasCredentials(ImportSource $source): bool
    {
        return filled($source->getRawOriginal('credentials'));
    }

    /**
     * Il modulo del pannello: vero se l'indirizzo cambia origine, la sorgente
     * ha credenziali e il campo delle credenziali non è stato riscritto (un
     * campo lasciato vuoto non arriva nei dati).
     *
     * @param  array<string, mixed>  $data
     */
    public static function forgetsCredentials(ImportSource $source, array $data): bool
    {
        return self::hasCredentials($source)
            && array_key_exists('url', $data)
            && blank($data['credentials'] ?? null)
            && self::changes($source, $data['url']);
    }
}
