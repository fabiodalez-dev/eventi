<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * L'assegnazione dei ruoli di piattaforma, **senza poter salire più in alto
 * di dove si è** (era il salvataggio di `RoleField`, R-US-06). La chiamano il
 * campo «Ruoli» del pannello e l'app della redazione.
 *
 * Le regole sono quelle che `RoleField` spiega per esteso:
 *
 * - solo un super amministratore conferisce `super_admin`; gli altri
 *   arrivano fino ad `admin`;
 * - l'insieme richiesto si interseca con quello conferibile: un valore
 *   forgiato non passa;
 * - i ruoli che chi modifica non può conferire ma che il bersaglio ha già
 *   restano dove sono: la regola toglie la possibilità di salire, non quella
 *   di amministrare;
 * - ogni cambio vero finisce nel registro di sicurezza (`ruoli_cambiati`, con
 *   il prima e il dopo); un salvataggio che non cambia niente no.
 *
 * Dall'app vale in più la decisione 4 della redazione: si conferisce al
 * massimo `moderator` (`inApp: true`), e `admin` e `super_admin` restano
 * come sono, dati e tolti solo dal sito. E dall'app `venue_owner` e
 * `venue_editor` non si danno a chi è (o diventa) staff: i loro permessi si
 * sommano a quelli del moderatore, e la combinazione è proprio quella che
 * apriva tutti i locali (revisione di sicurezza dell'ondata 1, punto 1).
 * Chi li ha già li tiene: la regola toglie la possibilità di conferirli.
 */
final class SyncUserRoles
{
    /** I ruoli che solo il sito conferisce e toglie (decisione 4). */
    public const WEB_ONLY = [UserRole::Admin, UserRole::SuperAdmin];

    /** I ruoli dello staff globale. */
    public const STAFF = [UserRole::Moderator, UserRole::Admin, UserRole::SuperAdmin];

    /** I ruoli di chi gestisce un locale, che dall'app non si sommano a quelli dello staff. */
    public const VENUE = [UserRole::VenueOwner, UserRole::VenueEditor];

    /**
     * I nomi dei ruoli che `$actor` può conferire.
     *
     * @return list<string>
     */
    public static function assignable(?User $actor, bool $inApp = false): array
    {
        $super = $actor instanceof User && $actor->hasRole(UserRole::SuperAdmin->value);
        $names = [];

        foreach (UserRole::cases() as $role) {
            if (! $super && $role === UserRole::SuperAdmin) {
                continue;
            }
            if ($inApp && in_array($role, self::WEB_ONLY, true)) {
                continue;
            }
            $names[] = $role->value;
        }

        return $names;
    }

    /**
     * Allinea i ruoli di `$target` a quelli richiesti, dentro quello che
     * `$actor` può conferire. Restituisce i nomi dei ruoli dopo il cambio.
     *
     * @param  list<int>  $roleIds  gli id dei ruoli richiesti (il campo del pannello lavora con gli id)
     * @return list<string>
     */
    public function syncIds(?User $actor, User $target, array $roleIds, bool $inApp = false): array
    {
        $assignable = Role::query()->whereIn('name', self::assignable($actor, $inApp))->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        /* Ciò che il bersaglio ha già e chi modifica non potrebbe dargli:
           non è suo da togliere. */
        $untouchable = $target->roles()->whereNotIn('roles.id', $assignable)->pluck('roles.id')->map(static fn (mixed $id): int => (int) $id)->all();

        $before = $this->names($target);
        $next = array_values(array_unique([...array_intersect($roleIds, $assignable), ...$untouchable]));

        if ($inApp) {
            $this->refuseVenueRolesForStaff($before, $next);
        }

        $target->roles()->sync($next);
        $target->unsetRelation('roles');

        $after = $this->names($target);

        if ($before !== $after) {
            SecurityLog::scrivi('ruoli_cambiati', $target, [
                'prima' => $before,
                'dopo' => $after,
            ], $actor);
        }

        return $after;
    }

    /**
     * Come `syncIds()`, con i nomi dei ruoli (`moderator`, `user`…): la forma
     * dell'API. Un nome che non esiste non diventa un ruolo.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function syncNames(?User $actor, User $target, array $names, bool $inApp = false): array
    {
        $ids = Role::query()->whereIn('name', $names)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return $this->syncIds($actor, $target, $ids, $inApp);
    }

    /**
     * Dall'app: 422 su `roles` se l'insieme nuovo dà `venue_owner` o
     * `venue_editor` a chi resta o diventa staff, e la persona non li aveva
     * già.
     *
     * @param  list<string>  $before  i nomi dei ruoli prima del cambio
     * @param  list<int>  $next  gli id dei ruoli dopo il cambio
     *
     * @throws ValidationException
     */
    private function refuseVenueRolesForStaff(array $before, array $next): void
    {
        $names = Role::query()->whereKey($next)->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();
        $staff = array_map(static fn (UserRole $role): string => $role->value, self::STAFF);
        $venue = array_map(static fn (UserRole $role): string => $role->value, self::VENUE);
        $added = array_diff(array_intersect($names, $venue), $before);

        if ($added !== [] && array_intersect($names, $staff) !== []) {
            throw ValidationException::withMessages(['roles' => __('users.app.venue_roles_for_staff')]);
        }
    }

    /** @return list<string> */
    private function names(User $target): array
    {
        return $target->roles()->pluck('roles.name')->map(static fn (mixed $name): string => (string) $name)->sort()->values()->all();
    }
}
