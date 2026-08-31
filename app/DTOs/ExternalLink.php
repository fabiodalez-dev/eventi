<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Un link esterno di un evento: un'etichetta e un indirizzo.
 *
 * La colonna `events.external_links` di §7.6 è un JSON, e un JSON senza un
 * punto solo che ne conosca la forma diventa quello che ogni scrittore decide
 * che sia. Qui la forma è dichiarata una volta — `{"label": …, "url": …}` — e
 * chi legge non deve indovinare.
 *
 * **Questa classe è anche l'unico parser di indirizzi del progetto.** Sono URL
 * scritti da terzi (redazione, gestori dei locali, un domani gli import), e
 * finiscono in un `href` della scheda pubblica: la domanda "questo indirizzo è
 * accettabile?" deve avere una risposta sola. `App\Rules\ExternalLinks` la usa
 * per spiegare all'utente *cosa* non va; `App\Casts\AsExternalLinks` la usa per
 * non far mai entrare nel modello una riga che il sito non potrebbe mostrare.
 *
 * Sono ammessi **soltanto** `http` e `https`: uno schema `javascript:`,
 * `data:` o `mailto:` in un `href` è, nell'ordine, esecuzione di codice nella
 * pagina, contenuto arbitrario travestito da link e un errore di categoria.
 * Le credenziali nell'indirizzo (`https://banca.it@dominio-ostile.tld`) sono
 * rifiutate per la stessa ragione: la parte che l'utente legge non è il posto
 * dove finisce.
 */
final readonly class ExternalLink
{
    /**
     * Un'etichetta è una parola o due: «Evento Facebook», «Sito ufficiale».
     * Oltre questa misura non è più un'etichetta ma una frase, e nell'elenco
     * della scheda pubblica andrebbe a capo tre volte.
     */
    public const int MAX_LABEL_LENGTH = 40;

    /**
     * @var list<string>
     */
    public const array ALLOWED_SCHEMES = ['http', 'https'];

    private function __construct(
        public string $label,
        public string $url,
    ) {}

    public static function make(string $label, string $url): self
    {
        return new self(trim($label), trim($url));
    }

    /**
     * La riga come arriva da un modulo, da un JSON o da un import: se non è
     * utilizzabile si ottiene `null`, non un oggetto a metà.
     */
    public static function tryFrom(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value->isAcceptable() ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $link = self::make(self::text($value['label'] ?? null), self::text($value['url'] ?? null));

        return $link->isAcceptable() ? $link : null;
    }

    public function isAcceptable(): bool
    {
        return $this->label !== ''
            && mb_strlen($this->label) <= self::MAX_LABEL_LENGTH
            && self::isAcceptableUrl($this->url);
    }

    public static function isAcceptableUrl(string $url): bool
    {
        return self::hasAllowedScheme($url) && self::hasUsableHost($url) && ! self::hasCredentials($url);
    }

    public static function hasAllowedScheme(string $url): bool
    {
        $scheme = self::part($url, PHP_URL_SCHEME);

        return $scheme !== null && in_array(mb_strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }

    /**
     * Un host utilizzabile è un indirizzo IP oppure un nome di dominio con un
     * suffisso vero. `https://` senza host e `https://esempio` non sono
     * indirizzi raggiungibili da un lettore del sito: sono refusi, e vanno
     * segnalati a chi li ha scritti mentre è ancora davanti al modulo.
     */
    public static function hasUsableHost(string $url): bool
    {
        $host = self::part($url, PHP_URL_HOST);

        if ($host === null || $host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $ascii = self::toAscii($host);

        if ($ascii === null) {
            return false;
        }

        return filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/\.[a-z]{2,}$/i', $ascii) === 1;
    }

    public static function hasCredentials(string $url): bool
    {
        return self::part($url, PHP_URL_USER) !== null || self::part($url, PHP_URL_PASS) !== null;
    }

    /**
     * @return array{label: string, url: string}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'url' => $this->url];
    }

    /**
     * I nomi ricorrenti, offerti come suggerimento e mai imposti: l'elenco
     * arriva a `datalist`, che propone senza chiudere (§10.2 — chi compila
     * deve poter scrivere «Podcast della serata» senza chiedere il permesso).
     *
     * @return list<string>
     */
    public static function suggestedLabels(): array
    {
        /** @var array<int, string> $labels */
        $labels = (array) __('events.external_links.suggestions');

        return array_values(array_map(strval(...), $labels));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * `parse_url` restituisce `false` sugli indirizzi malformati e omette le
     * chiavi assenti: entrambe le cose diventano `null`, così chi chiama ha
     * una risposta sola da controllare.
     */
    private static function part(string $url, int $component): ?string
    {
        if ($url === '') {
            return null;
        }

        $value = parse_url($url, $component);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Un dominio internazionalizzato (`comuneèbello.it`) è legittimo ma non
     * passa `FILTER_VALIDATE_DOMAIN`, che ragiona in ASCII: si converte prima
     * di giudicarlo, invece di rifiutarlo per un alfabeto.
     */
    private static function toAscii(string $host): ?string
    {
        if (mb_check_encoding($host, 'ASCII')) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? null : $ascii;
    }
}
