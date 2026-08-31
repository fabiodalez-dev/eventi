<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EventStatus;
use App\Models\City;
use App\Models\Event;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Archiviazione degli eventi scaduti (§14.5: «scaduti non archiviati»).
 *
 * Archiviare **non** vuol dire cancellare né nascondere: un evento archiviato
 * esce dalle liste e dalle finestre temporali — quelle partono dagli eventi
 * pubblicati (§8) — ma la sua pagina continua a rispondere, resta nell'archivio
 * del locale che l'ha ospitato ed è ancora lì per chi ci arriva da un motore di
 * ricerca o da un collegamento condiviso mesi prima (§11.9). Un 404 su una
 * pagina che ha ricevuto visite per un anno è una perdita secca.
 *
 * La regola è una sola e va letta al contrario di come suona: un evento si
 * archivia quando **non ha più nemmeno una data** dal giorno di taglio in poi.
 * Formulata così — «nessuna occorrenza recente o futura» invece di «l'ultima
 * occorrenza è vecchia» — una rassegna che va avanti da due anni e riprende a
 * ottobre non viene toccata, e nemmeno una ricorrenza settimanale con una sola
 * data ancora in calendario.
 *
 * Il giorno di taglio si conta nel fuso della città (§8.1) e sulla **giornata
 * evento** (`business_date`), non sull'ora di inizio: un concerto che finisce
 * alle 3:00 appartiene alla sera prima, e archiviarlo un giorno in anticipo
 * sarebbe visibile.
 *
 * Gli eventi senza alcuna occorrenza restano dove sono. Non sono scaduti: sono
 * incompleti, e li segnala la dashboard qualità come tali.
 */
final class ArchiveExpiredEvents
{
    /**
     * Quanti identificatori per giro di `UPDATE`.
     */
    private const CHUNK = 500;

    /**
     * Archivia in tutte le città accese e restituisce quanti eventi sono stati
     * toccati, città per città.
     *
     * @return array<string, int> slug della città => eventi archiviati
     */
    public function __invoke(int $days, bool $dryRun = false): array
    {
        $archived = [];

        /** @var iterable<int, City> $cities */
        $cities = City::query()->orderBy('id')->cursor();

        foreach ($cities as $city) {
            $count = $this->forCity($city, $days, $dryRun);

            if ($count > 0) {
                $archived[$city->slug] = $count;
            }
        }

        return $archived;
    }

    /**
     * Il giorno di taglio nel fuso della città: un evento si archivia solo se
     * non ha occorrenze **da questa giornata evento in poi**.
     */
    private function cutoff(City $city, int $days): CarbonImmutable
    {
        return CarbonImmutable::now($city->timezone)->startOfDay()->subDays(max($days, 0));
    }

    /**
     * Gli eventi archiviabili di una città.
     *
     * @return Builder<Event>
     */
    private function query(City $city, int $days): Builder
    {
        $cutoff = $this->cutoff($city, $days)->format('Y-m-d');

        return Event::query()
            ->where('city_id', $city->getKey())
            ->where('status', EventStatus::Published->value)
            /*
             * Un evento senza date non è scaduto: è incompleto. Il primo
             * `EXISTS` è ciò che lo tiene fuori — senza, il `NOT EXISTS` da
             * solo lo archivierebbe il giorno stesso in cui viene creato.
             */
            ->whereExists(fn (QueryBuilder $query) => $query
                ->from('event_occurrences')
                ->whereColumn('event_occurrences.event_id', 'events.id')
                ->selectRaw('1'))
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->from('event_occurrences')
                ->whereColumn('event_occurrences.event_id', 'events.id')
                ->where('event_occurrences.business_date', '>=', $cutoff)
                ->selectRaw('1'))
            ->orderBy('id');
    }

    private function forCity(City $city, int $days, bool $dryRun): int
    {
        $ids = $this->query($city, $days)->pluck('id')->all();

        if ($ids === [] || $dryRun) {
            return count($ids);
        }

        /*
         * Aggiornamento di massa e non un salvataggio per modello: l'observer
         * rimetterebbe in coda un'anteprima social per ciascuno — il file non
         * cambia, cambia lo stato — e su un archivio arretrato sarebbero
         * centinaia di lavori inutili. Ciò che l'observer fa e che qui serve
         * davvero è una riga sola, ed è quella sotto.
         */
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            Event::query()->whereIn('id', $chunk)->update(['status' => EventStatus::Archived->value]);
        }

        ContentVersion::bump((int) $city->getKey());

        return count($ids);
    }
}
