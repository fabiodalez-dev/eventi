<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\RegistrationState;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\City;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * L'anagrafica degli account come la legge la redazione (R-US-01, R-US-02):
 * le otto schede, i dieci filtri, la ricerca e l'ordinamento dell'elenco
 * «Utenti» del pannello. Le schede le usa anche `ListUsers`; i filtri sono
 * gli stessi di `UserResource::table()`, scritti come query.
 */
final class UserDirectory
{
    /** Le schede, nell'ordine del pannello. `all` è «Tutti». */
    public const TABS = ['all', 'pending', 'admins', 'staff', 'owners', 'editors', 'users', 'unlinked'];

    /** I filtri, nell'ordine del pannello. */
    public const FILTERS = [
        'registration', 'city', 'venue_city', 'venue', 'missing_city', 'role',
        'email_verified', 'whatsapp_verified', 'suspended', 'trashed',
    ];

    /** Le colonne ordinabili del pannello (`-campo` per il verso discendente). */
    public const SORTS = ['name', 'created_at', 'city', 'whatsapp_verified_at', 'community_suspended_at', 'last_active_at'];

    /** Le relazioni che la riga mostra e che le abilità leggono: una query sola per elenco. */
    public const ROW_RELATIONS = ['roles', 'venues.city', 'city', 'communityProfile'];

    /**
     * La query di una scheda (`ListUsers::getTabs()`).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'pending' => RegistrationState::scopeIncomplete($query),
            'admins' => $query->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', [UserRole::Admin->value, UserRole::SuperAdmin->value])),
            'staff' => $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', UserRole::Moderator->value)),
            'owners' => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->where('venue_user.role', VenueRole::Owner->value)),
            'editors' => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->where('venue_user.role', VenueRole::Editor->value)),
            'users' => $query->whereDoesntHave('venues')->whereDoesntHave('roles', fn (Builder $roles): Builder => $roles->where('name', '!=', UserRole::User->value)),
            'unlinked' => $query->whereDoesntHave('venues')->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', [UserRole::VenueOwner->value, UserRole::VenueEditor->value])),
            default => $query,
        };
    }

    /**
     * Un filtro del pannello. I sì/no accettano `1`/`0` (come `TernaryFilter`);
     * `trashed` accetta `with` (anche i cestinati) e `only` (solo i
     * cestinati): senza, come nel pannello, i cestinati non ci sono.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function filter(Builder $query, string $key, string $value): Builder
    {
        $yes = in_array($value, ['1', 'true'], true);

        return match ($key) {
            'registration' => ($state = RegistrationState::tryFrom($value)) instanceof RegistrationState ? $state->scope($query) : $query,
            'city' => $query->where('city_id', (int) $value),
            'venue_city' => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->where('city_id', (int) $value)),
            'venue' => $query->whereHas('venues', fn (Builder $venues): Builder => $venues->whereKey((int) $value)),
            'missing_city' => $yes ? $query->whereNull('city_id') : $query->whereNotNull('city_id'),
            'role' => $query->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', $value)),
            'email_verified' => $yes ? $query->whereNotNull('email_verified_at') : $query->whereNull('email_verified_at'),
            'whatsapp_verified' => $yes ? $query->whereNotNull('whatsapp_verified_at') : $query->whereNull('whatsapp_verified_at'),
            'suspended' => $yes ? $query->whereNotNull('community_suspended_at') : $query->whereNull('community_suspended_at'),
            default => $query,
        };
    }

    /**
     * La ricerca delle colonne cercabili del pannello: nome ed email, nome
     * dei locali di cui si è membri, nome utente della community.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function search(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes($term, '\\%_').'%';

        return $query->where(fn (Builder $where): Builder => $where
            ->where('name', 'like', $like)
            ->orWhere('email', 'like', $like)
            ->orWhereHas('venues', fn (Builder $venues): Builder => $venues->where('name', 'like', $like))
            ->orWhereHas('communityProfile', fn (Builder $profile): Builder => $profile->where('handle', 'like', $like)));
    }

    /**
     * Una pagina dell'elenco con scheda, filtri, ricerca e ordinamento; le
     * iscrizioni più recenti in cima, come il pannello.
     *
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function page(?string $tab, array $filters, ?string $search, ?string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $query = User::query()->with(self::ROW_RELATIONS);
        $query = match ($filters['trashed'] ?? null) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => $query,
        };
        $query = self::tab($query, $tab ?? 'all');
        foreach ($filters as $key => $value) {
            if ($key !== 'trashed' && $value !== '') {
                $query = self::filter($query, $key, $value);
            }
        }
        if ($search !== null && $search !== '') {
            $query = self::search($query, $search);
        }
        $this->sort($query, $sort);

        return $query->paginate($perPage, page: $page);
    }

    /**
     * I conteggi delle schede, senza filtri né ricerca: sul pannello il
     * numero della scheda «In attesa» è quello di tutte le iscrizioni da
     * completare; l'app lo dà per ogni scheda.
     *
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = self::tab(User::query(), $tab)->count();
        }

        return $counts;
    }

    /** @param  Builder<User>  $query */
    private function sort(Builder $query, ?string $sort): void
    {
        $sort = (string) $sort;
        $descending = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');
        if (! in_array($field, self::SORTS, true)) {
            $query->orderByDesc('created_at')->orderByDesc('id');

            return;
        }
        $direction = $descending ? 'desc' : 'asc';
        if ($field === 'city') {
            $query->orderBy(
                City::query()->select('name')->whereColumn('cities.id', 'users.city_id')->limit(1),
                $direction,
            );
        } else {
            $query->orderBy($field, $direction);
        }
        $query->orderBy('id', $direction);
    }
}
