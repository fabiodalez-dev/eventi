<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Consent;
use App\Support\ContentVersion;
use App\Support\CurrentCity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * La full-page cache di §12.3: **scheletro** della pagina iniziale, di
 * "stasera", del weekend, delle categorie e dei locali attivi, per cinque
 * minuti, invalidata alla pubblicazione di un evento.
 *
 * ## Perché "scheletro" è la parola importante
 *
 * §12.3 avverte del conflitto: una finestra mobile di tre ore ("inizia tra
 * poco") non può stare dentro una pagina in cache — o la pagina è in cache e
 * la sezione mente, o la sezione è giusta e il TTFB crolla. La soluzione era
 * già stata presa in §11.2: "In corso" e "Inizia tra poco" sono un componente
 * Livewire caricato **dopo** il primo disegno (D25). Quello che finisce qui
 * dentro è tutto il resto, che a cinque minuti di distanza è identico a sé
 * stesso.
 *
 * ## Le due cose che una pagina in cache non può portarsi dietro
 *
 * 1. **Il token CSRF.** Sta nell'intestazione di ogni pagina e vale per una
 *    sessione sola: servito a un altro visitatore, ogni suo invio sarebbe un
 *    419. Nella copia in cache viene sostituito da un segnaposto e rimesso al
 *    volo su ogni risposta. Il token è una stringa casuale di quaranta
 *    caratteri: non si scambia per niente altro nel documento.
 * 2. **Chi sta guardando.** Una pagina di chi ha una sessione autenticata
 *    parla di lui — i suoi salvataggi, il suo nome. Non entra in cache e non
 *    viene servita dalla cache. Stessa cosa per una pagina che porta un
 *    messaggio di conferma appena lasciato in sessione.
 *
 * Le intestazioni non si conservano: si conserva il corpo. È ciò che evita di
 * riemettere a un visitatore il cookie di sessione di un altro — l'errore che
 * trasforma una cache in una falla.
 */
final class CachePage
{
    /**
     * Il segnaposto che prende il posto del token CSRF nella copia salvata.
     */
    private const CSRF_PLACEHOLDER = '@@csrf-token@@';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isCacheable($request)) {
            return $next($request);
        }

        $key = $this->key($request);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return response($this->restore($cached), 200)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('X-Page-Cache', 'hit');
        }

        $response = $next($request);

        if ($this->isStorable($response)) {
            $content = (string) $response->getContent();

            Cache::put(
                $key,
                str_replace(csrf_token(), self::CSRF_PLACEHOLDER, $content),
                now()->addMinutes(config()->integer('page_cache.ttl_minutes')),
            );
        }

        $response->headers->set('X-Page-Cache', 'miss');

        return $response;
    }

    /**
     * I soli parametri della query string che entrano nella chiave.
     *
     * Sono i filtri che i controller leggono davvero — quelli di
     * `EventFilterRequest`, quelli di `VenueFilterRequest` e i due nomi di
     * pagina della paginazione. È un elenco di ciò che è **ammesso**, non di
     * ciò che va ignorato, e la differenza non è di stile: un elenco di
     * esclusioni copre `utm_*`, `gclid` e `fbclid` finché qualcuno non inventa
     * il prossimo, mentre un elenco di ammessi copre anche quello.
     *
     * Il motivo è doppio. Il primo è visibile subito: con l'indirizzo intero
     * nella chiave, `/eventi` e `/eventi?utm_source=newsletter` sono due voci
     * distinte, quindi il traffico da newsletter e social — che arriva a
     * ondate, cioè esattamente quando la cache servirebbe — non trova mai
     * niente. Il secondo si vede solo quando è tardi: chiunque può inventare
     * parametri a piacere e generare voci illimitate, e su un disco quasi
     * pieno riempire la cache è un modo per fermare il sito.
     *
     * @var list<string>
     */
    private const QUERY_ALLOWED = [
        'access',
        'accessible',
        'archivio',
        'category',
        'date',
        'family',
        'from',
        'lat',
        'lng',
        'municipality',
        'outdoor',
        'page',
        'price',
        'radius',
        'sort',
        'tag',
        'time',
        'to',
        'type',
        'venue',
        'zone',
    ];

    /**
     * La chiave. Porta dentro il numero di versione della città
     * (`App\Support\ContentVersion`): alla pubblicazione di un evento tutte le
     * chiavi vecchie diventano irraggiungibili in un colpo solo, senza dover
     * sapere quali pagine quell'evento tocchi — che sono, in generale, tutte.
     *
     * Porta dentro anche la lingua: la stessa pagina in due lingue è due
     * documenti diversi.
     *
     * E porta dentro la scelta sul consenso (§16). Il banner e lo script delle
     * statistiche stanno **dentro** il documento, quindi due persone con scelte
     * diverse non possono ricevere la stessa copia: senza questa parte della
     * chiave, il primo visitatore che accetta riempirebbe la cache di pagine
     * con il contatore acceso e il banner assente, e le servirebbe a chi non ha
     * ancora scelto niente. È un consenso preventivo che diventa un consenso
     * di qualcun altro. Le varianti sono poche — «non ha scelto», «ha
     * accettato», «ha rifiutato» — perché l'identificativo del browser resta
     * fuori di proposito (`Consent::fingerprint()`).
     *
     * Dell'indirizzo prende il percorso e i soli parametri di
     * `QUERY_ALLOWED`, riordinati: due indirizzi che chiedono la stessa cosa
     * scritta in ordine diverso sono la stessa pagina, e devono essere la
     * stessa voce.
     */
    public function key(Request $request): string
    {
        $city = app(CurrentCity::class)->get();
        $cityId = $city === null ? 0 : (int) $city->getKey();

        return sprintf(
            'pagina:%d:%d:%s:%s:%s',
            $cityId,
            ContentVersion::for($cityId),
            app()->getLocale(),
            app(Consent::class)->fingerprint(),
            sha1($this->canonicalUrl($request)),
        );
    }

    /**
     * L'indirizzo ridotto a ciò che cambia davvero la pagina.
     *
     * `access[]=musica&access[]=rampa` e `access=musica,rampa` sono la stessa
     * richiesta scritta in due modi — il primo lo manda il modulo, il secondo
     * lo produce `EventFilters::toQueryString()` — e qui diventano la stessa
     * riga, come già succede più a valle in `EventFilterRequest`.
     */
    private function canonicalUrl(Request $request): string
    {
        $parametri = [];

        foreach (self::QUERY_ALLOWED as $nome) {
            if (! $request->query->has($nome)) {
                continue;
            }

            $valore = $request->query->all()[$nome];

            if (is_array($valore)) {
                $valore = implode(',', array_map(
                    static fn (mixed $voce): string => is_scalar($voce) ? (string) $voce : '',
                    $valore,
                ));
            }

            if (! is_scalar($valore)) {
                continue;
            }

            $parametri[$nome] = (string) $valore;
        }

        ksort($parametri);

        return $request->getPathInfo().'?'.http_build_query($parametri);
    }

    /**
     * Si può leggere dalla cache?
     */
    private function isCacheable(Request $request): bool
    {
        if (! config()->boolean('page_cache.enabled')) {
            return false;
        }

        if (! $request->isMethod('GET') || $request->ajax()) {
            return false;
        }

        if (Auth::check()) {
            return false;
        }

        /*
         * "Vicino a me" (§11.7) mette latitudine e longitudine nella query
         * string: sono indirizzi diversi a ogni metro percorso, quindi chiavi
         * diverse a ogni richiesta. Salvarli riempirebbe la cache di voci che
         * nessuno rileggerà mai — la stessa trappola dell'arrotondamento al
         * quarto d'ora, vista da un'altra parte.
         */
        if ($request->has('near')) {
            return false;
        }

        /*
         * La ricerca libera resta fuori per la stessa ragione per cui `/cerca`
         * non ha questo middleware: ogni ricerca è diversa dalla precedente,
         * quindi si scriverebbe sempre e si rileggerebbe quasi mai. E siccome
         * `q` è testo arbitrario, dentro la chiave sarebbe anche il solo
         * parametro ammesso con cui qualcuno potrebbe far crescere la cache a
         * piacere — il buco che l'elenco di `QUERY_ALLOWED` serve a chiudere.
         */
        if ($request->filled('q')) {
            return false;
        }

        /*
         * Un messaggio di conferma appena lasciato in sessione è per una
         * persona sola: la pagina che lo porta non si salva e non si serve
         * salvata.
         */
        if (! $request->hasSession()) {
            return true;
        }

        /*
         * Anche gli errori di validazione appartengono a chi ha appena
         * compilato il modulo: una pagina che li porta non si conserva.
         */
        return ! $request->session()->has('status') && ! $request->session()->has('errors');
    }

    /**
     * Si può scrivere in cache? Solo una pagina intera, riuscita e in HTML:
     * un reindirizzamento, un 404 o un file scaricato non hanno niente da
     * conservare.
     */
    private function isStorable(Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        $content = $response->getContent();

        return is_string($content) && $content !== '';
    }

    private function restore(string $content): string
    {
        return str_replace(self::CSRF_PLACEHOLDER, csrf_token(), $content);
    }
}
