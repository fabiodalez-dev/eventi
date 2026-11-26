<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Actions\ModerateVenueAction;
use App\Enums\EventStatus;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Http\Resources\V1\PosterResource;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EditorialDashboardQuery;
use App\Queries\VenueDashboardQuery;
use App\Support\ActivityHistory;
use App\Support\Api\ApiDate;
use App\Support\Venues\VenueCover;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * I locali visti dalla redazione (§9.2): l'elenco, la scheda in sola lettura,
 * gli eventi collegati, la cronologia e il foglio da esportare, per l'app
 * (`E/venues`). Le stesse query, gli stessi filtri e le stesse schede
 * dell'elenco del pannello (`VenueResource`, `ListVenues`), che vede tutte le
 * città: la città di lavoro qui non filtra, come lì.
 */
final class EditorialVenues
{
    public const TABS = ['all', 'pending', 'approved', 'suspended'];

    /** Le colonne che il pannello ordina: nome (predefinito), comune, numero di eventi. */
    public const SORTS = ['name', 'municipality', 'events_count'];

    /** I filtri dell'elenco del pannello, con lo stesso significato. */
    public const FILTERS = ['city', 'type', 'status', 'is_verified', 'auto_publish', 'inactive'];

    /** I prossimi eventi nella scheda, come `filament.admin.venues.overview`. */
    public const UPCOMING = 8;

    /**
     * L'elenco: ricerca su nome e comune, una scheda per stato, i filtri,
     * l'ordinamento.
     *
     * @param  array<string, string>  $filters
     * @return Builder<Venue>
     */
    public function query(string $tab, array $filters, string $search): Builder
    {
        $query = Venue::query()->with(['media', 'city'])->withCount('events');

        match ($tab) {
            'pending' => $query->where('status', VenueStatus::Pending),
            'approved' => $query->where('status', VenueStatus::Approved),
            'suspended' => $query->where('status', VenueStatus::Suspended),
            default => null,
        };

        if (is_numeric($filters['city'] ?? null)) {
            $query->where('city_id', (int) $filters['city']);
        }
        if (($type = VenueType::tryFrom($filters['type'] ?? '')) !== null) {
            $query->where('type', $type);
        }
        if (($status = VenueStatus::tryFrom($filters['status'] ?? '')) !== null) {
            $query->where('status', $status);
        }
        foreach (['is_verified', 'auto_publish'] as $flag) {
            if (in_array($filters[$flag] ?? null, ['0', '1'], true)) {
                $query->where($flag, $filters[$flag] === '1');
            }
        }
        if (($filters['inactive'] ?? null) === '1') {
            EditorialDashboardQuery::inactiveVenueScope($query);
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $where): Builder => $where->where('name', 'like', $like)->orWhere('municipality', 'like', $like));
        }

        return $query;
    }

    /**
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, Venue>
     */
    public function paginate(string $tab, array $filters, string $search, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->sorted($this->query($tab, $filters, $search), $sort)->paginate($perPage, page: $page);
    }

    /**
     * @param  Builder<Venue>  $query
     * @return Builder<Venue>
     */
    public function sorted(Builder $query, string $sort): Builder
    {
        $column = ltrim($sort, '-');

        return in_array($column, self::SORTS, true)
            ? $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id')
            : $query->orderBy('name')->orderBy('id');
    }

    /**
     * I conteggi delle schede con i filtri e la ricerca attivi, come i numeri
     * che Filament mette accanto a ogni scheda.
     *
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    public function tabCounts(array $filters, string $search): array
    {
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = $this->query($tab, $filters, $search)->toBase()->getCountForPagination();
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function row(Venue $venue, User $user): array
    {
        return [
            'id' => (int) $venue->getKey(),
            'name' => (string) $venue->name,
            'slug' => (string) $venue->slug,
            'municipality' => $venue->municipality,
            'subtitle' => collect([$venue->municipality, $venue->type->label()])->filter()->join(' · '),
            'type' => ['value' => $venue->type->value, 'label' => $venue->type->label()],
            'status' => ['value' => $venue->status->value, 'label' => $venue->status->label()],
            'is_verified' => (bool) $venue->is_verified,
            'auto_publish' => (bool) $venue->auto_publish,
            'city' => $venue->city === null ? null : ['id' => (int) $venue->city->getKey(), 'name' => (string) $venue->city->name],
            'events_count' => (int) ($venue->getAttribute('events_count') ?? 0),
            'cover_url' => VenueCover::url($venue),
            'abilities' => $this->abilities($venue, $user),
        ];
    }

    /**
     * Le azioni della riga e della scheda: le stesse domande che il pannello
     * fa alla `VenuePolicy`, con le stesse condizioni di stato
     * (`ModerateVenueAction::can*`).
     *
     * @return array<string, bool>
     */
    public function abilities(Venue $venue, User $user): array
    {
        $gate = Gate::forUser($user);
        $moderate = $gate->allows('moderate', $venue);
        $update = $gate->allows('update', $venue);

        return [
            'view' => $gate->allows('view', $venue),
            'update' => $update,
            'approve' => $moderate && ModerateVenueAction::canApprove($venue),
            'reject' => $moderate && ModerateVenueAction::canReject($venue),
            'suspend' => $moderate && ModerateVenueAction::canSuspend($venue),
            'verify' => $moderate,
            // Il campo «Abilita il ticketing» si scrive solo da admin e super admin (`VenueResource`, scheda Ticketing).
            'ticketing' => $update && $user->hasAnyRole(['admin', 'super_admin']),
            'members' => $gate->allows('manageCollaborators', $venue),
            'destroy' => $gate->allows('delete', $venue),
        ];
    }

    /**
     * La scheda in sola lettura (`ViewVenue`): testata, informazioni,
     * descrizione, prossimi eventi, persone.
     *
     * @return array<string, mixed>
     */
    public function overview(Venue $venue, User $user): array
    {
        $venue->loadMissing(['city', 'media'])->loadCount('events');
        $timezone = $venue->city->timezone ?? config()->string('app.timezone');

        $eventsQuery = Event::query()->where('venue_id', $venue->getKey())->with(['media', 'category']);
        VenueDashboardQuery::applyNextOccurrence($eventsQuery, $venue->city);
        $upcoming = $eventsQuery->havingNotNull('next_starts_at')->orderBy('next_starts_at')->limit(self::UPCOMING)->get();
        $members = $venue->membersIncludingInvited()->orderBy('name')->get();

        return [
            ...$this->row($venue, $user),
            'address' => $venue->address,
            'address_extra' => $venue->address_extra,
            'postal_code' => $venue->postal_code,
            'phone' => $venue->phone,
            'email' => $venue->email,
            'website' => $venue->website,
            'capacity' => $venue->capacity,
            'plan' => ['value' => $venue->plan->value, 'label' => $venue->plan->label()],
            'rejection_reason' => $venue->rejection_reason,
            'description' => $venue->description,
            'ticketing_enabled' => (bool) $venue->ticketing_enabled,
            'site_url' => $venue->status === VenueStatus::Approved ? route('venues.show', $venue->slug) : null,
            'upcoming' => $upcoming->map(fn (Event $event): array => [
                'id' => (int) $event->getKey(),
                'title' => (string) $event->title,
                'starts_at' => ApiDate::instant(CarbonImmutable::parse((string) $event->getAttribute('next_starts_at'), 'UTC'), $timezone),
                'category' => $event->category?->name,
                'status' => ['value' => $event->status->value, 'label' => $event->status->label()],
                'poster' => PosterResource::toArray($event),
            ])->values()->all(),
            'members' => $members->map(fn (User $member): array => [
                'name' => (string) $member->name,
                'role' => VenueRole::tryFrom((string) $member->getRelationValue('pivot')?->getAttribute('role'))?->label(),
                'pending' => $member->getRelationValue('pivot')?->getAttribute('accepted_at') === null,
            ])->values()->all(),
        ];
    }

    /**
     * Gli eventi del locale (`EventsRelationManager`): ricerca sul titolo,
     * filtro di stato, i più recenti prima.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    public function events(Venue $venue, ?EventStatus $status, string $search, int $perPage, int $page): LengthAwarePaginator
    {
        return Event::query()
            ->where('venue_id', $venue->getKey())
            ->with(['media', 'category'])
            ->withCount('occurrences')
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status))
            ->when(trim($search) !== '', fn (Builder $query): Builder => $query->where('title', 'like', '%'.addcslashes(trim($search), '%_\\').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function eventRow(Event $event, User $user, string $timezone): array
    {
        return [
            'id' => (int) $event->getKey(),
            'title' => (string) $event->title,
            'category' => $event->category?->name,
            'status' => ['value' => $event->status->value, 'label' => $event->status->label()],
            'occurrences_count' => (int) ($event->getAttribute('occurrences_count') ?? 0),
            'published_at' => ApiDate::instant($event->published_at, $timezone),
            'poster' => PosterResource::toArray($event),
            'abilities' => ['view' => Gate::forUser($user)->allows('view', $event), 'update' => Gate::forUser($user)->allows('update', $event)],
        ];
    }

    /**
     * La cronologia delle modifiche (`ActivityRelationManager`), dalla più
     * recente.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function activity(Venue $venue, int $perPage, int $page): LengthAwarePaginator
    {
        return $venue->activitiesAsSubject()->with('causer')->latest('created_at')->latest('id')->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function activityRow(Activity $activity, Venue $venue, bool $canUpdate, string $timezone): array
    {
        $causer = $activity->causer;

        return [
            'id' => (int) $activity->getKey(),
            'created_at' => ApiDate::instant($activity->created_at, $timezone),
            'event' => ['value' => (string) $activity->event, 'label' => ActivityHistory::eventLabel($activity->event)],
            'causer' => $causer instanceof User ? (string) $causer->name : ActivityHistory::causerPlaceholder($activity),
            'changes' => ActivityHistory::describe($activity),
            'restorable_fields' => array_keys(ActivityHistory::previous($activity)),
            'abilities' => ['restore' => $canUpdate && ActivityHistory::restorable($activity, $venue)],
        ];
    }

    /**
     * Le righe del foglio da esportare: le colonne dell'elenco, con i filtri e
     * la ricerca di chi esporta (come l'esportazione del pannello, che esporta
     * quello che si sta guardando).
     *
     * @param  array<string, string>  $filters
     * @return Collection<int, array{string, string, string, int, string}>
     */
    public function exportRows(string $tab, array $filters, string $search, string $sort): Collection
    {
        return $this->sorted($this->query($tab, $filters, $search), $sort)->get()
            ->map(fn (Venue $venue): array => [
                (string) $venue->name,
                collect([$venue->municipality, $venue->type->label()])->filter()->join(' · '),
                $venue->status->label(),
                (int) ($venue->getAttribute('events_count') ?? 0),
                (string) ($venue->is_verified ? __('admin.venue_list.verified') : __('admin.venue_list.not_verified')),
            ]);
    }

    /** @return list<string> */
    public static function exportHeadings(): array
    {
        return [
            (string) __('admin.fields.name'),
            (string) __('admin.fields.municipality'),
            (string) __('admin.fields.status'),
            (string) __('admin.fields.events_count'),
            (string) __('admin.fields.is_verified'),
        ];
    }
}
