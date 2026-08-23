<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Annullare o spostare una data — **una sola**, oppure **tutta la serie**
 * (§9.2). È la distinzione che rende utilizzabile un cartellone ricorrente:
 * la rassegna del giovedì salta una settimana per la festa patronale, oppure
 * cambia orario da qui a fine stagione, e sono due gesti diversi.
 *
 * Tre regole valgono per l'ambito "serie" e sono ciò che rende questa classe
 * più di un `update` di massa:
 *
 * 1. **Solo la stessa ricorrenza.** Una data inserita a mano non appartiene ad
 *    alcuna serie e non viene mai trascinata: se `recurrence_id` è nullo,
 *    "serie" ricade su "questa data soltanto".
 * 2. **Solo il futuro.** Le date già passate restano com'erano: riscrivere il
 *    passato falserebbe le statistiche e le notifiche già inviate.
 * 3. **Le eccezioni non si toccano.** Una data già modificata a mano
 *    (`is_exception = true`, che imposta `EventOccurrenceObserver` — D21) ha
 *    ricevuto una decisione esplicita: la modifica di serie la scavalcherebbe.
 *    L'occorrenza da cui parte il comando fa eccezione a questa eccezione,
 *    perché è quella su cui si è cliccato.
 *
 * Ogni riga viene salvata singolarmente e non con un `UPDATE` di massa: sono
 * gli eventi del model a ricalcolare `business_date` ed `effective_ends_at`
 * (§5 delle convenzioni), e una query di massa li salterebbe lasciando le
 * colonne persistite a mentire.
 */
final class UpdateOccurrencesAction
{
    /**
     * @return int quante date sono state modificate
     */
    public function changeStatus(
        EventOccurrence $occurrence,
        OccurrenceStatus $status,
        ?string $note,
        OccurrenceScope $scope,
    ): int {
        return $this->apply($occurrence, $scope, function (EventOccurrence $target) use ($status, $note): void {
            $target->status = $status;
            $target->status_note = $note;
        });
    }

    /**
     * Sposta l'inizio (e con esso porte e fine, se ci sono) di un numero di
     * minuti, mantenendo la durata.
     *
     * @return int quante date sono state modificate
     */
    public function shift(EventOccurrence $occurrence, int $minutes, OccurrenceScope $scope): int
    {
        if ($minutes === 0) {
            return 0;
        }

        return $this->apply($occurrence, $scope, function (EventOccurrence $target) use ($minutes): void {
            $target->starts_at = CarbonImmutable::instance($target->starts_at)->addMinutes($minutes)->toMutable();

            if ($target->ends_at !== null) {
                $target->ends_at = CarbonImmutable::instance($target->ends_at)->addMinutes($minutes)->toMutable();
            }

            if ($target->doors_at !== null) {
                $target->doors_at = CarbonImmutable::instance($target->doors_at)->addMinutes($minutes)->toMutable();
            }
        });
    }

    /**
     * @param  callable(EventOccurrence): void  $mutate
     */
    private function apply(EventOccurrence $occurrence, OccurrenceScope $scope, callable $mutate): int
    {
        $targets = $this->targets($occurrence, $scope);

        return DB::transaction(function () use ($targets, $mutate): int {
            $changed = 0;

            foreach ($targets as $target) {
                $mutate($target);

                if ($target->isDirty()) {
                    $target->save();
                    $changed++;
                }
            }

            return $changed;
        });
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    private function targets(EventOccurrence $occurrence, OccurrenceScope $scope): Collection
    {
        if ($scope === OccurrenceScope::Single || $occurrence->recurrence_id === null) {
            /** @var Collection<int, EventOccurrence> $single */
            $single = EventOccurrence::query()->whereKey($occurrence->getKey())->get();

            return $single;
        }

        return EventOccurrence::query()
            ->where('recurrence_id', $occurrence->recurrence_id)
            ->where('starts_at', '>=', $occurrence->starts_at)
            ->where(fn (Builder $query) => $query
                ->where('is_exception', false)
                ->orWhere($occurrence->getQualifiedKeyName(), $occurrence->getKey()))
            ->orderBy('starts_at')
            ->get();
    }
}
