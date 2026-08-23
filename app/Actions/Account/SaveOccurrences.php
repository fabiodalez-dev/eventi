<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Notifications\NotificationScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Salvare date (§15.3). **Si salva l'occorrenza, non l'evento**: un evento con
 * dieci serate salvato "tutto" produrrebbe dieci promemoria sbagliati o uno
 * solo, arbitrario.
 *
 * Tre regole valgono per ogni salvataggio, e stanno qui perché nessun chiamante
 * le riscriva:
 *
 * - **una data già passata non si salva** — e "passata" lo decide
 *   `EventOccurrenceQuery`, non un confronto scritto qui (§8.1);
 * - **una data non pubblica non si salva** — la base del motore è già ristretta
 *   agli eventi pubblicati della città, quindi chiedere al motore *è* il
 *   controllo;
 * - **salvare due volte non è un errore**: il vincolo unico
 *   `(user_id, occurrence_id)` regge, e il secondo click restituisce la riga
 *   che c'era già.
 */
final class SaveOccurrences
{
    public function __construct(private readonly NotificationScheduler $scheduler) {}

    /**
     * Una sola data. `null` quando quella data non è salvabile: passata,
     * inesistente o non pubblica.
     */
    public function one(User $user, EventOccurrence $occurrence): ?SavedEvent
    {
        $city = $occurrence->event->city;

        if (! $city instanceof City) {
            return null;
        }

        $saved = $this->many($user, $city, [(int) $occurrence->getKey()]);

        return $saved->first();
    }

    /**
     * Un insieme di date. Le identifica il motore: ciò che non passa dal
     * filtro non esiste, non è pubblico o è già passato — e in tutti e tre i
     * casi va ignorato in silenzio (§15.1).
     *
     * @param  array<int, int>  $occurrenceIds
     * @return Collection<int, SavedEvent>
     */
    public function many(User $user, City $city, array $occurrenceIds): Collection
    {
        $ids = $this->identifiers($occurrenceIds);

        if ($ids === []) {
            return new Collection;
        }

        $savable = EventOccurrenceQuery::for($city)
            ->forOccurrences($ids)
            ->upcoming()
            ->get()
            ->map(static fn (EventOccurrence $occurrence): int => (int) $occurrence->getKey())
            ->all();

        if ($savable === []) {
            return new Collection;
        }

        $now = CarbonImmutable::now();

        /*
         * `insertOrIgnore` e non `firstOrCreate` riga per riga: la migrazione
         * di §15.1 arriva con decine di identificativi in una volta sola, e il
         * vincolo unico è già il guardiano dei duplicati.
         */
        DB::table('saved_events')->insertOrIgnore(array_map(
            static fn (int $id): array => [
                'user_id' => $user->getKey(),
                'occurrence_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $savable,
        ));

        $saved = SavedEvent::query()
            ->where('user_id', $user->getKey())
            ->whereIn('occurrence_id', $savable)
            ->get();

        /*
         * Il gancio di §15.5: **il promemoria nasce dal salvataggio**, non da
         * una scansione periodica dei salvataggi. Da questo momento l'invio
         * previsto è una riga con la propria chiave di deduplica, visibile nel
         * pannello prima ancora di partire.
         *
         * Sta qui e non nei due controller perché il sito e l'API salvano
         * dalla stessa azione: metterlo più in alto significherebbe scriverlo
         * due volte e dimenticarlo una.
         */
        $this->scheduler->remindersForMany(
            EventOccurrence::query()->whereIn('id', $savable)->get(),
            [(int) $user->getKey()],
        );

        return $saved;
    }

    /**
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function identifiers(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0 && ! in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return $clean;
    }
}
