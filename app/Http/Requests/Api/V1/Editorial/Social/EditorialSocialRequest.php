<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Social;

use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\Editorial\EditorialPermissions;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Una richiesta al Social della redazione (R9). Lo apre chi apre la pagina
 * «Social» del pannello: admin e super admin (`Social::canAccess`, chiave
 * `social_use` del banco). Il moderatore è dentro la redazione ma riceve 403.
 *
 * La città è quella di lavoro (`?city=`, come il selettore «Città» della
 * pagina, che elenca anche le città non attive). Con `event` lo studio si
 * restringe a un evento, come la pagina aperta dalla scheda dell'evento
 * (`?event=`): serve `update` sull'evento, e la città diventa la sua.
 */
class EditorialSocialRequest extends FormRequest
{
    private ?Event $studioEvent = null;

    private bool $eventLoaded = false;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(EditorialPermissions::class)->allows($user, 'social_use');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** La città dello studio: quella dell'evento, se c'è, altrimenti quella di lavoro (404 senza nessuna città). */
    public function city(): City
    {
        $event = $this->studioEvent();
        if ($event !== null) {
            return $event->city;
        }
        $city = EditorialDesk::require()->city;
        abort_if($city === null, 404);

        return $city;
    }

    /** L'evento a cui è ristretto lo studio, se c'è (404 se non esiste, 403 senza `update`). */
    public function studioEvent(): ?Event
    {
        if (! $this->eventLoaded) {
            $this->eventLoaded = true;
            $id = $this->integer('event');
            if ($id > 0) {
                $event = Event::query()->with(['organizer', 'city'])->findOrFail($id);
                Gate::forUser($this->actor())->authorize('update', $event);
                $this->studioEvent = $event;
            }
        }

        return $this->studioEvent;
    }

    public function studioEventId(): ?int
    {
        $event = $this->studioEvent();

        return $event === null ? null : (int) $event->getKey();
    }
}
