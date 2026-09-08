<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\FollowableType;
use App\Models\Event;
use App\Models\Follow;
use App\Models\User;

/**
 * Seguire un locale, un tag, una categoria (§15.7) o un evento ricorrente
 * (§15.3).
 *
 * I primi tre alimentano il feed e i digest e non producono alcun promemoria
 * puntuale; il quarto è un'altra cosa e lo dice il nome del pulsante: «Segui
 * questo evento» significa «salvami ogni data che nasce», e per questo qui
 * chiama subito il salvataggio delle date già in programma. Senza, chi preme
 * il pulsante oggi non avrebbe nulla in agenda fino alla prossima data
 * generata.
 */
final class FollowSubject
{
    public function __construct(private readonly SaveOccurrences $save) {}

    public function __invoke(User $user, FollowableType $type, int $id, bool $notify = true): Follow
    {
        /** @var Follow $follow */
        $follow = $user->follows()->updateOrCreate(
            ['followable_type' => $type->value, 'followable_id' => $id],
            ['notify' => $notify],
        );

        if ($type === FollowableType::Event) {
            $this->saveExistingDates($user, $id);
        }

        return $follow;
    }

    /**
     * Le date future già in programma di un evento appena seguito. Quali siano
     * future lo decide il motore, dentro `SaveOccurrences`.
     */
    private function saveExistingDates(User $user, int $eventId): void
    {
        $event = Event::query()->with('city')->find($eventId);

        if ($event === null || $event->city === null) {
            return;
        }

        $ids = $event->occurrences()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $this->save->many($user, $event->city, $ids);
    }
}
