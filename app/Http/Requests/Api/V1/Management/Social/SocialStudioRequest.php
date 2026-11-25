<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Social;

use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Services\Management\ManagedContent;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Una richiesta allo studio delle grafiche social (P10). Lo studio è solo
 * del locale (le rotte esistono solo sotto `venues/{venue}`) e lo apre ogni
 * membro, referente o collaboratore, come la pagina «Social» del pannello.
 *
 * Con `event` lo studio si restringe a un evento del locale (W-SO-08,
 * l'arrivo dalla scheda dell'evento): l'evento si cerca solo fra quelli del
 * locale (404 altrimenti) e serve `update`, come nella pagina.
 */
abstract class SocialStudioRequest extends FormRequest
{
    private ?Event $studioEvent = null;

    public function authorize(): bool
    {
        $subject = ManagementSubject::current();

        return $subject !== null && $subject->isVenue() && $subject->user->canAccessTenant($subject->model);
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_if($venue === null, 404);

        return $venue;
    }

    public function city(): City
    {
        return $this->venue()->city;
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** L'evento a cui è ristretto lo studio, se c'è. */
    public function studioEvent(): ?Event
    {
        $id = $this->integer('event');
        if ($id <= 0) {
            return null;
        }
        if ($this->studioEvent === null) {
            $event = ManagedContent::current()->events()->with(['city', 'organizer'])->whereKey($id)->firstOrFail();
            Gate::forUser($this->actor())->authorize('update', $event);
            $this->studioEvent = $event;
        }

        return $this->studioEvent;
    }

    public function studioEventId(): ?int
    {
        $event = $this->studioEvent();

        return $event === null ? null : (int) $event->getKey();
    }
}
