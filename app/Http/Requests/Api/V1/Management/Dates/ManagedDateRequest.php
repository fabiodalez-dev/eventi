<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Management\ManagedContent;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una richiesta sulle date di un evento del soggetto. L'evento si cerca solo
 * fra quelli del locale o dell'organizzazione e la data solo fra quelle
 * dell'evento (404 altrimenti, mai un `findOrFail` libero); poi decide la
 * policy, come nelle sezioni delle date dei pannelli.
 */
abstract class ManagedDateRequest extends FormRequest
{
    private ?Event $event = null;

    private ?EventOccurrence $date = null;

    /** La domanda alla policy, con l'evento o la data già trovati. */
    abstract protected function allowed(User $user): bool;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $this->allowed($user);
    }

    public function event(): Event
    {
        return $this->event ??= ManagedContent::current()->event((int) $this->route('event'))->loadMissing(['city', 'venue', 'organizer']);
    }

    /** La data della rotta, che deve essere di questo evento. */
    public function occurrence(): EventOccurrence
    {
        if ($this->date === null) {
            $event = $this->event();
            $this->date = EventOccurrence::query()->where('event_id', $event->getKey())->whereKey((int) $this->route('occurrence'))->firstOrFail();
            $this->date->setRelation('event', $event);
        }

        return $this->date;
    }

    public function subject(): ManagementSubject
    {
        return ManagementSubject::require();
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
