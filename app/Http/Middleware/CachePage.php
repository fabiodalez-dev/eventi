<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Cache\FrontendCacheConfiguration;
use App\Support\Consent;
use App\Support\ContentVersion;
use App\Support\Csp;
use App\Support\CurrentCity;
use App\Support\Sponsorship\ActiveGrants;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

    /**
     * Il segnaposto del nonce della CSP (§16).
     *
     * Stesso problema del token CSRF e stessa soluzione: il numero vale per
     * **una** risposta, e la copia salvata verrà servita ad altre. Congelarlo
     * nella copia significherebbe mandare a tutti una pagina i cui script
     * dichiarano un numero che non è quello dell'intestazione — cioè, in
     * pratica, una pagina senza JavaScript.
     */
    private const NONCE_PLACEHOLDER = '@@csp-nonce@@';

    public function handle(Request $request, Closure $next): Response
    {
        app(FrontendCacheConfiguration::class)->apply();
        if (! $this->isCacheable($request)) {
            return $next($request);
        }

        $key = $this->key($request);
        try {
            $cached = Cache::store(config('page_cache.store') ?: null)->get($key);
        } catch (Throwable $exception) {
            report($exception);

            return $next($request);
        }

        if (is_string($cached)) {
            return response($this->restore($cached), 200)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('X-Page-Cache', 'hit');
        }

        $response = $next($request);

        if ($this->isCacheable($request) && $this->isStorable($response)) {
            $content = (string) $response->getContent();

            try {
                Cache::store(config('page_cache.store') ?: null)->put(
                    $key,
                    $this->depersonalise($content),
                    now()->addMinutes(config()->integer('page_cache.ttl_minutes')),
                );
            } catch (Throwable $exception) {
                report($exception);
            }
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
        'membership',
        'municipality',
        'outdoor',
        'page',
        /*
         * L'archivio di un organizzatore (`/organizzatori/{slug}?past=1`).
         * Senza, la scheda e il suo archivio condividerebbero la stessa
         * chiave: chi arriva per secondo riceverebbe l'elenco dell'altro.
         */
        'past',
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
     *
     * ## Quanto costa comporla, e perché conta
     *
     * Questo metodo gira **prima** di andare a vedere se la pagina è già
     * pronta, quindi lo pagano anche — soprattutto — le richieste che la
     * trovano. Misurato sul progetto, in locale con il database sulla stessa
     * macchina:
     *
     * ```
     * query delle concessioni attive : 1,59 ms   <- il 90% del totale
     * due hash_file                  : 0,06 ms
     * tre letture di cache           : 0,16 ms
     * ```
     *
     * La `whereHas` sulle concessioni è passata in `App\Support\Sponsorship\ActiveGrants`,
     * che la tiene per la stessa durata della pagina: una interrogazione al
     * minuto per città invece di una per richiesta. Il resto è rimasto dov'era
     * perché costa poco e perché è la parte che deve avere effetto **subito**
     * — la revisione che scrive il pulsante «svuota la cache» del pannello non
     * può aspettare un minuto per essere creduta.
     *
     * I due `hash_file` restano: sei centesimi di millisecondo, e l'impronta
     * del contenuto è più solida della data di modifica quando il rilascio
     * avviene per `rsync`, che le date sa preservarle.
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
            sha1($request->getSchemeAndHttpHost().$this->canonicalUrl($request)
                .now($city->timezone ?? config('app.timezone'))->format('Y-m-d')
                .Cache::get('frontend_cache_revision', '0')
                .(is_file(public_path('build/release.json')) ? hash_file('sha256', public_path('build/release.json')) : '')
                .(is_file(public_path('build/manifest.json')) ? hash_file('sha256', public_path('build/manifest.json')) : '')
                .Cache::get('consent_scripts_revision', '0')
                .ActiveGrants::fingerprint($cityId)),
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
     *
     * @phpstan-impure Session and authentication can change while rendering.
     */
    private function isCacheable(Request $request): bool
    {
        // Booking capacity and sale windows must not be frozen in a full-page cache.
        if ($request->routeIs('events.show', 'events.occurrence')) {
            return false;
        }
        if (! config()->boolean('page_cache.enabled')) {
            return false;
        }

        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()
            || $request->headers->has('Authorization') || $request->has('signature')) {
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
        if ($request->hasAny(['near', 'lat', 'lng'])) {
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
        return ! $request->session()->hasAny(['status', 'errors', '_old_input'])
            && $request->session()->get('_flash.new', []) === []
            && $request->session()->get('_flash.old', []) === [];
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

        if ($response->headers->getCookies() !== [] || $response->headers->hasCacheControlDirective('no-store')) {
            return false;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        $content = $response->getContent();

        return is_string($content) && $content !== '';
    }

    /**
     * Toglie dalla copia da salvare i due valori che valgono per **una sola**
     * risposta: il token CSRF e il nonce della CSP.
     *
     * Sono entrambi stringhe casuali lunghe, quindi la sostituzione non può
     * colpire nient'altro nel documento.
     */
    private function depersonalise(string $content): string
    {
        $content = str_replace(csrf_token(), self::CSRF_PLACEHOLDER, $content);
        $nonce = app(Csp::class)->issued();

        return $nonce === null ? $content : str_replace($nonce, self::NONCE_PLACEHOLDER, $content);
    }

    /**
     * E li rimette, quelli di **questa** risposta.
     *
     * Il nonce lo ha già generato `SecurityHeaders` all'andata, prima ancora
     * che si arrivasse qui: la pagina servita dalla cache e l'intestazione che
     * la accompagna dichiarano quindi lo stesso numero.
     */
    private function restore(string $content): string
    {
        $content = str_replace(self::CSRF_PLACEHOLDER, csrf_token(), $content);
        $nonce = app(Csp::class)->issued();

        return $nonce === null ? $content : str_replace(self::NONCE_PLACEHOLDER, $nonce, $content);
    }
}
