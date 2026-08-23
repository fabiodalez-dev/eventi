<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\City;
use App\Queries\EventOccurrenceQuery;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
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
            fn (): array => EventOccurrenceQuery::for($city)
                ->between($first, $first->endOfMonth())
                ->dailyDigest(self::TITLES_PER_DAY),
        );

        return $digest;
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
        $first = $this->firstDay($city, $month);
        $digest = $this->digest($city, $month);

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
