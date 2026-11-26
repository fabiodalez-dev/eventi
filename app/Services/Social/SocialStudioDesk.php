<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\OccurrenceStatus;
use App\Enums\SocialFormat;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Le regole dello studio delle grafiche social, scritte una volta per il
 * pannello (`InteractsWithSocialStudio`) e per l'app di gestione
 * (`SocialStudioController`, P10).
 *
 * Il pannello tiene lo stato della pagina (periodo, giorno, selezione) e
 * chiede qui le cose che decidono: gli estremi di un periodo, il giorno da
 * cui aprire lo studio, il piccolo calendario, quali serate di un giorno si
 * possono davvero preparare, la preparazione del pacchetto e gli ultimi
 * pacchetti. L'app manda gli stessi valori e riceve le stesse risposte.
 */
final class SocialStudioDesk
{
    /** I periodi dell'elenco, nell'ordine in cui compaiono. */
    public const PERIODS = ['today', 'week', 'next30', 'custom'];

    /** Quante card si mostrano per volta, e al massimo con «mostra altre». */
    public const PAGE_SIZE = 24;

    public const MAX_CARDS = 240;

    /** Quante serate al massimo in un pacchetto. */
    public const MAX_SELECTION = 100;

    /** Quanti pacchetti recenti si riaprono (e quanti ne tiene la pulizia, `SocialBatch::prunable`). */
    public const RECENT = SocialBatch::KEEP_PER_OWNER;

    /** Il titolo scritto a mano della grafica, solo con una serata. */
    public const TITLE_MAX = 300;

    public function __construct(private readonly SocialCatalog $catalog) {}

    /**
     * Gli estremi di un periodo: oggi, fino a domenica, trenta giorni, oppure
     * le date scelte a mano (vuote: da oggi a fra 29 giorni).
     *
     * @return array{0: string, 1: string}
     */
    public static function periodBounds(City $city, string $period, string $from = '', string $until = ''): array
    {
        $today = CarbonImmutable::parse(EventOccurrenceQuery::for($city)->currentBusinessDate());
        [$start, $end] = match ($period) {
            'today' => [$today, $today],
            'week' => [$today, $today->endOfWeek(CarbonImmutable::SUNDAY)],
            'custom' => [$from !== '' ? CarbonImmutable::parse($from) : $today, $until !== '' ? CarbonImmutable::parse($until) : $today->addDays(29)],
            default => [$today, $today->addDays(29)],
        };

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * Un periodo scritto a mano messo in ordine e tenuto entro un anno.
     *
     * @return array{0: string, 1: string}
     */
    public static function clampRange(string $from, string $until): array
    {
        [$from, $until] = $from <= $until ? [$from, $until] : [$until, $from];

        return [$from, min($until, CarbonImmutable::parse($from)->addYear()->format('Y-m-d'))];
    }

    /**
     * Il giorno da cui si apre lo studio: oggi; con un evento, la sua prossima
     * data; se quel giorno non c'è niente da preparare, la prima serata in
     * programma dei prossimi trenta giorni. Il permesso sull'evento lo
     * controlla chi chiama.
     */
    public function initialDate(City $city, ?int $venueId, ?Event $event, bool $includeDemo): string
    {
        $date = EventOccurrenceQuery::for($city)->currentBusinessDate();
        if ($event !== null) {
            $next = EventOccurrenceQuery::managementFor($event)->upcoming()->get()->first();
            if ($next !== null) {
                $date = $next->business_date->format('Y-m-d');
            }
        }
        if ($this->catalog->events($city, $date, $venueId, $event?->getKey(), $includeDemo)->isEmpty()) {
            [$from, $until] = self::periodBounds($city, 'next30');
            $first = $this->catalog->period($city, $from, $until, $venueId, $event?->getKey(), $includeDemo, '', self::PAGE_SIZE)
                ->first(fn (EventOccurrence $occurrence): bool => $occurrence->status === OccurrenceStatus::Scheduled);
            if ($first !== null) {
                $date = $first->business_date->format('Y-m-d');
            }
        }

        return $date;
    }

    /**
     * Il piccolo calendario del mese (`Y-m`): sei settimane da lunedì, con il
     * numero di serate di ogni giorno.
     *
     * @return list<array{date: string, day: int, count: int, inMonth: bool, today: bool, selected: bool}>
     */
    public function calendar(City $city, string $month, string $selected, ?int $venueId, ?int $eventId, bool $includeDemo, string $search = ''): array
    {
        $first = CarbonImmutable::parse($month.'-01', $city->timezone);
        $start = $first->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addDays(41);
        $counts = $this->catalog->counts($city, $start->format('Y-m-d'), $end->format('Y-m-d'), $venueId, $eventId, $includeDemo, $search);
        $today = EventOccurrenceQuery::for($city)->currentBusinessDate();
        $days = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $days[] = ['date' => $key, 'day' => $day->day, 'count' => $counts[$key] ?? 0, 'inMonth' => $day->month === $first->month, 'today' => $key === $today, 'selected' => $key === $selected];
        }

        return $days;
    }

    /**
     * Le serate scelte di un giorno, solo fra quelle che si possono preparare
     * (in programma, del locale, dell'evento se c'è). Una serata in più o di
     * un altro locale è un tentativo, non un errore di battitura: 403.
     *
     * @param  list<int>  $ids
     * @return Collection<int, EventOccurrence>
     */
    public function selection(City $city, string $date, array $ids, ?int $venueId, ?int $eventId, bool $includeDemo): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $dates = $this->catalog->events($city, $date, $venueId, $eventId, $includeDemo)->whereIn('id', $ids)->values();
        abort_unless($dates->count() === count($ids), 403);

        return $dates;
    }

    /**
     * Prepara il pacchetto di un giorno. Il titolo scritto a mano vale solo
     * per una serata (`title` fra gli errori). Gli errori della grafica (un
     * titolo troppo lungo da disegnare, lo spazio su disco, il tetto
     * giornaliero del locale) sono `SocialStudioException`, come in
     * `SocialStudio::generate`.
     *
     * @param  list<int>  $ids
     * @param  array{monochrome: bool, show_address: bool, show_price: bool, image_fit: string, title: string}  $options
     */
    public function prepare(City $city, string $date, array $ids, SocialFormat $format, array $options, User $user, ?int $venueId, ?int $eventId, bool $includeDemo): SocialBatch
    {
        $dates = $this->selection($city, $date, $ids, $venueId, $eventId, $includeDemo);
        if ($dates->count() !== 1 && $options['title'] !== '') {
            throw ValidationException::withMessages(['title' => __('social.title_single')]);
        }

        return app(SocialStudio::class)->generate($city, $date, $dates, $format, $options, $user, $venueId);
    }

    /**
     * «Social di oggi» del riepilogo della redazione: le serate di oggi che
     * si possono preparare, senza i dimostrativi (come il carosello
     * automatico).
     *
     * @return Collection<int, EventOccurrence>
     */
    public function today(City $city): Collection
    {
        return $this->catalog->events($city, EventOccurrenceQuery::for($city)->currentBusinessDate());
    }

    /**
     * «Scarica le grafiche di oggi»: un pacchetto verticale con lo stile
     * predefinito e tutte le serate di oggi. Gli errori della grafica sono
     * quelli di `SocialStudio::generate`.
     */
    public function prepareToday(City $city, ?User $user): SocialBatch
    {
        return app(SocialStudio::class)->generate($city, EventOccurrenceQuery::for($city)->currentBusinessDate(), $this->today($city), SocialFormat::Portrait, [], $user);
    }

    /**
     * Gli ultimi pacchetti preparati nel pannello del locale (o della
     * redazione, con `$venueId` nullo), dal più recente.
     *
     * @return EloquentCollection<int, SocialBatch>
     */
    public function recent(City $city, ?int $venueId): EloquentCollection
    {
        return SocialBatch::query()->where('city_id', $city->getKey())->where('venue_id', $venueId)->latest()->limit(self::RECENT)->get();
    }
}
