<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Services\Management\ManagedContent;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Il nuovo evento del locale: «Salva come bozza» (basta il titolo) o
 * «Pubblica» (servono data e categoria). Con `draft_id` si riprende la bozza
 * già salvata dallo stesso modulo: salvare due volte non crea due eventi.
 */
final class VenueEventStoreRequest extends FormRequest
{
    private ?Event $draft = null;

    private bool $draftLoaded = false;

    public function authorize(): bool
    {
        $draft = $this->draft();

        return $draft !== null
            ? $this->user()?->can('update', $draft) === true
            : $this->user()?->can('create', [Event::class, $this->venue()]) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return EventWriteRules::venueStore($this->publishes());
    }

    public function publishes(): bool
    {
        return $this->boolean('publish');
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }

    /** La bozza da riprendere: solo una bozza di questo locale, mai un altro evento. */
    public function draft(): ?Event
    {
        if (! $this->draftLoaded) {
            $this->draftLoaded = true;
            $id = $this->input('draft_id');
            if (is_numeric($id)) {
                $this->draft = ManagedContent::current()->events()->whereKey((int) $id)->where('status', EventStatus::Draft)->firstOrFail();
            }
        }

        return $this->draft;
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
