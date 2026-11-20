<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Enums\FollowableType;
use App\Models\City;
use App\Models\Organizer;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Account\ContentPreferences;
use App\Support\ContentVersion;
use App\Support\RequestMemo;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;

/**
 * Il calendario mensile (§11.8).
 *
 * §11.8 pone un vincolo esplicito: **una sola query aggregata per mese, in
 * cache**. Le due cose vanno insieme — una griglia di trenta giorni che
 * interrogasse il database una volta per casella farebbe trenta letture per
 * disegnare una pagina che cambia una volta al giorno.
 *
 * La query è `EventOccurrenceQuery::dailyDigest()`, che restituisce conteggio e
 * primi titoli di ogni giornata evento in una lettura sola. La griglia si
 * costruisce poi in PHP: quali caselle appartengano al mese e quale sia oggi
 * non sono domande da porre a un database.
 *
 * ## Come si invalida
 *
 * §12.3 vuole la cache dei conteggi «invalidata sulla pubblicazione». Invece di
 * inseguire quali mesi tocchi ogni evento salvato — un evento con una
 * ricorrenza annuale li tocca tutti — la chiave porta il numero di versione
 * della città (`App\Support\ContentVersion`): cambiarlo rende irraggiungibile
 * ogni mese di quella città in un colpo solo. È lo stesso segnale che invalida
 * lo scheletro delle pagine e la mappa del sito.
 *
 * ## Il calendario generale e quello personale
 *
 * Quello generale è uguale per tutti ed è l'unico che entra in cache: si
 * calcola **senza** le preferenze di chi guarda. `EventOccurrenceQuery::for()`
 * applica da sé categorie e locali nascosti della persona collegata, e prima
 * il primo visitatore collegato di ogni mezz'ora decideva quali conteggi
 * vedevano tutti gli altri.
 *
 * Quello personale (la pagina iniziale di chi è collegato, `personal=1`
 * nell'API) segue le preferenze della persona collegata in questa richiesta:
 * categorie e locali nascosti, categorie scelte, locali, organizzatori,
 * categorie e tag seguiti. Non va in cache: è una query sola per mese.
 */
final class MonthCalendar
{
    /**
     * §12.3: conteggi del calendario in cache per mezz'ora.
     */
    private const TTL_MINUTES = 30;

    /**
     * Quanti titoli si mostrano in anteprima dentro una casella (§11.8).
     */
    public const TITLES_PER_DAY = 3;

    /**
     * Conteggio e titoli d'anteprima di ogni giornata del mese.
     *
     * @return array<string, array{count: int, titles: list<string>}>
     */
    public function digest(City $city, CarbonImmutable $month): array
    {
        $first = $this->firstDay($city, $month);

        /** @var array<string, array{count: int, titles: list<string>}> $digest */
        $digest = Cache::remember(
            $this->key($city, $first),
            now()->addMinutes(self::TTL_MINUTES),
            fn (): array => $this->query($city, null)
                ->between($first, $first->endOfMonth())
                ->dailyDigest(self::TITLES_PER_DAY),
        );

        return $digest;
    }

    /**
     * Conteggio e titoli del mese secondo gli interessi della persona.
     *
     * `null` quando la persona non ha espresso interessi (nessuna categoria
     * scelta, niente di seguito): per lei vale `filteredDigest()`.
     *
     * @return array<string, array{count: int, titles: list<string>}>|null
     */
    public function personalDigest(City $city, CarbonImmutable $month, User $user): ?array
    {
        $first = $this->firstDay($city, $month);
        $interests = $this->interests($user);

        if ($interests === null) {
            return null;
        }

        $query = $this->query($city, $user)->between($first, $first->endOfMonth());

        /*
         * Non `EventOccurrenceQuery::followedBy()`: legge ciò che la persona
         * segue con quattro query uguali (una per tipo), che il rilevatore
         * N+1 segnala a ogni apertura della pagina iniziale. Qui gli interessi
         * arrivano da una lettura sola e la regola è la stessa: una data è
         * della persona se il suo locale, il suo organizzatore (attivo), la
         * sua categoria o uno dei suoi tag è fra quelli che segue o sceglie.
         */
        $builder = $query->identifiersQuery();

        $builder->where(function (Builder $match) use ($interests): void {
            if ($interests['organizers'] !== []) {
                $match->orWhereIn('events.organizer_id', Organizer::query()->select('id')->where('is_active', true)->whereKey($interests['organizers']));
            }

            if ($interests['venues'] !== []) {
                $match->orWhereIn('venues.id', $interests['venues']);
            }

            if ($interests['categories'] !== []) {
                $match->orWhereIn('events.category_id', $interests['categories']);
            }

            if ($interests['tags'] !== []) {
                $match->orWhereExists(function (QueryBuilder $sub) use ($interests): void {
                    $sub->from('event_tag')
                        ->whereColumn('event_tag.event_id', 'events.id')
                        ->whereIn('event_tag.tag_id', $interests['tags']);
                });
            }
        });

        $rows = $builder
            ->reorder()
            ->select(['event_occurrences.business_date as digest_date', 'events.title as digest_title'])
            ->orderBy('event_occurrences.business_date')
            ->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id')
            ->toBase()
            ->get();

        /** @var array<string, array{count: int, titles: list<string>}> $digest */
        $digest = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $date = CarbonImmutable::parse((string) ($values['digest_date'] ?? ''))->format('Y-m-d');

            $digest[$date] ??= ['count' => 0, 'titles' => []];
            $digest[$date]['count']++;

            if (count($digest[$date]['titles']) < self::TITLES_PER_DAY) {
                $digest[$date]['titles'][] = (string) ($values['digest_title'] ?? '');
            }
        }

        return $digest;
    }

    /**
     * Il mese della città meno ciò che la persona ha nascosto (categorie e
     * locali): il calendario di chi non ha scelto interessi, e quello su cui
     * si ripiega quando i suoi interessi in un mese non portano a niente.
     *
     * @return array<string, array{count: int, titles: list<string>}>
     */
    public function filteredDigest(City $city, CarbonImmutable $month, User $user): array
    {
        $first = $this->firstDay($city, $month);

        return $this->query($city, $user)
            ->between($first, $first->endOfMonth())
            ->dailyDigest(self::TITLES_PER_DAY);
    }

    /**
     * Le caselle del mese personale, `null` quando la persona non ha espresso
     * interessi.
     *
     * @return list<array{date: CarbonImmutable, in_month: bool, is_today: bool, is_past: bool, count: int, titles: list<string>}>|null
     */
    public function personalGrid(City $city, CarbonImmutable $month, User $user): ?array
    {
        $digest = $this->personalDigest($city, $month, $user);

        return $digest === null ? null : $this->cells($city, $month, $digest);
    }

    /**
     * Ciò che la persona segue o ha scelto, letto in una query sola; `null`
     * quando non ha espresso alcun interesse.
     *
     * @return array{venues: list<int>, organizers: list<int>, categories: list<int>, tags: list<int>}|null
     */
    public function interests(User $user): ?array
    {
        /* La home la chiede da più pezzi (prossimo evento, finestre): una
           lettura per richiesta. */
        return RequestMemo::remember('month-calendar:interests:'.$user->id, fn (): ?array => $this->readInterests($user));
    }

    /** @return array{venues: list<int>, organizers: list<int>, categories: list<int>, tags: list<int>}|null */
    private function readInterests(User $user): ?array
    {
        $types = [
            FollowableType::Venue->value => 'venues',
            FollowableType::Organizer->value => 'organizers',
            FollowableType::Category->value => 'categories',
            FollowableType::Tag->value => 'tags',
        ];

        $interests = ['venues' => [], 'organizers' => [], 'categories' => [], 'tags' => []];

        foreach ($user->follows()->whereIn('followable_type', array_keys($types))->get(['followable_type', 'followable_id']) as $follow) {
            $interests[$types[(string) $follow->followable_type]][] = (int) $follow->followable_id;
        }

        $selection = app(ContentPreferences::class)->selection($user);

        if ($selection['mode'] === 'selected') {
            $interests['categories'] = array_values(array_unique([...$interests['categories'], ...$selection['categories']]));
        }

        return array_merge(...array_values($interests)) === [] ? null : $interests;
    }

    /**
     * Le caselle da disegnare: dal lunedì che apre la prima settimana del mese
     * alla domenica che chiude l'ultima. I giorni degli altri mesi restano
     * nella griglia — una settimana con tre caselle mancanti non si legge — ma
     * si dichiarano come fuori mese.
     *
     * @return list<array{date: CarbonImmutable, in_month: bool, is_today: bool, is_past: bool, count: int, titles: list<string>}>
     */
    public function grid(City $city, CarbonImmutable $month): array
    {
        return $this->cells($city, $month, $this->digest($city, $month));
    }

    /**
     * Le caselle di un mese a partire da un riepilogo già calcolato (generale
     * o personale).
     *
     * @param  array<string, array{count: int, titles: list<string>}>  $digest
     * @return list<array{date: CarbonImmutable, in_month: bool, is_today: bool, is_past: bool, count: int, titles: list<string>}>
     */
    public function cells(City $city, CarbonImmutable $month, array $digest): array
    {
        $first = $this->firstDay($city, $month);

        $today = CarbonImmutable::now($city->timezone)->startOfDay();
        $cursor = $first->startOfWeek(CarbonImmutable::MONDAY);
        $last = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();

        $cells = [];

        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $day = $digest[$key] ?? ['count' => 0, 'titles' => []];

            $cells[] = [
                'date' => $cursor,
                'in_month' => $cursor->month === $first->month && $cursor->year === $first->year,
                'is_today' => $key === $today->format('Y-m-d'),
                'is_past' => $cursor < $today,
                'count' => $day['count'],
                'titles' => $day['titles'],
            ];

            $cursor = $cursor->addDay();
        }

        return $cells;
    }

    /**
     * La query del calendario: generale (`null`, senza le preferenze di chi
     * guarda) o personale.
     *
     * `EventOccurrenceQuery::for()` legge le preferenze dalla richiesta, e
     * solo sulle rotte che lo dichiarano (`personalize_discovery`): qui lo si
     * spegne o lo si accende per il tempo di costruire la query, così che il
     * risultato non dipenda da quale rotta abbia chiamato.
     */
    private function query(City $city, ?User $user): EventOccurrenceQuery
    {
        return $this->withPersonalisation($user !== null, static fn (): EventOccurrenceQuery => EventOccurrenceQuery::for($city));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    private function withPersonalisation(bool $personal, Closure $build): mixed
    {
        $attributes = request()->attributes;
        $had = $attributes->has('personalize_discovery');
        $before = $attributes->get('personalize_discovery');

        $personal ? $attributes->set('personalize_discovery', true) : $attributes->remove('personalize_discovery');

        try {
            return $build();
        } finally {
            $had ? $attributes->set('personalize_discovery', $before) : $attributes->remove('personalize_discovery');
        }
    }

    private function firstDay(City $city, CarbonImmutable $month): CarbonImmutable
    {
        return $month->setTimezone($city->timezone)->startOfMonth()->startOfDay();
    }

    private function key(City $city, CarbonImmutable $first): string
    {
        return sprintf(
            'calendario:%d:%d:%s',
            (int) $city->getKey(),
            ContentVersion::for($city),
            $first->format('Y-m'),
        );
    }
}
