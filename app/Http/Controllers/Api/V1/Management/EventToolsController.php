<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\CreateSharedEventTag;
use App\Enums\ApiErrorCode;
use App\Enums\AttendanceMode;
use App\Enums\PriceType;
use App\Enums\ScheduleShortcut;
use App\Enums\TicketTierStatus;
use App\Enums\VenueStatus;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Events\EventChecksRequest;
use App\Http\Requests\Api\V1\Management\Events\EventFormRequest;
use App\Http\Requests\Api\V1\Management\Events\EventUpdateActionRequest;
use App\Http\Requests\Api\V1\Management\Events\FacebookImportRequest;
use App\Http\Requests\Api\V1\Management\Events\LookupRequest;
use App\Http\Requests\Api\V1\Management\Events\TagStoreRequest;
use App\Http\Resources\V1\EventResource;
use App\Models\City;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Services\Events\EventDateChecks;
use App\Services\Events\SameNightNearby;
use App\Services\Events\VenueEventEditor;
use App\Services\Import\FacebookEventImport;
use App\Services\Import\FacebookImportDrafts;
use App\Services\Import\FacebookImportThrottle;
use App\Services\Management\ManagedContent;
use App\Services\Management\ManagedEvents;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use App\Support\BeforeGoingDefaults;
use App\Support\Management\ManagementSubject;
use App\Support\VenueEventDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Quello che serve al modulo dell'evento oltre al salvataggio: gli elenchi e
 * le abitudini del locale, gli avvisi sulla data, l'import da Facebook, i
 * tag, la ricerca dei locali, l'anteprima della pagina.
 */
final class EventToolsController extends Controller
{
    /**
     * Le scelte del modulo: categorie, città (organizzazione), abitudini del
     * locale con l'ora abituale, le scorciatoie della data già calcolate
     * nell'ora della città, «Prima di andare» del locale da precompilare.
     */
    public function form(EventFormRequest $request, ManagedEvents $events): JsonResponse
    {
        $subject = ManagementSubject::require();
        $city = ManagedEvents::city($subject->model);
        $venue = $subject->venue() ?? $this->approvedVenue($request->integer('venue_id') ?: null);
        $data = [
            'categories' => $events->categories(),
            'city' => ['id' => (int) $city->getKey(), 'name' => $city->name, 'timezone' => $city->timezone],
            'cities' => [],
            'defaults' => ['price_type' => PriceType::Free->value],
            'start_time' => null,
            'shortcuts' => [],
            'venue' => $venue === null ? null : ['id' => (int) $venue->getKey(), 'name' => $venue->name, 'address' => $venue->address],
            'before_going' => BeforeGoingDefaults::merge(BeforeGoingDefaults::forVenue($venue), []),
            // Gli elenchi chiusi del modulo che `GET /management/options` non ha, con le etichette del sito.
            'ticket_tier_statuses' => self::choices(TicketTierStatus::options()),
            'weekdays' => self::choices(Weekday::options()),
            'attendance_modes' => self::choices([
                AttendanceMode::Offline->value => __('seo.offline'),
                AttendanceMode::Online->value => __('seo.online'),
                AttendanceMode::Mixed->value => __('seo.mixed'),
            ]),
            'parking_types' => self::choices(['free' => __('seo.parking_free'), 'paid' => __('seo.parking_paid'), 'none' => __('seo.parking_none')]),
            'organizer_types' => self::choices(['Organization' => __('seo.organization'), 'Person' => __('seo.person')]),
            'limits' => ['ticket_tiers' => VenueEventEditor::MAX_TIERS, 'tags' => 50, 'agenda' => 50, 'faqs' => 30],
        ];

        if ($subject->venue() !== null) {
            $own = $subject->venue();
            $data['defaults'] = ['price_type' => PriceType::Free->value, ...VenueEventDefaults::formDefaults($own)];
            [$hour, $minute] = VenueEventDefaults::startTime($own);
            $data['start_time'] = sprintf('%02d:%02d', $hour, $minute);
            $now = CarbonImmutable::now($city->timezone);
            foreach (ScheduleShortcut::cases() as $shortcut) {
                $data['shortcuts'][] = [
                    'value' => $shortcut->value,
                    'label' => $shortcut->label(),
                    'starts_at' => $shortcut->startsAt($now, $hour, $minute)->toIso8601String(),
                    'repeats' => $shortcut->repeats(),
                    'weekday' => $shortcut->weekday()?->value,
                ];
            }
        } else {
            $data['cities'] = City::query()->orderBy('name')->get(['id', 'name', 'timezone'])
                ->map(fn (City $item): array => ['id' => (int) $item->getKey(), 'name' => $item->name, 'timezone' => $item->timezone])->values()->all();
        }

        return response()->json(['data' => $data]);
    }

    /**
     * «Stessa sera in zona» e chiusura straordinaria per l'orario scelto.
     * Il luogo è l'indirizzo a mano se ha le coordinate, altrimenti il locale
     * (il soggetto, o il locale principale scelto dall'organizzazione).
     */
    public function checks(EventChecksRequest $request, EventDateChecks $checks): JsonResponse
    {
        $subject = ManagementSubject::require();
        $own = $subject->venue();
        $venue = $own ?? $this->approvedVenue($request->integer('venue_id') ?: null);
        $city = $own->city ?? City::query()->find($request->integer('city_id') ?: null) ?? ManagedEvents::city($subject->model);
        $location = (array) $request->input('custom_location', []);
        $eventId = $request->integer('event_id') ?: null;
        if ($eventId !== null && ! ManagedContent::current()->events()->whereKey($eventId)->exists()) {
            $eventId = null;
        }

        $context = EventDateChecks::context($city, [$location, $venue], $request->input('starts_at'), $request->input('ends_at'),
            $city->timezone, $own === null ? null : (int) $own->getKey(), $request->input('category_id'), $eventId);
        $start = EventDateChecks::time($request->input('starts_at'), $city->timezone);
        // Un evento con un indirizzo proprio non si tiene nel locale: le sue chiusure non contano.
        $closure = EventDateChecks::closure(filled($location['address'] ?? null) ? null : $venue, $start);

        return response()->json(['data' => [
            'same_night' => $checks->sameNight($context),
            'radius_km' => SameNightNearby::RADIUS_KM,
            'closure' => $closure,
        ]]);
    }

    /**
     * «Importa da Facebook»: legge l'evento pubblico e restituisce i campi
     * del modulo già compilati, più un gettone (`import`) da rimandare con la
     * creazione per la provenienza e la foto di copertina. Gli stessi limiti
     * del pannello: 3 al minuto e 30 al giorno per persona.
     */
    public function importFacebook(FacebookImportRequest $request, FacebookEventImport $import, FacebookImportThrottle $throttle, FacebookImportDrafts $drafts): JsonResponse
    {
        $user = $request->actor();
        $limit = $throttle->attempt($user);
        if ($limit !== null) {
            return ApiResponse::error(ApiErrorCode::RateLimited, __('facebook_import.'.$limit), status: 429);
        }

        $subject = ManagementSubject::require();
        $city = $subject->venue()->city ?? City::query()->find($request->integer('city_id') ?: null) ?? ManagedEvents::city($subject->model);
        try {
            $source = $import->fetch($request->string('url')->toString());
            $fields = $import->formData($source, $city->timezone);
        } catch (\Throwable $error) {
            report($error);

            return ApiResponse::error(ApiErrorCode::ValidationFailed, __('facebook_import.failed'), ['url' => [__('facebook_import.failed_hint')]], 422);
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($fields[$field])) {
                $fields[$field] = CarbonImmutable::parse((string) $fields[$field], $city->timezone)->toIso8601String();
            }
        }
        if ($subject->isOrganizer()) {
            // L'organizzazione non scrive link esterni: li prende la creazione dai dati letti.
            unset($fields['external_links']);
            $fields['city_id'] = (int) $city->getKey();
        }

        return response()->json(['data' => [
            'fields' => $fields,
            'import' => $drafts->put($user, $subject->type().':'.$subject->model->getKey(), $source),
            'photo_url' => is_string($source['cover']['url'] ?? null) ? $source['cover']['url'] : null,
            'source' => [
                'venue_name' => $source['venue']['name'] ?? null,
                'venue_address' => $source['venue']['address'] ?? null,
                'date_label' => $source['date_label'] ?? null,
                'hosts' => self::hostNames($source['hosts'] ?? null),
                'responded_count' => $source['responded_count'] ?? null,
            ],
            'location_address' => FacebookEventImport::locationAddress($source),
        ]]);
    }

    /**
     * «Anteprima della pagina»: la stessa forma di `GET /events/{slug}`
     * anche per un evento non ancora pubblicato, con le sue date future (o
     * le ultime passate).
     */
    public function preview(EventUpdateActionRequest $request, OccurrenceFeed $feed): JsonResponse
    {
        $event = $request->event();
        $event->load(['venue', 'category', 'tags', 'city', 'media', 'ticketTiers']);
        $city = $event->city;
        $occurrences = EventOccurrenceQuery::managementFor($event)->upcoming()->get()->take(config()->integer('api.limits.event_dates'));
        if ($occurrences->isEmpty()) {
            $occurrences = EventOccurrenceQuery::managementFor($event)->past()->orderByNewestFirst()->get()->take(3);
        }
        $occurrences = $occurrences->values();
        $feed->hydrate($occurrences, []);
        $occurrences->loadMissing('ticketTiers');
        $user = $request->user();
        $context = ApiContext::forOccurrences($city, [], $user instanceof User ? $user : null, $feed->ids($occurrences));

        return response()->json(['data' => EventResource::toArray($event, $occurrences, $context)]);
    }

    /** I tag per il campo «Tag»: per nome, o per id (quelli già scelti). */
    public function tags(LookupRequest $request): JsonResponse
    {
        $query = Tag::query()->orderBy('name');
        $ids = array_map('intval', (array) $request->input('ids', []));
        if ($ids !== []) {
            $query->whereKey($ids);
        } else {
            $name = Str::squish(strip_tags($request->string('q')->toString()));
            $query->when($name !== '', fn ($tags) => $tags->where('name', 'like', '%'.addcslashes($name, '%_\\').'%'));
        }

        return response()->json(['data' => $query->limit(50)->get(['id', 'name'])
            ->map(fn (Tag $tag): array => ['id' => (int) $tag->getKey(), 'name' => $tag->name])->values()->all()]);
    }

    /** Un tag nuovo, condiviso con tutti i locali (solo il locale lo crea). */
    public function storeTag(TagStoreRequest $request, CreateSharedEventTag $create): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);
        $eventId = $request->integer('event_id') ?: null;
        $event = $eventId === null ? null : ManagedContent::current()->event($eventId);
        $tag = $create->execute($user, $request->string('name')->toString(), $event, $venue);

        return response()->json(['data' => ['id' => (int) $tag->getKey(), 'name' => $tag->name]], 201);
    }

    /**
     * I locali approvati, per il locale principale dell'organizzazione e per
     * «Organizzatore registrato» delle informazioni editoriali.
     */
    public function venues(LookupRequest $request): JsonResponse
    {
        $query = Venue::query()->where('status', VenueStatus::Approved->value)->with('city')->orderBy('name');
        $ids = array_map('intval', (array) $request->input('ids', []));
        if ($ids !== []) {
            $query->whereKey($ids);
        } else {
            $name = Str::squish(strip_tags($request->string('q')->toString()));
            $query->when($name !== '', fn ($venues) => $venues->where('name', 'like', '%'.addcslashes($name, '%_\\').'%'));
        }

        return response()->json(['data' => $query->limit(30)->get()->map(fn (Venue $venue): array => [
            'id' => (int) $venue->getKey(),
            'name' => $venue->name,
            'address' => $venue->address,
            'city' => $venue->city?->name,
        ])->values()->all()]);
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function choices(array $options): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => $options[$value]], array_map('strval', array_keys($options)));
    }

    /**
     * I nomi di chi organizza su Facebook, come li mostra il pannello.
     *
     * @return list<string>
     */
    private static function hostNames(mixed $hosts): array
    {
        $names = [];
        foreach (is_array($hosts) ? $hosts : [] as $host) {
            if (is_array($host) && is_string($host['name'] ?? null) && $host['name'] !== '') {
                $names[] = $host['name'];
            }
        }

        return $names;
    }

    private function approvedVenue(?int $id): ?Venue
    {
        return $id === null ? null : Venue::query()->whereKey($id)->where('status', VenueStatus::Approved->value)->first();
    }
}
