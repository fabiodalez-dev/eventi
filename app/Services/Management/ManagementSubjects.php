<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Enums\VenueStatus;
use App\Http\Resources\V1\PosterResource;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Che cosa gestisce una persona: i locali e le organizzazioni in cui può
 * entrare, e le date in cui fa solo il controllo ingressi.
 *
 * Gli insiemi sono quelli di `User::getTenants` (lo switcher dei pannelli):
 * locali con appartenenza accettata e senza provvedimento, organizzazioni
 * attive di cui è proprietaria o membro. Se i due elenchi divergessero, l'app
 * offrirebbe un soggetto che poi la porta (`ResolveManagementSubject`)
 * rifiuta con un 404.
 */
final class ManagementSubjects
{
    public function __construct(private readonly ManagementPermissions $permissions) {}

    /** @return BelongsToMany<Venue, User> */
    public function venues(User $user): BelongsToMany
    {
        return $user->venues()->whereIn('venues.status', VenueStatus::valoriSenzaProvvedimento());
    }

    /** @return Builder<Organizer> */
    public function organizers(User $user): Builder
    {
        return $user->managedOrganizers();
    }

    /**
     * Le date non ancora finite in cui la persona è nel personale d'ingresso:
     * la stessa soglia dell'elenco `GET /management/dates`.
     *
     * @return Builder<EventOccurrence>
     */
    public function checkinDates(User $user): Builder
    {
        return EventOccurrence::query()->whereHas('checkinStaff', fn (Builder $staff) => $staff->whereKey($user->getKey()))
            ->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) >= ?', [now()]);
    }

    /** @return array{venues: int, organizers: int, checkin: int} */
    public function counts(User $user): array
    {
        return [
            'venues' => $this->venues($user)->count(),
            'organizers' => $this->organizers($user)->count(),
            'checkin' => $this->checkinDates($user)->count(),
        ];
    }

    /**
     * La risposta di `GET /management/subjects`.
     *
     * @return array{venues: list<array<string, mixed>>, organizers: list<array<string, mixed>>, checkin_only: bool}
     */
    public function payload(User $user): array
    {
        $venues = $this->venues($user)->with(['city', 'media'])->orderBy('name')->get()
            ->map(fn (Venue $venue): array => $this->venue($user, $venue))->values()->all();
        $organizers = $this->organizers($user)->with('city')->orderBy('name')->get()
            ->map(fn (Organizer $organizer): array => $this->organizer($user, $organizer))->values()->all();

        return [
            'venues' => $venues,
            'organizers' => $organizers,
            // Solo personale d'ingresso: niente da gestire, ma date da controllare.
            'checkin_only' => $venues === [] && $organizers === [] && $this->checkinDates($user)->exists(),
        ];
    }

    /** @return array<string, mixed> */
    private function venue(User $user, Venue $venue): array
    {
        $logo = $venue->getFirstMedia('logo');

        return [
            'id' => (int) $venue->getKey(),
            'slug' => (string) $venue->slug,
            'name' => (string) $venue->name,
            'city' => $venue->city === null ? null : ['name' => (string) $venue->city->name, 'timezone' => (string) $venue->city->timezone],
            'role' => $user->isOwnerOfVenue((int) $venue->getKey()) ? 'owner' : 'editor',
            'status' => $venue->status->value,
            'logo' => $logo instanceof Media ? PosterResource::media($logo) : null,
            'ticketing_enabled' => (bool) $venue->ticketing_enabled,
            'auto_publish' => (bool) $venue->auto_publish,
            'permissions' => $this->permissions->for($user, $venue),
        ];
    }

    /** @return array<string, mixed> */
    private function organizer(User $user, Organizer $organizer): array
    {
        return [
            'id' => (int) $organizer->getKey(),
            'slug' => (string) $organizer->slug,
            'name' => (string) $organizer->name,
            'city' => $organizer->city === null ? null : ['name' => (string) $organizer->city->name, 'timezone' => (string) $organizer->city->timezone],
            'role' => (int) $organizer->owner_id === (int) $user->getKey() ? 'owner' : 'member',
            'permissions' => $this->permissions->for($user, $organizer),
        ];
    }
}
