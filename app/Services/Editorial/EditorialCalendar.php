<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\EventStatus;
use App\Models\EventOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Il calendario della redazione (R-EV-27): le date in un intervallo, con il
 * titolo e il colore che il pannello disegna. La stessa classe la usano il
 * riquadro `EventsCalendarWidget` della pagina «Calendario» e
 * `GET /api/v1/editorial/calendar`, così sito e app mostrano le stesse
 * caselle.
 *
 * **Le date, non gli eventi**: una rassegna di dieci serate compare dieci
 * volte. **Tutte le città**, come il pannello: il calendario non filtra per
 * la città di lavoro. **Il colore dice lo stato dell'evento**, non la
 * categoria: chi lo apre sta moderando.
 */
final class EditorialCalendar
{
    /** L'app chiede al massimo poco più di un mese con le settimane a cavallo: sei settimane, più un margine. */
    public const MAX_DAYS = 62;

    /** Oltre, la risposta si tronca e lo dice (`meta.truncated`): un mese vero ne ha molte meno. */
    public const MAX_ROWS = 1500;

    /**
     * Le date che cominciano fra `$from` (compreso) e `$until`, con evento e
     * locali caricati insieme, in ordine di inizio. Gli eventi nel cestino
     * restano fuori.
     *
     * @return Builder<EventOccurrence>
     */
    public function query(CarbonInterface $from, CarbonInterface $until): Builder
    {
        return EventOccurrence::query()
            ->with(['event.venue', 'event.city', 'venue'])
            /*
             * Solo la finestra che il calendario sta mostrando: senza questo
             * filtro si caricherebbero tutte le occorrenze mai generate, e
             * `occurrences:generate` ne produce dodici mesi per ricorrenza.
             */
            ->whereBetween('starts_at', [$from, $until])
            ->whereHas('event', fn (Builder $q) => $q->whereNull('deleted_at'))
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    /** «Titolo · Locale»: su una casella larga cento pixel non c'è spazio per due righe. */
    public static function title(EventOccurrence $occurrence): string
    {
        $venue = $occurrence->effectiveVenue()?->name;

        return $venue === null ? (string) $occurrence->event->title : $occurrence->event->title.' · '.$venue;
    }

    /** Il colore della casella secondo lo stato dell'evento, come nel pannello. */
    public static function color(EventStatus $status): string
    {
        return match ($status) {
            EventStatus::Published => '#7a9900',
            EventStatus::Pending => '#b45309',
            EventStatus::Draft => '#6b6b66',
            EventStatus::Rejected => '#b91c1c',
            EventStatus::Archived => '#a1a1aa',
            EventStatus::Cancelled => '#7f1d1d',
        };
    }

    /**
     * La legenda dei colori, nell'ordine degli stati.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function legend(): array
    {
        return array_map(
            static fn (EventStatus $status): array => ['value' => $status->value, 'label' => $status->label(), 'color' => self::color($status)],
            EventStatus::cases(),
        );
    }

    /** La fine da disegnare: quella effettiva, poi quella dichiarata, poi l'inizio. */
    public static function end(EventOccurrence $occurrence): CarbonInterface
    {
        return $occurrence->effective_ends_at ?? $occurrence->ends_at ?? $occurrence->starts_at;
    }

    /**
     * Le righe per l'app: i giorni `from`…`to` compresi, nel fuso `$timezone`
     * (quello della città di lavoro). Ogni data porta inizio e fine nel fuso
     * della **sua** città, come la casella del pannello.
     *
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function rows(User $viewer, CarbonImmutable $fromDay, CarbonImmutable $toDay, string $timezone): array
    {
        $from = $fromDay->setTimezone($timezone)->startOfDay()->utc();
        $until = $toDay->setTimezone($timezone)->addDay()->startOfDay()->utc()->subSecond();

        /** @var Collection<int, EventOccurrence> $found */
        $found = $this->query($from, $until)->limit(self::MAX_ROWS + 1)->get();
        $truncated = $found->count() > self::MAX_ROWS;
        $gate = Gate::forUser($viewer);

        $rows = $found->take(self::MAX_ROWS)->map(function (EventOccurrence $occurrence) use ($gate): array {
            $event = $occurrence->event;
            $zone = $event->city->timezone;
            $starts = $occurrence->starts_at->copy()->setTimezone($zone);
            $venue = $occurrence->effectiveVenue();

            return [
                'id' => (int) $occurrence->getKey(),
                'event_id' => (int) $event->getKey(),
                'title' => self::title($occurrence),
                'event_title' => (string) $event->title,
                'venue' => $venue?->name,
                'city' => ['id' => (int) $event->city->getKey(), 'name' => (string) $event->city->name, 'timezone' => $zone],
                'date' => $starts->toDateString(),
                'starts_at' => $starts->toIso8601String(),
                'ends_at' => self::end($occurrence)->copy()->setTimezone($zone)->toIso8601String(),
                'all_day' => (bool) $occurrence->is_all_day,
                'event_status' => ['value' => $event->status->value, 'label' => $event->status->label()],
                'status' => ['value' => $occurrence->status->value, 'label' => $occurrence->status->label()],
                'color' => self::color($event->status),
                'abilities' => [
                    'open' => $gate->allows('view', $event),
                    'edit' => $gate->allows('update', $event),
                ],
            ];
        })->values()->all();

        return ['rows' => $rows, 'truncated' => $truncated];
    }
}
