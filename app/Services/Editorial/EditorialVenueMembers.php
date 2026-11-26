<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Actions\InviteVenueMemberAction;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Support\SecurityLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * Referenti e collaboratori di un locale visti dalla redazione (§9.2,
 * «assegnazione owner ed editor»): la sezione «Persone» della scheda del
 * pannello e `E/venues/{venue}/members` dell'app.
 *
 * Il ruolo sta nella pivot `venue_user`, non sull'utente. Il permesso
 * (`VenuePolicy::manageCollaborators`, admin e super admin in redazione) lo
 * controlla chi chiama.
 *
 * Le regole vengono da `MembersRelationManager`, che adesso chiama questa
 * classe:
 *
 * - **Invita per email**: chi è già referente, o ha già quel ruolo, non
 *   riceve un secondo messaggio e non viene declassato; il resto lo fa
 *   `InviteVenueMemberAction`, come per l'invito del referente.
 * - **Aggiungi un account**: solo con l'email confermata (uno mai confermato
 *   può averlo registrato chiunque); entra subito, senza invito da
 *   accettare.
 * - **Modifica**: ruolo e data di accettazione; un invito confermato a mano
 *   diventa un'appartenenza vera e prende anche il ruolo globale.
 * - **Scollega**: toglie la riga.
 *
 * Ogni accesso dato, cambiato o tolto va nel registro di sicurezza con chi lo
 * ha deciso.
 */
final class EditorialVenueMembers
{
    public const CANDIDATES_LIMIT = 50;

    public function __construct(private readonly InviteVenueMemberAction $invitations) {}

    /**
     * Le persone del locale, inviti in sospeso compresi, con la riga della
     * pivot (`role`, `invited_at`, `accepted_at`).
     *
     * @return Collection<int, User>
     */
    public function list(Venue $venue): Collection
    {
        return $venue->membersIncludingInvited()->orderBy('users.name')->get();
    }

    /** La riga di una persona del locale, invito compreso; `null` se non c'è. */
    public function find(Venue $venue, int $userId): ?User
    {
        $user = $venue->membersIncludingInvited()->whereKey($userId)->first();

        return $user instanceof User ? $user : null;
    }

    /**
     * Se l'invito per email non serve: chi ha già quel ruolo, o è referente,
     * non lo riceve. Risponde con il messaggio da mostrare (con il ruolo
     * attuale), oppure `null` quando l'invito va mandato.
     */
    public function alreadyThere(Venue $venue, string $email, VenueRole $role): ?string
    {
        $pivot = $venue->membersIncludingInvited()->where('users.email', mb_strtolower(trim($email)))->first()?->getRelationValue('pivot');
        $pivot = $pivot instanceof Pivot ? $pivot : null;
        $current = $pivot?->getAttribute('role');
        if ($pivot === null || ! ($current === VenueRole::Owner->value || $current === $role->value)) {
            return null;
        }
        $key = $pivot->getAttribute('accepted_at') === null ? 'admin.notifications.already_invited' : 'admin.notifications.already_member';

        return (string) __($key, ['role' => mb_strtolower(VenueRole::from((string) $current)->label())]);
    }

    /** L'invito per email: l'account, se manca, e il messaggio li mette `InviteVenueMemberAction`. */
    public function invite(Venue $venue, string $email, string $name, VenueRole $role): User
    {
        return $this->invitations->execute($venue, $email, $name, $role);
    }

    /**
     * Gli account che si possono aggiungere: email confermata, non ancora del
     * locale (nemmeno con un invito in sospeso), cercati per nome o email.
     *
     * @return Collection<int, User>
     */
    public function candidates(Venue $venue, string $search = ''): Collection
    {
        $search = trim($search);

        return User::query()
            ->whereNotNull('email_verified_at')
            ->whereNotIn('users.id', $venue->membersIncludingInvited()->select('users.id'))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $where): Builder => $where
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->limit(self::CANDIDATES_LIMIT)
            ->get(['id', 'name', 'email']);
    }

    /** Se un account si può aggiungere: lo stesso filtro di `candidates()`. */
    public function canAttach(Venue $venue, User $user): bool
    {
        return $user->email_verified_at !== null && $this->find($venue, (int) $user->getKey()) === null;
    }

    /**
     * I valori della riga quando la redazione aggiunge un account: entra
     * subito, senza invito da accettare (D64).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attachPivot(array $data): array
    {
        $data['invited_at'] = now();
        $data['accepted_at'] = now();

        return $data;
    }

    /** «Aggiungi un account» dall'app: la riga di `attachPivot()` e il registro. */
    public function attach(Venue $venue, User $user, VenueRole $role, ?User $actor): void
    {
        $venue->membersIncludingInvited()->attach($user->getKey(), $this->attachPivot(['role' => $role->value]));
        $this->log('accesso_locale_concesso', $venue, $user, $role->value, $actor);
    }

    /**
     * Dopo la modifica di una riga: un invito confermato a mano dalla
     * redazione diventa un'appartenenza vera e serve anche il ruolo globale,
     * che l'invito da accettare non assegna più.
     */
    public function afterUpdate(Venue $venue, User $user, ?string $role, mixed $acceptedAt, ?User $actor): void
    {
        if (filled($acceptedAt) && filled($role)) {
            InviteVenueMemberAction::ensureGlobalRole($user, VenueRole::from((string) $role));
        }
        $this->log('accesso_locale_modificato', $venue, $user, (string) ($role ?? ''), $actor);
    }

    /** «Modifica» dall'app: ruolo e data di accettazione (vuota = invito ancora da accettare). */
    public function update(Venue $venue, User $user, VenueRole $role, ?CarbonInterface $acceptedAt, ?User $actor): void
    {
        $venue->membersIncludingInvited()->updateExistingPivot($user->getKey(), [
            'role' => $role->value,
            'accepted_at' => $acceptedAt,
        ]);
        $this->afterUpdate($venue, $user, $role->value, $acceptedAt, $actor);
    }

    /** «Scollega» dall'app: toglie la riga e lo scrive nel registro. */
    public function detach(Venue $venue, User $user, ?User $actor): void
    {
        $venue->membersIncludingInvited()->detach($user->getKey());
        $this->log('accesso_locale_revocato', $venue, $user, null, $actor);
    }

    /**
     * Chi entra in un locale, con quale ruolo, e chi lo ha deciso: senza
     * questa riga un accesso dato e tolto dopo l'uso non lascerebbe traccia.
     */
    public function log(string $evento, Venue $venue, User $user, ?string $ruolo, ?User $actor): void
    {
        SecurityLog::scrivi($evento, $user, array_filter([
            'venue_id' => $venue->getKey(),
            'ruolo' => $ruolo,
        ], fn (mixed $valore): bool => filled($valore)), causa: $actor);
    }
}
