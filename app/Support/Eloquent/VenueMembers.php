<?php

declare(strict_types=1);

namespace App\Support\Eloquent;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * La relazione `Venue::members()` vista dal locale: come `VenueMemberships`
 * dal lato della persona, una riga scritta senza `accepted_at` nasce
 * accettata (`AttachesAcceptedVenueMembership`).
 *
 * @template TDeclaringModel of Venue
 *
 * @extends BelongsToMany<User, TDeclaringModel, Pivot, 'pivot'>
 */
class VenueMembers extends BelongsToMany
{
    use AttachesAcceptedVenueMembership;
}
