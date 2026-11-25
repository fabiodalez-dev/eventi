<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use App\Models\Event;
use App\Models\User;
use App\Services\Management\ManagedContent;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una richiesta su un evento del soggetto. L'evento si cerca solo fra quelli
 * del locale o dell'organizzazione (404 altrimenti, mai un `findOrFail`
 * libero); poi decide la policy, con l'abilità di `ability()`.
 */
abstract class ManagedEventRequest extends FormRequest
{
    private ?Event $event = null;

    /** L'abilità di `EventPolicy` che serve: `view`, `update`, `delete`. */
    abstract protected function ability(): string;

    public function authorize(): bool
    {
        return $this->user()?->can($this->ability(), $this->event()) === true;
    }

    public function event(): Event
    {
        return $this->event ??= ManagedContent::current()->event((int) $this->route('event'));
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
