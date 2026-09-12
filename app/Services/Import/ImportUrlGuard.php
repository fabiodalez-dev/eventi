<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Exceptions\ImportException;

/**
 * L'indirizzo di una sorgente lo scrive una persona e lo scarica **il
 * server**: è la definizione di SSRF. Senza un controllo, chiunque possa
 * dichiarare un calendario può far chiamare al server la propria rete interna
 * — `http://127.0.0.1:8080/`, `http://169.254.169.254/` dei metadati di una
 * macchina virtuale, la stampante in `192.168.1.x` — e leggerne la risposta
 * attraverso i messaggi di errore.
 *
 * Il controllo è in tre strati, e servono tutti e tre:
 *
 * 1. **Lo schema.** Solo `http` e `https`. `file://`, `gopher://`, `ftp://` e
 *    soprattutto `php://` non descrivono un calendario remoto: descrivono un
 *    modo di leggere il disco o di parlare con un servizio interno.
 * 2. **L'indirizzo scritto nell'URL**, quando è già un IP.
 * 3. **Gli indirizzi in cui il nome si risolve.** È lo strato che conta
 *    davvero: `interno.esempio.it` non somiglia a niente di sospetto e può
 *    puntare a `10.0.0.5`. Si guardano **tutti** gli indirizzi del nome, non
 *    il primo.
 *
 * Il quarto strato non è qui ma in `IcsImportDriver`: ogni **redirect** viene
 * ricontrollato da questa stessa classe prima di essere seguito. Un server
 * pubblico che risponde `302 Location: http://127.0.0.1/` aggirerebbe
 * altrimenti tutti e tre i controlli qui sopra.
 *
 * Un nome che non si risolve affatto viene lasciato passare: non c'è alcun
 * indirizzo da raggiungere, la connessione fallirà e il guasto verrà
 * raccontato come irraggiungibilità. Trattarlo come un attacco trasformerebbe
 * un DNS che non risponde in un errore di configurazione permanente.
 */
final class ImportUrlGuard
{
    /**
     * Le reti che un calendario pubblico non abita mai.
     *
     * Sono scritte qui e non in `config/import.php` di proposito: un elenco di
     * sicurezza modificabile da un file di configurazione è un elenco che una
     * distrazione può svuotare.
     *
     * @var list<string>
     */
    private const BLOCKED = [
        // IPv4
        '0.0.0.0/8',          // «questa rete»
        '10.0.0.0/8',         // privata
        '100.64.0.0/10',      // CGNAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, e i metadati delle macchine virtuali
        '172.16.0.0/12',      // privata
        '192.0.0.0/24',       // assegnazioni di protocollo IETF
        '192.168.0.0/16',     // privata
        '198.18.0.0/15',      // benchmark fra reti
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // riservata

        // IPv6
        '::/128',             // indirizzo non specificato
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4 mappato in IPv6: `::ffff:127.0.0.1`
        '64:ff9b::/96',       // NAT64, che traduce verso IPv4
        '100::/64',           // discard-only
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * I nomi che valgono come loopback a prescindere da come si risolvono.
     *
     * @var list<string>
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return string l'indirizzo ripulito, pronto per la richiesta
     *
     * @throws ImportException quando l'indirizzo non è scaricabile in sicurezza
     */
    public function assert(string $url): string
    {
        $problem = $this->problem($url);

        if ($problem !== null) {
            throw $problem;
        }

        return trim($url);
    }

    /** Resolve immediately before connecting; never fall back to an unverified lookup. */
    public function resolvedAddress(string $url): ?string
    {
        $this->assert($url);
        $host = $this->host((string) parse_url($url, PHP_URL_HOST));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw ImportException::unreachable($url, 'DNS senza indirizzi pubblici');
        }
        foreach ($addresses as $address) {
            if ($this->isBlocked($address)) {
                throw ImportException::privateAddress($address);
            }
        }

        return $addresses[0];
    }

    /**
     * La stessa verifica, come domanda invece che come guasto: è ciò che
     * permette al modulo di dirlo **mentre** si scrive l'indirizzo, invece di
     * lasciarlo scoprire alla prima esecuzione.
     */
    public function reject(string $url): ?string
    {
        return $this->problem($url)?->getMessage();
    }

    private function problem(string $url): ?ImportException
    {
        $url = trim($url);

        if ($url === '') {
            return ImportException::noUrl();
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return ImportException::invalidUrl();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return ImportException::unsupportedScheme();
        }

        $host = $this->host($parts['host']);

        if ($host === '') {
            return ImportException::invalidUrl();
        }

        if (in_array(strtolower($host), self::BLOCKED_HOSTS, true)) {
            return ImportException::privateAddress($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isBlocked($host) ? ImportException::privateAddress($host) : null;
        }

        foreach ($this->resolver->resolve($host) as $address) {
            if ($this->isBlocked($address)) {
                return ImportException::privateAddress($address);
            }
        }

        return null;
    }

    /**
     * Il nome senza le parentesi degli IPv6 e senza il punto finale della
     * radice: `[::1]` e `esempio.it.` sono lo stesso indirizzo di `::1` e
     * `esempio.it`, e chi filtra la forma scritta e non quella normalizzata
     * lascia passare la seconda.
     */
    private function host(string $host): string
    {
        $host = trim($host);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return rtrim($host, '.');
    }

    private function isBlocked(string $address): bool
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            // Un indirizzo che non si lascia nemmeno interpretare non si scarica.
            return true;
        }

        foreach (self::BLOCKED as $network) {
            if ($this->inNetwork($binary, $network)) {
                return true;
            }
        }

        /*
         * La rete pubblica per esclusione: le bandiere di `filter_var` sono
         * una seconda opinione sulle stesse famiglie, e coprono i casi che un
         * elenco scritto a mano dimentica.
         */
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function inNetwork(string $binary, string $network): bool
    {
        [$subnet, $bits] = explode('/', $network);

        $range = @inet_pton($subnet);

        if ($range === false || strlen($range) !== strlen($binary)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($binary, $range, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($range[$bytes]) & $mask);
    }
}
