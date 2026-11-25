<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Il nuovo evento dell'organizzazione: nasce sempre in bozza, con la prima
 * data facoltativa (`EventPolicy::create`, cioè chi gestisce
 * l'organizzazione).
 */
final class OrganizerEventStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Event::class, $this->organizer()]) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return EventWriteRules::organizerStore();
    }

    public function organizer(): Organizer
    {
        $organizer = ManagementSubject::require()->organizer();
        abort_unless($organizer instanceof Organizer, 404);

        return $organizer;
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
