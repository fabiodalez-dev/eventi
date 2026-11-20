<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\City;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\Delivery\HashDraw;
use App\Services\Sponsorship\Delivery\ImpressionTokenClaims;
use App\Support\Sponsorship\BotFilter;
use App\Support\Sponsorship\MetricExclusions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scrive le misure di una campagna: visualizzazioni, clic e il traffico dello
 * spazio in cui comparivano.
 *
 * Tre strade.
 *
 * **Con il gettone usa e getta** (`recordWithToken()`): la strada di oggi per
 * il sito e per le app aggiornate. Il numero casuale del gettone entra in
 * `sponsorship_impression_nonces` con un `INSERT IGNORE`: se la riga entra la
 * visualizzazione è nuova, altrimenti è un doppione. Atomico su MariaDB senza
 * Redis e senza lock applicativi, e lo stesso gettone conta una volta sola
 * anche se arriva cinquanta volte nello stesso istante.
 *
 * **Con il gettone giornaliero di prima** (`record()`): le app già installate
 * mandano ancora quello, e devono continuare a contare. Restano la deduplica
 * dei clic per `click_id` e quella di 15 minuti del controller dell'API.
 *
 * **Il segnale di occasione** (`recordOpportunity()`): lo spazio mostrava il
 * contenuto redazionale perché nessuno l'aveva comprato. Non tocca nessuna
 * campagna: aggiunge solo una vista all'aggregato orario, che serve a sapere
 * quanto traffico c'è da vendere.
 *
 * **Niente coda.** La coda qui è una tabella svuotata da cron: accodare vuol
 * dire una scrittura per mettere e una per togliere, più il lavoro vero, e un
 * minuto di ritardo sul pacing. Le scritture dirette in una transazione
 * costano meno e dicono subito a che punto è una campagna.
 */
final class RecordSponsorshipMetric
{
    use DetectsConcurrencyErrors;

    /** Le pagine da cui può arrivare un clic, per il registro dei clic. */
    private const PAGES = ['home', 'feed', 'event', 'venues', 'profile', 'search', 'other'];

    /** Tentativi quando MariaDB sceglie questa transazione come vittima di uno stallo. */
    private const ATTEMPTS = 3;

    /**
     * Le segnalazioni di tetto superato, da scrivere dopo la transazione: una
     * misura scartata dal tetto annulla la transazione, e la riga di registro
     * scritta dentro sparirebbe con lei.
     *
     * @var list<array<string, mixed>>
     */
    private array $alerts = [];

    /**
     * Se questa richiesta va contata: niente programmi che si dichiarano tali,
     * niente redazione, niente committente che guarda la propria pubblicità.
     *
     * Sta qui e non nei controller perché le porte sono due (sito e API) e la
     * regola è una: due copie della stessa regola prima o poi divergono.
     */
    public function shouldCount(Request $request, Sponsorship $campaign, ?Authenticatable $user): bool
    {
        if (BotFilter::isBot($request->userAgent())) {
            return false;
        }

        return ! MetricExclusions::excludes($user, $campaign);
    }

    /**
     * La campagna a cui si può ancora scrivere una misura con il gettone.
     *
     * Visibile adesso, oppure visibile una casella fa
     * (`HashDraw::slotMinutes()`, la vita di una copia in cache): l'apertura
     * della home resta nella pagina salvata fino alla fine della casella, e chi
     * la guarda in quel tempo la sta guardando davvero. Oltre, il gettone di
     * una campagna che non può più comparire si rifiuta.
     *
     * Stato e cestino si leggono sempre adesso: una campagna messa in pausa
     * smette di contare subito, perché in pausa nessuno paga. L'obiettivo pure,
     * per le visualizzazioni: è anche un massimo. **Un clic no**: chi tocca una
     * card ancora in pagina mentre l'unità arriva all'obiettivo ha cliccato
     * davvero, e scartarlo abbassa il tasso di clic finale di ogni campagna a
     * obiettivo. Il clic conta se il gettone è stato emesso da non più di una
     * casella; la visualizzazione che si porta dietro la blocca
     * `writeClick()`.
     */
    public function countableCampaign(int $sponsorshipId, ?int $cityId = null, ?CarbonImmutable $now = null, string $metric = 'impressions', ?CarbonImmutable $issuedAt = null): ?Sponsorship
    {
        $now ??= CarbonImmutable::now('UTC');
        $slot = $now->subMinutes(HashDraw::slotMinutes());
        $attempts = [[$now, true], [$slot, true]];

        if ($metric === 'clicks' && $issuedAt !== null && $issuedAt->greaterThanOrEqualTo($slot)) {
            $attempts[] = [$now, false];
            $attempts[] = [$slot, false];
        }

        foreach ($attempts as [$at, $withinGoal]) {
            $campaign = Sponsorship::query()
                ->visible($at, $withinGoal)
                ->when($cityId !== null, fn ($query) => $query->where('sponsorships.city_id', $cityId))
                ->whereKey($sponsorshipId)
                ->first();

            if ($campaign instanceof Sponsorship) {
                return $campaign;
            }
        }

        return null;
    }

    /**
     * Conta una misura con il gettone usa e getta. Restituisce se ha contato.
     *
     * **Visualizzazione.** Conta una volta per gettone. **Clic.** Conta una
     * volta per gettone; se arriva senza la sua visualizzazione (chi tocca la
     * card prima del secondo di permanenza, o una rete che perde il primo
     * segnale) conta tutte e due: chi clicca ha visto, e scartare clic veri è
     * peggio che contarli. Il tasso di clic resta comunque sotto il 100%.
     *
     * Il gettone deve essere già verificato e appartenere a questa campagna:
     * è compito del controller, che sa anche se la campagna è ancora visibile.
     */
    public function recordWithToken(Sponsorship $campaign, ImpressionTokenClaims $claims, string $metric, Request $request, string $channel, ?CarbonImmutable $now = null): bool
    {
        if (! in_array($metric, ['impressions', 'clicks'], true) || ! $claims->isFor(self::id($campaign))) {
            return false;
        }

        $now ??= CarbonImmutable::now('UTC');
        $page = $this->page($request);

        for ($attempt = 1; ; $attempt++) {
            DB::beginTransaction();

            try {
                $counted = $metric === 'impressions'
                    ? $this->writeImpression($campaign, $claims, $channel, $now)
                    : $this->writeClick($campaign, $claims, $channel, $page, $now);

                $counted ? DB::commit() : DB::rollBack();
                $this->flushAlerts();

                return $counted;
            } catch (Throwable $error) {
                DB::rollBack();
                $this->alerts = [];

                if ($attempt < self::ATTEMPTS && $this->causedByConcurrencyError($error)) {
                    continue;
                }

                throw $error;
            }
        }
    }

    /**
     * Conta una misura arrivata con il gettone giornaliero delle app già
     * installate. È il comportamento di prima, con in più l'aggregato orario
     * dello spazio.
     */
    public function record(Sponsorship $campaign, string $metric, Request $request, string $channel): void
    {
        $input = $request->validate([
            'click_id' => 'nullable|uuid',
            'placement' => 'nullable|in:banner,home_hero,card',
            'page' => 'nullable|in:home,feed,event,venues,profile,search,other',
        ]);

        /* Il banner ha uno spazio suo; gli altri valori dicono soltanto «una
           card», e lo spazio vero è la collocazione della campagna. */
        $slot = ($input['placement'] ?? null) === 'banner' ? 'banner' : $campaign->placement->value;

        /* Il tetto globale per campagna e ora sta qui, nel punto in cui passano sia
           il sito sia l'API: due controlli in due controller sarebbero due posti
           dove dimenticarne uno. Chi lo supera non lo sa: il chiamante risponde
           204 come sempre, perché un segnale diverso direbbe a chi gonfia quando
           fermarsi e quando riprovare. Si conta dopo la deduplica dei clic:
           un clic ripetuto non conta, e non deve nemmeno consumare il tetto, o
           bastano poche decine di indirizzi che rimandano lo stesso clic per
           far sparire quelli veri del resto dell'ora. */
        DB::transaction(function () use ($campaign, $metric, $input, $channel, $slot): void {
            // Serialize counter/log writes per campaign, including duplicate delivery.
            $locked = Sponsorship::query()->lockForUpdate()->findOrFail($campaign->id);
            $key = null;
            if ($metric === 'clicks') {
                $key = hash('sha256', $channel.'|'.$campaign->id.'|'.($input['click_id'] ?? Str::uuid()));
                if (SponsorshipClick::where('request_key', $key)->exists()) {
                    return;
                }
            }
            /* L'obiettivo è anche un massimo, come sulla strada del gettone:
               sotto il lucchetto della riga, così due misure simultanee su
               una campagna all'ultima visualizzazione non lo superano. */
            if ($metric === 'impressions' && (! $this->grantGoalOpen($locked) || $this->singleGoalReached($locked))) {
                return;
            }
            if (! $this->withinHourlyCap($campaign, $metric, $channel)) {
                return;
            }
            if ($key !== null) {
                SponsorshipClick::create([
                    'sponsorship_id' => $campaign->id, 'request_key' => $key,
                    'channel' => $channel, 'placement' => $input['placement'] ?? $campaign->placement->value,
                    'page' => $input['page'] ?? 'other', 'clicked_at' => now('UTC'),
                ]);
            }
            $locked->increment($metric);
            $local = CarbonImmutable::now($this->timezone((int) $campaign->city_id));
            SponsorshipDailyStat::registra($campaign->id, $metric, $local);
            $this->slotHourly((int) $campaign->city_id, $slot, $channel, $local,
                views: $metric === 'impressions' ? 1 : 0,
                sponsored: $metric === 'impressions' ? 1 : 0,
                clicks: $metric === 'clicks' ? 1 : 0);
        });

        $this->flushAlerts();
    }

    /**
     * Uno spazio è stato visto mentre mostrava il contenuto di riserva: una
     * vista in più nell'aggregato orario, nient'altro.
     *
     * Nessuna campagna, nessuna persona: è un contatore di traffico. Ma la
     * previsione che ne nasce decide quanto spazio ricevono le campagne a
     * visualizzazioni, quindi il controller vuole il gettone dell'occasione,
     * lo conta una volta sola e ha un limite per indirizzo stretto.
     */
    public function recordOpportunity(int $cityId, string $placement, string $channel, ?CarbonImmutable $now = null): void
    {
        $local = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($this->timezone($cityId));

        $this->slotHourly($cityId, $placement, $channel, $local, views: 1, sponsored: 0, clicks: 0);
    }

    /**
     * La visualizzazione: prima il contatore della campagna, poi il numero
     * casuale.
     *
     * **L'ordine conta.** Il contatore della campagna prende il lucchetto
     * della riga: da lì in poi le misure della stessa campagna passano una
     * alla volta. Al contrario, il numero casuale prenderebbe prima un
     * lucchetto condiviso sulla campagna (la chiave esterna) e poi lo
     * vorrebbe esclusivo per il contatore: due misure insieme si bloccherebbero
     * a vicenda. Un doppione così costa un `ROLLBACK`, che è il caso raro.
     */
    private function writeImpression(Sponsorship $campaign, ImpressionTokenClaims $claims, string $channel, CarbonImmutable $now): bool
    {
        if (! $this->incrementCampaign($campaign, impressions: 1, clicks: 0)) {
            return false;
        }

        $inserted = DB::table('sponsorship_impression_nonces')->insertOrIgnore([
            'nonce' => $claims->nonce,
            'sponsorship_id' => $campaign->getKey(),
            'placement' => $claims->placement,
            'seen_at' => $now->utc()->toDateTimeString(),
            'clicked_at' => null,
        ]);

        if ($inserted !== 1 || ! $this->withinHourlyCap($campaign, 'impressions', $channel)) {
            return false;
        }

        $local = $now->setTimezone($this->timezone((int) $campaign->city_id));
        $this->daily(self::id($campaign), $local, impressions: 1, clicks: 0);
        $this->slotHourly((int) $campaign->city_id, $claims->placement, $channel, $local, views: 1, sponsored: 1, clicks: 0);

        return true;
    }

    /**
     * Il clic: una volta per gettone, e con la sua visualizzazione se non era
     * ancora arrivata.
     *
     * Una sola istruzione dice in quale dei tre casi si è: `INSERT … ON
     * DUPLICATE KEY UPDATE` che segna `clicked_at` solo se era vuoto. MariaDB
     * risponde 1 se ha inserito (clic senza visualizzazione: si contano
     * tutte e due), 2 se ha aggiornato (visualizzazione già contata: solo il
     * clic), 0 se non ha cambiato niente (clic già contato: doppione).
     */
    private function writeClick(Sponsorship $campaign, ImpressionTokenClaims $claims, string $channel, string $page, CarbonImmutable $now): bool
    {
        // Prima il lucchetto della riga, per la stessa ragione della visualizzazione.
        if (! $this->incrementCampaign($campaign, impressions: 0, clicks: 1)) {
            return false;
        }

        $at = $now->utc()->toDateTimeString();
        $state = DB::affectingStatement(
            'insert into `sponsorship_impression_nonces` (`nonce`, `sponsorship_id`, `placement`, `seen_at`, `clicked_at`) values (?, ?, ?, ?, ?) '
            .'on duplicate key update `clicked_at` = if(`clicked_at` is null, values(`clicked_at`), `clicked_at`)',
            [$claims->nonce, $campaign->getKey(), $claims->placement, $at, $at],
        );

        if ($state === 0 || ! $this->withinHourlyCap($campaign, 'clicks', $channel)) {
            return false;
        }

        /* Il clic senza la sua visualizzazione la porta con sé, se l'unità
           non ha già raggiunto l'obiettivo: l'obiettivo è anche un massimo.
           Per una campagna singola lo dice `incrementCampaign()`; per le
           righe di un'abilitazione lo chiedo qui, perché il clic può arrivare
           dopo che l'abilitazione l'ha raggiunto (`countableCampaign()`). */
        $impressions = $state === 1 && $this->grantGoalOpen($campaign) && $this->withinHourlyCap($campaign, 'impressions', $channel)
            && $this->incrementCampaign($campaign, impressions: 1, clicks: 0) ? 1 : 0;

        SponsorshipClick::query()->insertOrIgnore([
            'sponsorship_id' => $campaign->getKey(),
            'request_key' => hash('sha256', $channel.'|'.self::id($campaign).'|token:'.$claims->nonce),
            'channel' => $channel,
            'placement' => $claims->placement,
            'page' => $page,
            'clicked_at' => $at,
        ]);

        $local = $now->setTimezone($this->timezone((int) $campaign->city_id));
        $this->daily(self::id($campaign), $local, impressions: $impressions, clicks: 1);
        $this->slotHourly((int) $campaign->city_id, $claims->placement, $channel, $local, views: $impressions, sponsored: $impressions, clicks: 1);

        return true;
    }

    /**
     * Il contatore della campagna, in una sola istruzione.
     *
     * Per una campagna singola con un obiettivo la condizione sta nella
     * stessa istruzione: oltre l'obiettivo non si scrive, nemmeno con due
     * misure nello stesso istante. Per le righe di un'abilitazione l'obiettivo
     * è sulla somma delle righe, e lo controlla `visible()` prima: due misure
     * simultanee su righe diverse della stessa abilitazione possono superarlo
     * di quante sono, non di più.
     */
    private function incrementCampaign(Sponsorship $campaign, int $impressions, int $clicks): bool
    {
        $columns = array_filter(['impressions' => $impressions, 'clicks' => $clicks]);

        $query = Sponsorship::query()->whereKey($campaign->getKey());

        if ($impressions > 0) {
            $query->where(fn ($goal) => $goal->whereNull('impressions_goal')
                ->orWhereNotNull('sponsorship_grant_id')
                ->orWhereColumn('impressions', '<', 'impressions_goal'));
        }

        return $query->incrementEach($columns) === 1;
    }

    /** Vero se la campagna singola ha un obiettivo e l'ha già raggiunto. */
    private function singleGoalReached(Sponsorship $campaign): bool
    {
        return $campaign->sponsorship_grant_id === null && $campaign->impressions_goal !== null
            && (int) $campaign->impressions >= (int) $campaign->impressions_goal;
    }

    /** Vero se la riga non è di un'abilitazione, o se la sua abilitazione non ha ancora raggiunto l'obiettivo. */
    private function grantGoalOpen(Sponsorship $campaign): bool
    {
        return $campaign->sponsorship_grant_id === null
            || ! SponsorshipGrant::query()->whereKey($campaign->sponsorship_grant_id)->goalReached()->exists();
    }

    /**
     * L'aggregato giornaliero della campagna, con le due misure in una sola
     * istruzione. È lo stesso `upsert` di `SponsorshipDailyStat::registra()`,
     * che ne sa sommare una alla volta: qui un clic può portare con sé la sua
     * visualizzazione, e due istruzioni sarebbero una scrittura in più per
     * ogni clic. Zero o uno per colonna, come nell'aggregato orario.
     */
    private function daily(int $sponsorshipId, CarbonImmutable $local, int $impressions, int $clicks): void
    {
        $now = now();

        DB::table('sponsorship_daily_stats')->upsert(
            [[
                'sponsorship_id' => $sponsorshipId,
                'day' => $local->toDateString(),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['sponsorship_id', 'day'],
            [
                'impressions' => DB::raw('`sponsorship_daily_stats`.`impressions`'.($impressions > 0 ? ' + 1' : '')),
                'clicks' => DB::raw('`sponsorship_daily_stats`.`clicks`'.($clicks > 0 ? ' + 1' : '')),
                'updated_at' => $now,
            ],
        );
    }

    /**
     * L'aggregato orario dello spazio, nel fuso della città: è l'ora che conta
     * per la previsione («il sabato sera»), non quella del server.
     *
     * `views` sono tutte le occasioni viste, vendute o no; `sponsored_views`
     * quelle in cui c'era una sponsorizzazione. Ogni misura aggiunge zero o
     * uno a ogni colonna: nel testo della query entra solo `+ 1`, mai un
     * valore.
     */
    private function slotHourly(int $cityId, string $placement, string $channel, CarbonImmutable $local, int $views, int $sponsored, int $clicks): void
    {
        DB::table('sponsorship_slot_hourly')->upsert(
            [[
                'city_id' => $cityId,
                'placement' => $placement,
                'channel' => $channel,
                'day' => $local->toDateString(),
                'hour' => $local->hour,
                'views' => $views,
                'sponsored_views' => $sponsored,
                'clicks' => $clicks,
            ]],
            ['city_id', 'placement', 'channel', 'day', 'hour'],
            [
                'views' => DB::raw('`sponsorship_slot_hourly`.`views`'.($views > 0 ? ' + 1' : '')),
                'sponsored_views' => DB::raw('`sponsorship_slot_hourly`.`sponsored_views`'.($sponsored > 0 ? ' + 1' : '')),
                'clicks' => DB::raw('`sponsorship_slot_hourly`.`clicks`'.($clicks > 0 ? ' + 1' : '')),
            ],
        );
    }

    /**
     * Il fuso della città, dall'elenco che `visible()` ha già letto in questa
     * richiesta: nessuna query in più per sapere che ora è a Padova.
     */
    private function timezone(int $cityId): string
    {
        $timezone = City::timezones()->firstWhere('id', $cityId)?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : config()->string('app.timezone');
    }

    /** La chiave della campagna come intero; zero per una riga non salvata, che nessun gettone nomina. */
    private static function id(Sponsorship $campaign): int
    {
        $key = $campaign->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }

    /** La pagina da cui arriva il clic; un valore sconosciuto diventa «altro», mai un errore. */
    private function page(Request $request): string
    {
        $page = $request->input('page');

        return is_string($page) && in_array($page, self::PAGES, true) ? $page : 'other';
    }

    /**
     * Se la campagna ha ancora spazio nell'ora in corso per questa misura.
     *
     * Il contatore è per campagna, misura e ora UTC, e non guarda l'indirizzo:
     * il tetto per indirizzo lo fanno i controller, prima. Così un indirizzo
     * consuma al massimo la propria quota del tetto globale, e per arrivare in
     * cima servono centinaia di indirizzi diversi — che è esattamente il caso
     * che il tetto per indirizzo non vede.
     *
     * Oltre il tetto si conta di meno, mai di più: le misure sono già una stima
     * al ribasso (D52/D53), e un'ora persa per eccesso di prudenza costa meno di
     * una fattura gonfiata. Un tetto a zero o negativo spegne il controllo.
     */
    private function withinHourlyCap(Sponsorship $campaign, string $metric, string $channel): bool
    {
        $tetto = config()->integer('eventi.sponsorship_metric_hourly_cap.'.$metric, 0);

        if ($tetto <= 0) {
            return true;
        }

        $ora = CarbonImmutable::now('UTC');
        $chiave = 'sponsorship-metric-global:'.self::id($campaign).':'.$metric.':'.$ora->format('YmdH');

        // Due ore di vita: l'ora corrente più un margine, poi la chiave si butta da sola.
        Cache::add($chiave, 0, 7200);
        $conteggio = (int) Cache::increment($chiave);

        if ($conteggio <= $tetto) {
            return true;
        }

        /* Una riga per campagna, misura e ora, non una per misura scartata: chi
           gonfia ne manderebbe migliaia, e il registro diventerebbe il secondo
           bersaglio. */
        if (Cache::add($chiave.':segnalata', true, 7200)) {
            $this->alerts[] = [
                'sponsorship_id' => $campaign->getKey(),
                'misura' => $metric,
                'ora_utc' => $ora->format('Y-m-d H:00'),
                'tetto' => $tetto,
                'canale' => $channel,
            ];
        }

        return false;
    }

    /** Scrive le segnalazioni di tetto superato, fuori dalla transazione della misura. */
    private function flushAlerts(): void
    {
        foreach ($this->alerts as $dettagli) {
            /* Senza `performedOn()`: la campagna non è nella mappa dei tipi
               polimorfici, e aggiungercela per una riga di registro toccherebbe
               tutto ciò che la legge. L'identificativo sta nelle proprietà. */
            activity('sicurezza')->withProperties($dettagli)
                ->event('sponsor_misure_oltre_tetto')->log('sponsor_misure_oltre_tetto');
            Log::warning('Misure sponsor oltre il tetto orario della campagna: le successive di quest\'ora sono scartate.', $dettagli);
        }

        $this->alerts = [];
    }
}
