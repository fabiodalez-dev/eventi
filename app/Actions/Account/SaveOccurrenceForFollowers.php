<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\FollowableType;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Services\Notifications\NotificationScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Segui questo evento»: ogni nuova data generata viene salvata automaticamente
 * per chi lo segue (§15.3).
 *
 * L'aggancio è la creazione dell'occorrenza e non la generazione della serie:
 * una data aggiunta a mano dal gestore a una rassegna seguita è, per chi la
 * segue, esattamente la stessa notizia di una data generata dalla regola.
 *
 * Qui non si passa dal motore temporale di proposito: una data appena creata
 * appartiene per costruzione al futuro della serie, e chiedere al motore
 * significherebbe interrogare la città e la pubblicazione dell'evento a ogni
 * riga di una generazione da cinquanta date. Il motore torna in gioco al primo
 * salvataggio fatto da una persona.
 */
final class SaveOccurrenceForFollowers
{
    public function __construct(private readonly NotificationScheduler $scheduler) {}

    public function __invoke(EventOccurrence $occurrence): int
    {
        /** @var list<int> $followers */
        $followers = Follow::query()
            ->where('followable_type', FollowableType::Event->value)
            ->where('followable_id', $occurrence->event_id)
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($followers === []) {
            return 0;
        }

        $now = CarbonImmutable::now();

        $saved = DB::table('saved_events')->insertOrIgnore(array_map(
            static fn (int $userId): array => [
                'user_id' => $userId,
                'occurrence_id' => $occurrence->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $followers,
        ));

        /*
         * Una data salvata automaticamente è una data salvata: porta con sé i
         * suoi promemoria come qualunque altra (§15.5). Chi segue una rassegna
         * non deve accorgersi che il salvataggio non l'ha fatto lui.
         */
        $this->scheduler->remindersFor($occurrence, $followers);

        return $saved;
    }
}
