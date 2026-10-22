<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Da un indirizzo YouTube o Vimeo alla sua forma incorporabile.
 *
 * Solo questi due fornitori, e solo con gli indirizzi che proteggono di più
 * chi guarda: `youtube-nocookie.com` non deposita cookie finché non si preme
 * play, Vimeo con `dnt=1` non traccia. Tutto il resto — un link a un sito
 * qualunque, un file video, un indirizzo scritto male — risponde `null` e la
 * scheda non mostra niente: meglio nessun video di un riquadro rotto.
 *
 * La pagina non carica l'iframe finché la persona non lo chiede: fino a
 * quel momento c'è un riquadro nostro, senza richieste a terzi.
 */
final readonly class VideoEmbed
{
    private function __construct(
        public string $provider,
        public string $id,
        public string $embedUrl,
        public string $watchUrl,
    ) {}

    public static function from(?string $url): ?self
    {
        $url = trim((string) $url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^(www|m)\./', '', $host) ?? $host;
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            $id = null;
            if ($path === '/watch' && is_string($query['v'] ?? null)) {
                $id = $query['v'];
            } elseif (preg_match('#^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})#', $path, $m) === 1) {
                $id = $m[1];
            }

            return self::youtube($id);
        }
        if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})#', $path, $m) === 1) {
            return self::youtube($m[1]);
        }
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(\d{6,12})(?:/|$)#', $path, $m) === 1) {
            return new self('vimeo', $m[1], 'https://player.vimeo.com/video/'.$m[1].'?dnt=1', 'https://vimeo.com/'.$m[1]);
        }

        return null;
    }

    private static function youtube(?string $id): ?self
    {
        if ($id === null || preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) {
            return null;
        }

        return new self('youtube', $id, 'https://www.youtube-nocookie.com/embed/'.$id.'?rel=0', 'https://www.youtube.com/watch?v='.$id);
    }

    public function providerLabel(): string
    {
        return $this->provider === 'youtube' ? 'YouTube' : 'Vimeo';
    }
}
