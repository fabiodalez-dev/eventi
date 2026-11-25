<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Actions\InviteVenueMemberAction;
use App\Actions\RemoveVenueMember;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * I collaboratori di un locale (§10.6), scritti una volta per la pagina
 * «Collaboratori» del pannello e per l'API dell'app.
 *
 * Qui stanno le regole che prima vivevano solo nella pagina: chi compare
 * nell'elenco e come (gli inviti in attesa senza nome e con l'email
 * mascherata), il tetto degli inviti, chi si reinvita, si ritira o si toglie.
 * Il permesso di farlo (`VenuePolicy::manageCollaborators`, il referente) lo
 * controlla chi chiama, come prima.
 *
 * **Il tetto è lo stesso secchio sul sito e nell'app.** Ogni invito crea un
 * account e spedisce un'email dal nostro indirizzo a chi il referente
 * sceglie: senza un tetto, il pannello diventerebbe un modo di scrivere a una
 * lista intera. La chiave è per persona e per locale, come per l'import da
 * Facebook, e la condividono invito e «Reinvia»: dieci l'ora, trenta al
 * giorno, comunque li si mandi.
 */
final class VenueCollaborators
{
    public const INVITES_PER_HOUR = 10;

    public const INVITES_PER_DAY = 30;

    public function __construct(
        private readonly InviteVenueMemberAction $invitations,
        private readonly RemoveVenueMember $removal,
    ) {}

    /**
     * Le persone del locale con il ruolo che hanno **qui**: il ruolo sta nella
     * pivot, non sull'utente, perché la stessa persona può essere referente di
     * un locale e collaboratore di un altro. Inviti in attesa compresi.
     *
     * @return Builder<User>
     */
    public function members(Venue $venue): Builder
    {
        return User::query()
            ->join('venue_user', 'venue_user.user_id', '=', 'users.id')
            ->where('venue_user.venue_id', $venue->getKey())
            ->select([
                'users.*',
                'venue_user.role as venue_role',
                'venue_user.invited_at as venue_invited_at',
                'venue_user.accepted_at as venue_accepted_at',
            ]);
    }

    /**
     * Prima chi è dentro, per nome; poi gli inviti, per email: un ordinamento
     * per nome mescolerebbe gli inviti secondo il nome dell'account, che non
     * si vede.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function sorted(Builder $query): Builder
    {
        return $query
            ->orderByRaw('venue_user.accepted_at is null')
            ->orderByRaw('case when venue_user.accepted_at is null then users.email else users.name end');
    }

    /**
     * La ricerca della pagina: il nome si cerca solo fra chi ha accettato
     * (cercarlo fra gli inviti direbbe di chi è l'account), l'email fra tutti.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function search(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->where(fn (Builder $where): Builder => $where
            ->where(fn (Builder $name): Builder => $name->whereNotNull('venue_user.accepted_at')->where('users.name', 'like', $like))
            ->orWhere('users.email', 'like', $like));
    }

    /**
     * L'invito di un collaboratore: il ruolo è sempre collaboratore, mai
     * referente. Chi è già del locale non si reinvita (il ruolo non cambia da
     * qui, e un secondo messaggio non serve a nessuno); chi ha un invito in
     * attesa si reinvita dalla sua riga. Il messaggio, l'account e la riga li
     * mette `InviteVenueMemberAction`.
     */
    public function invite(Venue $venue, User $actor, string $email, string $name): CollaboratorOutcome
    {
        if ($this->limitReached($actor, $venue)) {
            return CollaboratorOutcome::LimitReached;
        }

        $existing = $venue->membersIncludingInvited()->where('users.email', mb_strtolower(trim($email)))->first();
        if ($existing instanceof User) {
            return $existing->getRelationValue('pivot')?->getAttribute('accepted_at') === null
                ? CollaboratorOutcome::AlreadyInvited
                : CollaboratorOutcome::AlreadyMember;
        }

        $this->hitLimit($actor, $venue);
        $this->invitations->execute($venue, $email, $name, VenueRole::Editor);

        return CollaboratorOutcome::Invited;
    }

    /**
     * «Reinvia»: un collegamento nuovo, che fa scadere il precedente. Conta
     * nello stesso tetto degli inviti, perché spedisce lo stesso messaggio.
     */
    public function resend(Venue $venue, User $actor, User $member): CollaboratorOutcome
    {
        if (! $this->isPendingEditorInvite($venue, $member)) {
            return CollaboratorOutcome::NotAllowed;
        }

        if ($this->limitReached($actor, $venue)) {
            return CollaboratorOutcome::LimitReached;
        }

        $this->hitLimit($actor, $venue);
        $this->invitations->resend($venue, $member, $actor);

        return CollaboratorOutcome::Resent;
    }

    /** «Ritira l'invito»: solo un invito da collaboratore ancora in attesa. */
    public function withdraw(Venue $venue, User $actor, User $member): CollaboratorOutcome
    {
        if (! $this->isPendingEditorInvite($venue, $member)) {
            return CollaboratorOutcome::NotAllowed;
        }

        return $this->invitations->withdraw($venue, $member, $actor)
            ? CollaboratorOutcome::Withdrawn
            : CollaboratorOutcome::NotAllowed;
    }

    /** Toglie un collaboratore che ha accettato; mai un referente (`RemoveVenueMember`). */
    public function remove(Venue $venue, User $actor, User $member): CollaboratorOutcome
    {
        return $this->removal->execute($venue, $member, $actor)
            ? CollaboratorOutcome::Removed
            : CollaboratorOutcome::NotAllowed;
    }

    public function limitReached(User $actor, Venue $venue): bool
    {
        [$key, $dailyKey] = self::limitKeys($actor, $venue);

        return RateLimiter::tooManyAttempts($key, self::INVITES_PER_HOUR)
            || RateLimiter::tooManyAttempts($dailyKey, self::INVITES_PER_DAY);
    }

    /** Un invito o un reinvio in più nel secchio: un'ora per il tetto orario, un giorno per quello giornaliero. */
    public function hitLimit(User $actor, Venue $venue): void
    {
        [$key, $dailyKey] = self::limitKeys($actor, $venue);

        RateLimiter::hit($key, 3600);
        RateLimiter::hit($dailyKey, 86400);
    }

    /**
     * Le chiavi del tetto, le stesse da quando stavano nella pagina: un
     * referente che invita dal sito e poi dall'app consuma lo stesso secchio.
     *
     * @return array{0: string, 1: string}
     */
    public static function limitKeys(User $actor, Venue $venue): array
    {
        $key = 'venue-invite:'.$actor->getKey().':'.$venue->getKey();

        return [$key, $key.':daily'];
    }

    /** Gli inviti dei referenti li gestisce la redazione, come i referenti. */
    public function isPendingEditorInvite(Venue $venue, User $member): bool
    {
        $pivot = $this->pivot($venue, $member);

        return $pivot !== null
            && $pivot->getAttribute('accepted_at') === null
            && $pivot->getAttribute('role') === VenueRole::Editor->value;
    }

    /** Se la riga dell'elenco (letta da `members()`) è di chi ha già accettato. */
    public static function accepted(User $row): bool
    {
        return $row->getAttribute('venue_accepted_at') !== null;
    }

    /** `m****@esempio.it`: abbastanza per riconoscere l'indirizzo scritto, non per leggerne uno nuovo. */
    public static function masked(string $email): string
    {
        [$locale, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return Str::mask($locale, '*', 1).'@'.$dominio;
    }

    /** La riga di `venue_user` fra la persona e il locale, invito compreso. */
    private function pivot(Venue $venue, User $member): ?Pivot
    {
        $pivot = $venue->membersIncludingInvited()->whereKey($member->getKey())->first()?->getRelationValue('pivot');

        return $pivot instanceof Pivot ? $pivot : null;
    }
}
