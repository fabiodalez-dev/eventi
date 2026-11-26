<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\ImportSource;

/**
 * L'indirizzo di una sorgente di import come lo mostra l'app della
 * redazione (decisione 5: i segreti non tornano in lettura).
 *
 * Un indirizzo è esso stesso un segreto quando apre un calendario privato:
 * quelli «segreti» di Google (`…/private-<hash>/basic.ics`), quelli con un
 * gettone nel percorso o nella query (Nextcloud, Outlook), e ogni indirizzo
 * di una sorgente che ha credenziali (chi lo vede sa dove le credenziali
 * aprono). In questi casi l'app riceve solo schema e host, seguiti da «…»;
 * lo stesso dentro `last_error` e nei messaggi delle esecuzioni, che citano
 * l'indirizzo.
 *
 * Il pannello del sito continua a mostrare l'indirizzo per intero: è lì che
 * si cambia.
 */
final class ImportUrlMask
{
    public const ELLIPSIS = '…';

    /** Parole che in un percorso o in una query indicano un accesso privato. */
    private const SECRET_WORDS = ['private', 'secret', 'token', 'apikey', 'api_key', 'access_key', 'signature', 'password', 'passwd'];

    /** Un gettone: almeno 16 lettere e cifre di fila, con almeno una cifra e una lettera. */
    private const TOKEN_PATTERN = '/(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{16,}/';

    private const URL_PATTERN = '~https?://[^\s<>"\'()\[\]]+~i';

    /** Vero se l'indirizzo da solo apre qualcosa di privato. */
    public static function urlIsSecret(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return true;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return true;
        }

        $rest = rawurldecode(($parts['path'] ?? '').'?'.($parts['query'] ?? '').'#'.($parts['fragment'] ?? ''));
        $lower = mb_strtolower($rest);
        foreach (self::SECRET_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return preg_match(self::TOKEN_PATTERN, $rest) === 1;
    }

    public static function isSecret(?string $url, bool $hasCredentials): bool
    {
        return filled($url) && ($hasCredentials || self::urlIsSecret($url));
    }

    public static function sourceIsSecret(ImportSource $source): bool
    {
        return self::isSecret($source->url, ImportUrlOrigin::hasCredentials($source));
    }

    /** `schema://host[:porta]/…`. */
    public static function mask(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return self::ELLIPSIS;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.strtolower($parts['host']).$port.'/'.self::ELLIPSIS;
    }

    /** L'indirizzo da mostrare: mascherato se è un segreto, altrimenti così com'è. */
    public static function show(?string $url, bool $hasCredentials): ?string
    {
        if ($url === null) {
            return null;
        }

        return self::isSecret($url, $hasCredentials) ? self::mask($url) : $url;
    }

    public static function forSource(ImportSource $source): ?string
    {
        return self::show($source->url, ImportUrlOrigin::hasCredentials($source));
    }

    /**
     * Un testo (un errore, un messaggio di esecuzione) con ogni indirizzo
     * mascherato, quando la sorgente è segreta; altrimenti così com'è.
     */
    public static function text(?string $text, bool $secret): ?string
    {
        if ($text === null || ! $secret) {
            return $text;
        }

        return (string) preg_replace_callback(self::URL_PATTERN, static function (array $match): string {
            $url = rtrim($match[0], '.,;:');

            return self::mask($url).substr($match[0], strlen($url));
        }, $text);
    }
}
