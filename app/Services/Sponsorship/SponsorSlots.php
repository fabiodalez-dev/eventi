<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\DTOs\EventFilters;
use App\Enums\SponsorshipPlacement;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Queries\EventOccurrenceQuery;
use App\Services\Search\EventFinder;
use App\Services\Sponsorship\Delivery\DeliveryPlan;
use App\Services\Sponsorship\Delivery\DeliveryPlanBuilder;
use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * La card della home e la cima dell'elenco come **buchi** nella pagina in
 * cache, come «In corso adesso» e il prossimo evento.
 *
 * Prima la scelta finiva dentro lo scheletro salvato, e per un anonimo restava
 * quella fino a trenta minuti: la quota dipendeva da quando la cache si
 * rigenerava e non da ciò che era stato comprato, e una campagna in pausa o
 * arrivata al tetto restava a schermo.
 *
 * Adesso, quando la pagina si disegna per essere salvata, al posto della card
 * c'è una regione segnata (`open()` … `close()`) che porta con sé ciò che
 * serve per riempirla: città, collocazione e, per un elenco filtrato, gli
 * eventi che rispettano i filtri. Nello stesso momento ogni campagna del piano
 * viene disegnata una volta e messa in cache (per riga, filtri e versione dei
 * contenuti). `CachePage` toglie la regione dalla copia salvata (`extract()`)
 * e a ogni risposta la riempie (`fill()`): un'estrazione dal piano e il
 * frammento già pronto. Dalla cache: due letture e una firma, nessuna query.
 *
 * I gettoni delle visualizzazioni non si salvano mai: frammenti e scheletro
 * portano il segnaposto di `ImpressionToken`, che `CachePage` rimette fresco a
 * ogni risposta.
 *
 * **I marcatori li scrive solo il server.** Segnaposto dei buchi, dei
 * gettoni e del riempimento sono testo semplice (`@@sponsor-…@@`), e il testo
 * degli utenti (il titolo di un evento) arriva nella stessa pagina escapato
 * per l'HTML ma non per loro. Prima di salvare la copia, e in ogni frammento,
 * `neutralise()` spegne quelli che la pagina portava già: altrimenti un
 * titolo potrebbe chiedere al server un gettone valido per la campagna di un
 * altro, o un frammento HTML dentro un attributo. E diventano segnaposto solo
 * i gettoni che questo processo ha emesso (`ImpressionToken::issuedHere()`),
 * non qualunque stringa con la loro forma.
 *
 * **Quanto si disegna in anticipo.** Ogni frammento costa qualche query, e
 * una lista filtrata che nessuno aveva ancora chiesto pagava tutte le
 * campagne del piano. Ora si disegnano in anticipo le più probabili, poche
 * (`PRERENDER_LIMIT`, meno ancora con i filtri), con le campagne caricate in
 * una volta sola; le altre al primo uso. E un frammento dipende dai filtri
 * solo quando li rispetta: la card della home non li guarda mai, e la cima
 * di un elenco filtrato che mostra una campagna fuori dai filtri è la stessa
 * dell'elenco senza filtri, quindi la condividono.
 *
 * **La data promossa non si ripete fra i risultati.** Nella cima dell'elenco
 * la card in cima toglieva la stessa occorrenza dalla griglia. Con il buco la
 * griglia salvata resta intera, e la card scelta a ogni visita toglie dalla
 * risposta la propria occorrenza (`<article data-flip-id="occurrence-N">`).
 */
final class SponsorSlots
{
    /** Quante campagne si disegnano in anticipo per una regione; le altre al primo uso. */
    private const PRERENDER_LIMIT = 8;

    /** Lo stesso per un elenco filtrato: ogni combinazione di filtri ha la sua copia. */
    private const FILTERED_PRERENDER_LIMIT = 3;

    /** I marcatori che solo il server scrive, se li trova già nella pagina. */
    private const FORGED_MARKER = '/@@(?=sponsor-)/';

    private const START = '<!-- page-cache-sponsor:start ';

    private const END = '<!-- page-cache-sponsor:end -->';

    private const PLACEHOLDER = '/@@sponsor-slot:([A-Za-z0-9_-]+)@@/';

    private const REGION = '/<!-- page-cache-sponsor:start ([A-Za-z0-9_-]+) -->.*?<!-- page-cache-sponsor:end -->/s';

    /** Un gettone emesso, da rimettere come segnaposto: `v1.{riga}.{collocazione}.{minuto}.{numero}.{firma}`. */
    private const TOKEN = '/\bv1\.([1-9]\d{0,18})\.([a-z_]{1,32})\.[1-9]\d{0,11}\.[0-9a-f]{16}\.[A-Za-z0-9_-]{43}\b/';

    public function __construct(
        private readonly DeliveryPlanBuilder $plans,
    ) {}

    /** L'inizio della regione, con il suo carico. */
    public static function open(string $payload): string
    {
        return self::START.$payload.' -->';
    }

    public static function close(): string
    {
        return self::END;
    }

    /**
     * Il segno di uno spazio che questa volta non mostra nessuna
     * sponsorizzazione: un blocco vuoto alto zero, con collocazione e città.
     *
     * Non è `hidden`: il browser deve poterlo vedere entrare nello schermo per
     * mandare il segnale dell'occasione non venduta
     * (`data-sponsorship-opportunity`, `resources/js/sponsorship-metrics.js`).
     * Un elemento senza area che entra nello schermo vale come visto per
     * intero, quindi conta dopo un secondo come una card.
     */
    public static function vacant(SponsorshipPlacement|string $placement, City|int|null $city = null): string
    {
        $value = $placement instanceof SponsorshipPlacement ? $placement->value : $placement;
        $placement = e($value);
        $cityId = $city instanceof City ? (int) $city->getKey() : $city;

        if ($cityId === null || $cityId < 1 || SponsorshipPlacement::tryFrom($value) === null) {
            return '<div aria-hidden="true" data-sponsorship-vacant="'.$placement.'"></div>';
        }

        /* Il gettone dell'occasione: senza, il segnale si potrebbe mandare in
           un ciclo e gonfiare la previsione del traffico. In una pagina che si
           salva diventa segnaposto, come i gettoni delle card. */
        return '<div aria-hidden="true" data-sponsorship-vacant="'.$placement.'" data-sponsorship-opportunity="'.$placement.'" data-sponsorship-city="'.$cityId.'"'
            .' data-sponsorship-opportunity-token="'.ImpressionToken::issueOpportunity($cityId, $value).'"></div>';
    }

    /** C'è almeno una regione da riempire? */
    public static function present(string $html): bool
    {
        return str_contains($html, self::START) || str_contains($html, '@@sponsor-slot:');
    }

    /**
     * Prepara la regione di una collocazione per una pagina che sta per
     * essere salvata: disegna in anticipo le campagne del piano e restituisce
     * il carico da mettere nella regione.
     */
    public function prepare(City $city, SponsorshipPlacement $placement, ?EventFilters $filters = null): string
    {
        $plan = $this->plans->current($city, $placement);
        $query = $filters !== null && $filters->activeCount() > 0 ? $filters->toQueryString() : null;
        $prefer = null;

        if ($query !== null && $plan->eventIds() !== []) {
            $prefer = app(EventFinder::class)->query($city, EventFilters::fromArray($query))
                ->forEvents($plan->eventIds())->promotable()->eventIds();
        }

        $payload = [
            'c' => (int) $city->getKey(),
            'p' => $placement->value,
            'q' => $query,
            'e' => $prefer,
        ];

        $this->prerender($city, $placement, $plan, $query, $prefer);

        return self::encode($payload);
    }

    /**
     * Nella copia da salvare: i marcatori che la pagina portava già si
     * spengono, le regioni diventano segnaposto, i gettoni pure.
     */
    public static function extract(string $html): string
    {
        $html = self::neutralise($html);
        $html = preg_replace(self::REGION, '@@sponsor-slot:$1@@', $html) ?? $html;

        return self::tokensToPlaceholders($html);
    }

    /**
     * Spegne ogni `@@sponsor-…` che c'è già nel testo: diventa `&#64;@sponsor-…`,
     * che il browser mostra uguale e che nessun riempimento riconosce. Si
     * chiama prima di mettere i segnaposto veri, che così restano i soli.
     */
    public static function neutralise(string $html): string
    {
        return preg_replace(self::FORGED_MARKER, '&#64;@', $html) ?? $html;
    }

    /**
     * Ogni gettone emesso qui torna segnaposto: la copia salvata non ne porta.
     * Una stringa con la sola forma di un gettone, che questo processo non ha
     * emesso, resta com'è e non verrà mai firmata da capo.
     */
    public static function tokensToPlaceholders(string $html): string
    {
        $html = preg_replace_callback(
            self::TOKEN,
            static function (array $match): string {
                $issued = ImpressionToken::issuedHere($match[0]);

                return $issued === null ? $match[0] : ImpressionToken::placeholder($issued[0], $issued[1]);
            },
            $html,
        ) ?? $html;

        return preg_replace_callback(
            ImpressionToken::OPPORTUNITY_TOKEN_PATTERN,
            static function (array $match): string {
                $issued = ImpressionToken::issuedHere($match[0]);

                return $issued === null ? $match[0] : ImpressionToken::opportunityPlaceholder($issued[0], $issued[1]);
            },
            $html,
        ) ?? $html;
    }

    /**
     * Riempie ogni segnaposto con un'estrazione dal piano. I gettoni restano
     * segnaposto: li rimette `ImpressionToken::fillPlaceholders()`.
     *
     * Prima si sceglie per ogni regione, poi si tolgono dalla griglia le
     * occorrenze promosse, e solo alla fine si inseriscono i frammenti: così
     * la card sponsorizzata non toglie sé stessa.
     */
    public function fill(string $html, ?CarbonImmutable $now = null): string
    {
        if (! str_contains($html, '@@sponsor-slot:')) {
            return $html;
        }

        $now ??= CarbonImmutable::now('UTC');
        $fragments = [];
        $promoted = [];
        /* Il segno intermedio cambia a ogni riempimento: il testo della pagina
           non può conoscerlo, quindi non può farsi sostituire un frammento. */
        $marker = '@@sponsor-fill-'.bin2hex(random_bytes(8)).':';

        $html = preg_replace_callback(self::PLACEHOLDER, function (array $match) use (&$fragments, &$promoted, $now, $marker): string {
            $payload = self::decode($match[1]);
            $fragment = '';

            if ($payload !== null) {
                try {
                    [$fragment, $occurrenceId] = $this->fragmentFor($payload, $now);

                    if ($occurrenceId !== null) {
                        $promoted[] = $occurrenceId;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $fragment = '';
                }
            }

            /* Lo spazio è andato al contenuto della redazione: un segno vuoto
               lo dice al browser, che può contare l'occasione non venduta. */
            if ($fragment === '' && $payload !== null) {
                $fragment = self::vacant($payload['p'], (int) $payload['c']);
            }

            $fragments[] = $payload === null ? '' : self::open($match[1]).$fragment.self::close();

            return $marker.(count($fragments) - 1).'@@';
        }, $html) ?? $html;

        foreach ($promoted as $occurrenceId) {
            $html = self::withoutOccurrence($html, $occurrenceId);
        }

        return preg_replace_callback('/'.preg_quote($marker, '/').'(\d+)@@/', static fn (array $match): string => $fragments[(int) $match[1]] ?? '', $html) ?? $html;
    }

    /**
     * La campagna estratta e il suo frammento.
     *
     * @param  array{c: int, p: string, q: array<string, string>|null, e: list<int>|null}  $payload
     * @return array{0: string, 1: int|null}
     */
    private function fragmentFor(array $payload, CarbonImmutable $now): array
    {
        $plan = $this->plans->current($payload['c'], $payload['p'], $now);
        $rowId = $plan->draw(app(Draw::class), [], $payload['e']);

        if ($rowId === null) {
            return ['', null];
        }

        $query = self::fragmentQuery($payload['p'], $plan->row($rowId)['event'] ?? null, $payload['q'], $payload['e']);
        $cached = Cache::get(self::fragmentKey($payload['c'], $payload['p'], $rowId, $query));

        if (! is_array($cached) || ! is_string($cached['html'] ?? null)) {
            $city = City::query()->find($payload['c']);
            $placement = SponsorshipPlacement::tryFrom($payload['p']);

            if (! $city instanceof City || $placement === null) {
                return ['', null];
            }

            $cached = $this->render($city, $placement, $rowId, $query);
        }

        $occurrence = $cached['occurrence'] ?? null;

        return [(string) $cached['html'], $payload['p'] === SponsorshipPlacement::ListTop->value && is_int($occurrence) ? $occurrence : null];
    }

    /**
     * Disegna in anticipo le campagne del piano, le più probabili prima, e
     * solo quelle che non sono già in cache. Le campagne si caricano in una
     * volta sola.
     *
     * @param  array<string, string>|null  $query
     * @param  list<int>|null  $prefer
     */
    private function prerender(City $city, SponsorshipPlacement $placement, DeliveryPlan $plan, ?array $query, ?array $prefer): void
    {
        $shares = $plan->shares();
        $wanted = $prefer === null || $prefer === [] ? null : array_flip($prefer);
        $rows = [];

        foreach ($plan->units() as $key => $unit) {
            $events = array_column($unit['rows'], 'event', 'id');

            foreach ($plan->eventShares($unit) as $rowId => $share) {
                /* Con i filtri l'estrazione sceglie fra le campagne che li
                   rispettano, se ce n'è almeno una: sono quelle da preparare. */
                if ($wanted === null || isset($wanted[$events[$rowId] ?? 0])) {
                    $rows[$rowId] = ($shares[$key] ?? 0.0) * $share;
                }
            }
        }

        arsort($rows);

        $todo = [];

        foreach (array_keys($rows) as $rowId) {
            if (count($todo) >= ($query === null ? self::PRERENDER_LIMIT : self::FILTERED_PRERENDER_LIMIT)) {
                break;
            }

            $rowQuery = self::fragmentQuery($placement->value, $plan->row($rowId)['event'] ?? null, $query, $prefer);

            if (! is_array(Cache::get(self::fragmentKey((int) $city->getKey(), $placement->value, $rowId, $rowQuery)))) {
                $todo[$rowId] = $rowQuery;
            }
        }

        if ($todo === []) {
            return;
        }

        $loaded = $this->sponsorships($placement, array_keys($todo));

        foreach ($todo as $rowId => $rowQuery) {
            $this->render($city, $placement, $loaded[$rowId] ?? $rowId, $rowQuery);
        }
    }

    /**
     * Le campagne con ciò che il frammento legge, in una query per relazione.
     *
     * @param  list<int>  $ids
     * @return array<int, Sponsorship>
     */
    private function sponsorships(SponsorshipPlacement $placement, array $ids): array
    {
        $relations = [
            'event.venue', 'event.category', 'event.tags',
            'event.occurrences' => static fn ($occurrences) => $occurrences->withCount('interestedUsers as interested_count'),
        ];

        if ($placement === SponsorshipPlacement::ListTop) {
            $relations[] = 'event.media';
        }

        return Sponsorship::query()->with($relations)->whereKey($ids)->get()
            ->keyBy(fn (Sponsorship $row): int => (int) $row->getKey())
            ->all();
    }

    /**
     * Disegna il frammento di una campagna e lo mette in cache.
     *
     * @param  array<string, string>|null  $query
     * @return array{html: string, occurrence: int|null}
     */
    private function render(City $city, SponsorshipPlacement $placement, Sponsorship|int $row, ?array $query): array
    {
        $rowId = $row instanceof Sponsorship ? (int) $row->getKey() : $row;
        $sponsorship = $row instanceof Sponsorship ? $row : ($this->sponsorships($placement, [$row])[$row] ?? null);

        $html = '';
        $occurrenceId = null;

        if ($sponsorship !== null) {
            if ($placement === SponsorshipPlacement::ListTop) {
                $occurrence = $this->listOccurrence($city, $sponsorship, $query);
                $occurrenceId = $occurrence === null ? null : (int) $occurrence->getKey();
                $html = $occurrence === null ? '' : view('partials.sponsored.list-top', [
                    'sponsorship' => $sponsorship,
                    'sponsoredOccurrence' => $occurrence,
                ])->render();
            } else {
                $html = view('partials.sponsored.home-card', ['cardSponsorship' => $sponsorship])->render();
            }
        }

        /* Il frammento porta il titolo dell'evento: anche qui i marcatori che
           c'erano già si spengono prima di mettere i segnaposto dei gettoni. */
        $fragment = ['html' => self::tokensToPlaceholders(self::neutralise($html)), 'occurrence' => $occurrenceId];

        try {
            Cache::put(self::fragmentKey((int) $city->getKey(), $placement->value, $rowId, $query), $fragment, now()->addMinutes(config()->integer('page_cache.ttl_minutes') + 5));
        } catch (Throwable $exception) {
            report($exception);
        }

        return $fragment;
    }

    /**
     * La data da mostrare in cima all'elenco: la prima che rispetta i filtri,
     * altrimenti la prima in programma. È la stessa regola del controller.
     *
     * @param  array<string, string>|null  $query
     */
    private function listOccurrence(City $city, Sponsorship $sponsorship, ?array $query): ?EventOccurrence
    {
        $occurrence = app(EventFinder::class)->query($city, EventFilters::fromArray($query ?? []))
            ->forEvents([(int) $sponsorship->event_id])->promotable()->firstPerEvent(1)->first()
            ?? EventOccurrenceQuery::for($city)->forEvents([(int) $sponsorship->event_id])
                ->promotable()->firstPerEvent(1)->first();
        if ($occurrence !== null && $sponsorship->relationLoaded('event') && $sponsorship->event !== null
            && (int) $occurrence->event_id === (int) $sponsorship->event->getKey()) {
            /* L'evento è già caricato con le sue relazioni: niente query di nuovo. */
            $occurrence->setRelation('event', $sponsorship->event);
        }

        $occurrence?->loadMissing(['event.venue', 'event.category', 'event.media', 'venue']);

        return $occurrence;
    }

    /**
     * Toglie dalla risposta la card della griglia per questa occorrenza. Le
     * card della griglia sono `<article>` senza altri `<article>` dentro.
     */
    private static function withoutOccurrence(string $html, int $occurrenceId): string
    {
        return preg_replace('/<article data-flip-id="occurrence-'.$occurrenceId.'"[^>]*>.*?<\/article>/s', '', $html) ?? $html;
    }

    /**
     * La chiave del frammento: collocazione, riga, filtri, versione dei
     * contenuti della città e lingua. Una modifica a un evento cambia la
     * versione, e il frammento si ridisegna.
     *
     * @param  array<string, string>|null  $query
     */
    private static function fragmentKey(int $cityId, string $placement, int $rowId, ?array $query): string
    {
        return 'sponsorizzazioni:frammento:v2:'.$cityId.':'.$placement.':'.$rowId.':'
            .($query === null ? '-' : substr(sha1((string) json_encode($query)), 0, 16)).':'
            .ContentVersion::for($cityId).':'.app()->getLocale();
    }

    /**
     * I filtri da cui dipende il frammento di una riga: nessuno per la card
     * della home, che non li guarda; nessuno per la cima di un elenco
     * filtrato quando l'evento della riga non li rispetta, perché allora si
     * mostra la sua prima data come senza filtri, e il frammento è lo stesso.
     *
     * @param  array<string, string>|null  $query
     * @param  list<int>|null  $prefer
     * @return array<string, string>|null
     */
    private static function fragmentQuery(string $placement, ?int $eventId, ?array $query, ?array $prefer): ?array
    {
        if ($query === null || $placement !== SponsorshipPlacement::ListTop->value) {
            return null;
        }

        return $eventId !== null && in_array($eventId, $prefer ?? [], true) ? $query : null;
    }

    /** @param  array{c: int, p: string, q: array<string, string>|null, e: list<int>|null}  $payload */
    private static function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    /** @return array{c: int, p: string, q: array<string, string>|null, e: list<int>|null}|null */
    private static function decode(string $encoded): ?array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_int($data['c'] ?? null) || ! is_string($data['p'] ?? null)
            || ! in_array($data['p'], [SponsorshipPlacement::HomeCard->value, SponsorshipPlacement::ListTop->value], true)) {
            return null;
        }

        $query = is_array($data['q'] ?? null) ? array_filter($data['q'], 'is_string') : null;
        $events = is_array($data['e'] ?? null) ? array_values(array_filter($data['e'], 'is_int')) : null;

        return ['c' => $data['c'], 'p' => $data['p'], 'q' => $query, 'e' => $events];
    }
}
