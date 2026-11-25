<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Models\User;
use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Il tuo accesso» (W-CO-06): lo apre ogni persona che gestisce il locale,
 * referente o collaboratore, come la pagina del pannello
 * (`VenueAccess::canAccess`). La porta del soggetto ha già chiesto
 * l'appartenenza accettata a un locale senza provvedimento; qui la si
 * richiede di nuovo, perché è il permesso della pagina.
 */
final class VenueAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isActiveMemberOfVenue((int) $this->venue()->getKey());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }

    public function member(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
