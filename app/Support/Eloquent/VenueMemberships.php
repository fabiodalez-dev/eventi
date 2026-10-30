<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * La relazione `User::venues()` con una cosa in più: ogni scrittura sulla
 * pivot dimentica la copia in memoria che `User::venueMemberships()` tiene per
 * la durata della richiesta. Così chi toglie un collaboratore e poi, nella
 * stessa richiesta, chiede se può ancora agire riceve la risposta vera, non
 * quella di un attimo prima.
 *
 * Come `VenueMembers` dall'altro lato, una riga scritta senza `accepted_at`
 * nasce accettata (`AttachesAcceptedVenueMembership`).
 *
 * @template TDeclaringModel of User
 *
 * @extends BelongsToMany<Venue, TDeclaringModel, Pivot, 'pivot'>
 */
class VenueMemberships extends BelongsToMany
{
    use AttachesAcceptedVenueMembership;

    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     * @param  bool  $touch
     */
    public function attach($id, array $attributes = [], $touch = true): void
    {
        parent::attach($id, $attributes, $touch);
        $this->forget();
    }

    /**
     * @param  mixed  $ids
     * @param  bool  $touch
     */
    public function detach($ids = null, $touch = true): int
    {
        $result = parent::detach($ids, $touch);
        $this->forget();

        return $result;
    }

    /**
     * @param  Collection<int|string, mixed>|Model|array<int|string, mixed>  $ids
     * @param  bool  $detaching
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}
     */
    public function sync($ids, $detaching = true): array
    {
        $result = parent::sync($ids, $detaching);
        $this->forget();

        return $result;
    }

    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     * @param  bool  $touch
     */
    public function updateExistingPivot($id, array $attributes, $touch = true): int
    {
        $result = parent::updateExistingPivot($id, $attributes, $touch);
        $this->forget();

        return $result;
    }

    private function forget(): void
    {
        $this->getParent()->forgetVenueMemberships();
    }
}
