<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organizer;
use App\Models\User;

/**
 * Chi entra e chi esce da un organizzatore, nel registro di sicurezza.
 *
 * Il responsabile (`owner_id`) e i collaboratori (`organizer_user`) aprono
 * `/organizza` e i dati dell'organizzatore come un ruolo: per i locali c'era
 * già `accesso_locale_concesso` / `accesso_locale_revocato`, per gli
 * organizzatori non restava niente (né qui né nel registro attività del
 * modello), e chi era stato messo responsabile, e quando, non si
 * ricostruiva. Le righe sono le stesse dei locali, con `organizer_id`:
 *
 * - `accesso_organizzatore_concesso`: la persona diventa responsabile
 *   (`ruolo: responsabile`) o collaboratore (`ruolo: collaboratore`);
 * - `accesso_organizzatore_revocato`: smette di esserlo.
 *
 * Si usa in due tempi, perché il pannello salva il modello e poi la tabella
 * dei collaboratori: `snapshot()` prima del salvataggio, `record()` dopo.
 * La chiamano il modulo degli organizzatori del pannello e l'app della
 * redazione (`EditorialOrganizers`).
 */
final class OrganizerAccessLog
{
    public const OWNER = 'responsabile';

    public const MEMBER = 'collaboratore';

    /**
     * Responsabile e collaboratori in questo momento (vuoto per un
     * organizzatore che non esiste ancora).
     *
     * @return array{owner: int|null, users: list<int>}
     */
    public static function snapshot(?Organizer $organizer): array
    {
        if (! $organizer instanceof Organizer || ! $organizer->exists) {
            return ['owner' => null, 'users' => []];
        }

        $owner = $organizer->newQuery()->whereKey($organizer->getKey())->value('owner_id');

        return [
            'owner' => $owner === null ? null : (int) $owner,
            'users' => $organizer->users()->pluck('users.id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all(),
        ];
    }

    /**
     * Confronta con `$before` e scrive una riga per ogni persona che entra o
     * esce. Un salvataggio che non cambia le persone non scrive niente.
     *
     * @param  array{owner: int|null, users: list<int>}  $before
     */
    public static function record(Organizer $organizer, array $before, ?User $actor = null): void
    {
        $after = self::snapshot($organizer);
        $actor ??= self::actor();

        if ($before['owner'] !== $after['owner']) {
            self::write('accesso_organizzatore_revocato', $before['owner'], $organizer, self::OWNER, $actor);
            self::write('accesso_organizzatore_concesso', $after['owner'], $organizer, self::OWNER, $actor);
        }
        foreach (array_diff($before['users'], $after['users']) as $id) {
            self::write('accesso_organizzatore_revocato', $id, $organizer, self::MEMBER, $actor);
        }
        foreach (array_diff($after['users'], $before['users']) as $id) {
            self::write('accesso_organizzatore_concesso', $id, $organizer, self::MEMBER, $actor);
        }
    }

    private static function write(string $event, ?int $userId, Organizer $organizer, string $role, ?User $actor): void
    {
        if ($userId === null) {
            return;
        }

        SecurityLog::scrivi($event, User::withTrashed()->find($userId), [
            'organizer_id' => (int) $organizer->getKey(),
            'ruolo' => $role,
        ], causa: $actor);
    }

    private static function actor(): ?User
    {
        $user = app()->bound('request') ? request()->user() : null;

        return $user instanceof User ? $user : null;
    }
}
