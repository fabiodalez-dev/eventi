<?php

declare(strict_types=1);

namespace App\Console\Commands\Showcase;

use App\Enums\CommunityStatus;
use App\Enums\RideAccessibility;
use App\Enums\RideLeg;
use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\CarpoolCase;
use App\Models\CarpoolProfile;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideReview;
use App\Models\RideSearch;
use App\Models\User;
use App\Queries\CarpoolQuery;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\CarpoolService;
use App\Services\Carpool\CarpoolTerms;
use App\Services\Carpool\RideReviews;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Musonza\Chat\Models\Conversation;
use Musonza\Chat\Models\Message;

/**
 * I passaggi della vetrina (`demo:showcase`): offerte verso gli eventi della
 * settimana, richieste in stati diversi, qualche messaggio in chat e le
 * recensioni di viaggi già conclusi.
 *
 * Le righe si scrivono direttamente, non con `CarpoolService`: il servizio
 * avvisa conducenti e passeggeri a ogni passo. Si scrivono però con gli stessi
 * valori e gli stessi vincoli del servizio — chiave attiva dell'offerta,
 * occupazione unica per persona, data e tratta, posti mai oltre la capienza,
 * istantanea della data identica a `CarpoolQuery::snapshot()`, orario ammesso
 * da `CarpoolQuery::allowed()` — così `carpool:maintain` le riconosce come
 * valide e non le annulla.
 *
 * Il silenzio ha tre livelli. Qui non nasce nessun avviso né riga di consegna.
 * I profili passaggi delle persone demo hanno le notifiche push spente, e le
 * loro preferenze consegnano solo in app: se la manutenzione produce un
 * promemoria per loro, `CommunityDelivery` lo chiude senza inviarlo. E le
 * persone non hanno dispositivi né iscrizioni push.
 */
final class ShowcaseRides
{
    /** Minuti fra la partenza e l'inizio dell'evento, per i viaggi già conclusi. */
    private const PAST_LEAD_MINUTES = 45;

    /** @var array<string, array{0: int, 1: int|string}> */
    private array $report = [];

    public function __construct(private CarpoolQuery $clock) {}

    /**
     * Le date del catalogo investitori concluse da più delle ore di chat, entro
     * la conservazione dello storico. Dalla più vecchia: una seconda
     * esecuzione, giorni dopo, ritrova le stesse date e ne aggiunge in coda.
     *
     * @param  Builder<EventOccurrence>  $investor  tutte le date del catalogo investitori
     * @return Collection<int, EventOccurrence>
     */
    public function pastDates(Builder $investor, int $limit): Collection
    {
        $now = CarbonImmutable::now();

        return $investor->where('starts_at', '<', $now->subHours(config()->integer('carpool.chat_hours') + 1)->addMinutes(self::PAST_LEAD_MINUTES)->utc())
            ->where('starts_at', '>', $now->subDays(config()->integer('carpool.history_retention_days') - 1)->utc())
            ->orderBy('starts_at')->orderBy('id')->limit($limit)->get()->values();
    }

    /**
     * Controlla la coerenza del catalogo prima di scrivere: persone, eventi,
     * stati e posti devono esistere e tornare.
     *
     * @param  array{people: list<mixed>, events: list<mixed>, rides: list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: int, 6: array<string, mixed>}>, ride_requests: list<array{0: int, 1: int, 2: int, 3: string, 4: string|null}>, ride_messages: list<array{0: int, 1: string, 2: string}>, ride_reviews: list<array{0: int, 1: int, 2: string, 3: int, 4: string}>}  $catalog
     */
    public static function validate(array $catalog): void
    {
        $max = config()->integer('carpool.max_seats');
        $keys = [];
        foreach ($catalog['rides'] as $index => [$driver, $event, $leg, $zone, $time, $capacity, $details]) {
            $key = $driver.':'.$event.':'.$leg;
            if (! isset($catalog['people'][$driver], $catalog['events'][$event]) || RideLeg::tryFrom($leg) === null || isset($keys[$key])
                || $capacity < 1 || $capacity > $max || ! preg_match('/^\d{2}:\d{2}$/', $time) || $zone === ''
                || RideAccessibility::tryFrom((string) ($details['accessibility'] ?? 'not_specified')) === null) {
                throw new \RuntimeException('Passaggio non valido nel catalogo: '.$index);
            }
            $keys[$key] = true;
        }
        $accepted = [];
        $pairs = [];
        foreach ($catalog['ride_requests'] as $index => [$ride, $person, $seats, $status]) {
            $offer = $catalog['rides'][$ride] ?? null;
            if ($offer === null || ! isset($catalog['people'][$person]) || $person === $offer[0] || $seats < 1
                || ! in_array($status, ['accepted', 'pending', 'declined', 'withdrawn'], true) || isset($pairs[$ride.':'.$person])) {
                throw new \RuntimeException('Richiesta di passaggio non valida nel catalogo: '.$index);
            }
            $pairs[$ride.':'.$person] = true;
            if ($status === 'accepted') {
                $accepted[$ride] = ($accepted[$ride] ?? 0) + $seats;
                if ($accepted[$ride] >= $offer[5]) {
                    throw new \RuntimeException('Il passaggio '.$ride.' resterebbe senza posti liberi e sparirebbe dalla ricerca.');
                }
            }
        }
        foreach ($catalog['ride_messages'] as $index => [$request, $author]) {
            if (($catalog['ride_requests'][$request][3] ?? null) !== 'accepted' || ! in_array($author, ['driver', 'passenger'], true)) {
                throw new \RuntimeException('Messaggio non valido nel catalogo: '.$index);
            }
        }
        foreach ($catalog['ride_reviews'] as $index => [$driver, $passenger, , $rating]) {
            if (! isset($catalog['people'][$driver], $catalog['people'][$passenger]) || $driver === $passenger || $rating < 1 || $rating > 5) {
                throw new \RuntimeException('Recensione al conducente non valida nel catalogo: '.$index);
            }
        }
    }

    /**
     * @param  array{rides: list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: int, 6: array<string, mixed>}>, ride_requests: list<array{0: int, 1: int, 2: int, 3: string, 4: string|null}>, ride_messages: list<array{0: int, 1: string, 2: string}>, ride_reviews: list<array{0: int, 1: int, 2: string, 3: int, 4: string}>}  $catalog
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     * @param  Collection<int, EventOccurrence>  $pastDates
     * @return array<string, array{0: int, 1: int|string}>
     */
    public function seed(City $city, array $catalog, array $people, array $events, Collection $pastDates): array
    {
        $this->report = [];
        $drivers = array_values(array_unique([...array_column($catalog['rides'], 0), ...array_column($catalog['ride_reviews'], 0)]));
        $this->enable($people, $drivers);
        $offers = $this->seedOffers($city, $catalog['rides'], $people, $events);
        $requests = $this->seedRequests($catalog['ride_requests'], $people, $offers);
        $this->seedMessages($catalog['ride_messages'], $requests);
        $this->seedReviews($catalog['ride_reviews'], $people, $pastDates);

        return $this->report;
    }

    /**
     * Maggiore età dichiarata, regole in vigore accettate, notifiche push dei
     * passaggi spente; per chi guida anche la dichiarazione del conducente.
     * Sono i requisiti di `CarpoolAccess::eligible()` oltre a email e WhatsApp,
     * che le persone demo hanno già.
     *
     * @param  array<int, User>  $people
     * @param  list<int>  $drivers
     */
    private function enable(array $people, array $drivers): void
    {
        $version = (string) config('carpool.terms_version');
        $hash = app(CarpoolTerms::class)->hash();
        $created = 0;
        foreach ($people as $index => $user) {
            $profile = CarpoolProfile::query()->firstOrNew(['user_id' => $user->id]);
            $created += (int) ! $profile->exists;
            $at = CarbonImmutable::now()->subDays(10 - $index % 7);
            if ($profile->terms_version !== $version || $profile->adult_declared_at === null) {
                $profile->forceFill(['adult_declared_at' => $profile->adult_declared_at ?? $at, 'adult_version' => '18-plus-v1',
                    'terms_version' => $version, 'terms_hash' => $hash, 'terms_accepted_at' => $at]);
            }
            if (in_array($index, $drivers, true) && $profile->driver_declared_at === null) {
                $profile->driver_declared_at = $at;
            }
            $profile->push_enabled = false;
            $profile->save();
            $user->setRelation('carpoolProfile', $profile);
        }
        $this->report['Profili passaggi (maggiorenni, regole accettate)'] = [$created, CarpoolProfile::query()->whereIn('user_id', $this->ids($people))->count()];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: int, 6: array<string, mixed>}>  $rows
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     * @return array<int, RideOffer>
     */
    private function seedOffers(City $city, array $rows, array $people, array $events): array
    {
        $offers = [];
        $created = 0;
        $skipped = 0;
        foreach ($rows as $index => [$driverIndex, $eventIndex, $legValue, $zone, $time, $capacity, $details]) {
            $driver = $people[$driverIndex] ?? null;
            $date = $events[$eventIndex] ?? null;
            if ($driver === null || $date === null) {
                $skipped++;

                continue;
            }
            $leg = RideLeg::from($legValue);
            $offer = RideOffer::query()->where('driver_id', $driver->id)->where('occurrence_id', $date->id)->where('leg', $leg->value)->first();
            if ($offer === null) {
                $departure = $this->departure($city, $date, $leg, $time);
                // Stessa regola del servizio: data visibile, partenza futura e nella finestra della tratta, conducente libero.
                if (! $this->clock->allowed($date, $leg, $departure) || $this->occupied($driver, $date, $leg)) {
                    $skipped++;

                    continue;
                }
                $at = CarbonImmutable::now()->subHours(40 + $index * 5);
                $offer = DB::transaction(function () use ($driver, $date, $leg, $zone, $departure, $capacity, $details, $at): RideOffer {
                    $offer = new RideOffer;
                    $offer->forceFill(['driver_id' => $driver->id, 'occurrence_id' => $date->id, 'leg' => $leg, 'status' => RideStatus::Open,
                        'active_key' => $driver->id.':'.$date->id.':'.$leg->value, 'zone' => $zone, 'departure_at' => $departure, 'capacity' => $capacity,
                        'accessibility' => RideAccessibility::from((string) ($details['accessibility'] ?? 'not_specified')),
                        'accessibility_note' => $details['accessibility_note'] ?? null,
                        'note' => trim(($details['note'] ?? '').' Passaggio dimostrativo: il conducente è un profilo di prova.'),
                        'stops' => $details['stops'] ?? [], 'snapshot' => $this->clock->snapshot($date), 'revision' => 1,
                        'created_at' => $at, 'updated_at' => $at])->save();
                    $this->occupy($driver, $offer, null);

                    return $offer;
                });
                $created++;
            }
            $offers[$index] = $offer;
        }
        $total = RideOffer::query()->whereIn('driver_id', $this->ids($people))->where('status', RideStatus::Open)->count();
        $this->report['Passaggi offerti'] = [$created, $total];
        if ($skipped > 0) {
            $this->report['Passaggi saltati (data passata o conducente già in viaggio)'] = [$skipped, $skipped];
        }

        return $offers;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: string, 4: string|null}>  $rows
     * @param  array<int, User>  $people
     * @param  array<int, RideOffer>  $offers
     * @return array<int, RideRequest>
     */
    private function seedRequests(array $rows, array $people, array $offers): array
    {
        $requests = [];
        $created = 0;
        $skipped = 0;
        foreach ($rows as $index => [$offerIndex, $personIndex, $seats, $statusValue, $note]) {
            $offer = $offers[$offerIndex] ?? null;
            $user = $people[$personIndex] ?? null;
            if ($offer === null || $user === null) {
                continue;
            }
            $request = RideRequest::query()->where('ride_offer_id', $offer->id)->where('user_id', $user->id)->first();
            if ($request === null) {
                $status = RideRequestStatus::from($statusValue);
                $active = in_array($status, [RideRequestStatus::Pending, RideRequestStatus::Accepted], true);
                // Le stesse condizioni di `CarpoolService::request()` e `decide()`: offerta aperta e futura,
                // nessun blocco fra i due, persona libera su quella data e tratta, posti sufficienti.
                $blocked = $offer->driver === null || app(CarpoolAccess::class)->blocked($user, $offer->driver);
                if ($offer->status !== RideStatus::Open || ! $this->clock->operational($offer) || ($active && ($blocked || $this->occupied($user, $offer->occurrence, $offer->leg)))
                    || ($status === RideRequestStatus::Accepted && $seats > app(CarpoolService::class)->available($offer))) {
                    $skipped++;

                    continue;
                }
                $at = CarbonImmutable::now()->subHours(30 - $index % 24);
                $request = DB::transaction(function () use ($offer, $user, $seats, $status, $active, $note, $at): RideRequest {
                    $request = new RideRequest;
                    $closed = $active ? null : $at->addHours(2);
                    $request->forceFill(['ride_offer_id' => $offer->id, 'user_id' => $user->id, 'seats' => $seats, 'companions_adult' => $seats > 1,
                        'note' => $note, 'offer_revision' => $offer->revision, 'status' => $status, 'active_key' => $active ? $user->id.':'.$offer->id : null,
                        'accepted_at' => $status === RideRequestStatus::Accepted ? $at->addHour() : null, 'closed_at' => $closed,
                        'close_reason' => match ($status) {
                            RideRequestStatus::Declined => 'driver_declined', RideRequestStatus::Withdrawn => 'passenger_withdrew', default => null,
                        },
                        'created_at' => $at, 'updated_at' => $closed ?? $at])->save();
                    if ($status === RideRequestStatus::Accepted) {
                        $this->occupy($user, $offer, $request);
                        $this->openChat($request, $offer->driver ?? throw new \RuntimeException('Conducente assente.'), $user);
                    }

                    return $request;
                });
                $created++;
            }
            $requests[$index] = $request;
        }
        $ids = $this->ids($people);
        $all = RideRequest::query()->whereIn('user_id', $ids)->whereIn('ride_offer_id', RideOffer::query()->whereIn('driver_id', $ids)->select('id'));
        $this->report['Richieste di passaggio'] = [$created, (clone $all)->count()];
        $open = RideOffer::query()->whereIn('driver_id', $ids)->where('status', RideStatus::Open);
        $taken = (int) (clone $all)->where('status', RideRequestStatus::Accepted)->whereIn('ride_offer_id', (clone $open)->select('id'))->sum('seats');
        $this->report['Posti accettati sui passaggi aperti'] = [0, $taken.' su '.(int) $open->sum('capacity')];
        if ($skipped > 0) {
            $this->report['Richieste saltate (offerta chiusa, posti o persona occupata)'] = [$skipped, $skipped];
        }

        return $requests;
    }

    /**
     * La chat che `CarpoolService::decide()` apre all'accettazione, senza
     * l'evento di ingresso dei partecipanti né l'avviso al passeggero.
     */
    private function openChat(RideRequest $request, User $driver, User $passenger): void
    {
        $conversation = Conversation::query()->create(['direct_message' => false, 'data' => ['purpose' => 'event_ride']]);
        $driver->joinConversation($conversation);
        $passenger->joinConversation($conversation);
        $chat = RideConversation::query()->create(['ride_request_id' => $request->id, 'conversation_id' => $conversation->id]);
        foreach ([$driver, $passenger] as $participant) {
            DB::table('ride_chat_preferences')->insert(['ride_conversation_id' => $chat->id, 'user_id' => $participant->id]);
        }
    }

    /**
     * Messaggi cifrati come quelli di `RideChat::send()`, ma senza
     * `Message::send()`: niente evento di invio, niente avviso al destinatario.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $rows
     * @param  array<int, RideRequest>  $requests
     */
    private function seedMessages(array $rows, array $requests): void
    {
        $created = 0;
        $chats = [];
        foreach ($rows as $index => [$requestIndex, $author, $body]) {
            $request = $requests[$requestIndex] ?? null;
            $chat = $request?->conversation;
            if ($request === null || $chat === null || $request->status !== RideRequestStatus::Accepted) {
                continue;
            }
            // Una chat che ha già messaggi è stata popolata da un'esecuzione precedente: non si ripete.
            $chats[$chat->id] ??= ! DB::table('chat_messages')->where('conversation_id', $chat->conversation_id)->exists();
            if (! $chats[$chat->id]) {
                continue;
            }
            $sender = $author === 'driver' ? $request->offer->driver_id : $request->user_id;
            $participation = DB::table('chat_participation')->where('conversation_id', $chat->conversation_id)
                ->where('messageable_type', (new User)->getMorphClass())->where('messageable_id', $sender)->value('id');
            if ($participation === null) {
                continue;
            }
            $at = CarbonImmutable::instance($request->accepted_at ?? now())->addMinutes(15 + $index * 11);
            (new Message)->forceFill(['body' => $body, 'conversation_id' => $chat->conversation_id, 'participation_id' => $participation,
                'type' => 'text', 'created_at' => $at, 'updated_at' => $at])->save();
            $created++;
        }
        $conversations = RideConversation::query()->whereIn('ride_request_id', array_map(fn (RideRequest $r): int => $r->id, $requests))->pluck('conversation_id');
        $this->report['Messaggi nelle chat dei passaggi'] = [$created, DB::table('chat_messages')->whereIn('conversation_id', $conversations)->count()];
    }

    /**
     * Viaggi conclusi con recensione, su date passate del catalogo
     * investitori: l'offerta è «completata» come la lascia la manutenzione, la
     * richiesta è accettata prima della partenza e confermata dal passeggero
     * dopo, e la recensione si scrive solo se `RideReviews::eligibleTrip()` la
     * ammette.
     *
     * @param  list<array{0: int, 1: int, 2: string, 3: int, 4: string}>  $rows
     * @param  array<int, User>  $people
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedReviews(array $rows, array $people, Collection $dates): void
    {
        $created = 0;
        $skipped = 0;
        foreach ($rows as $index => [$driverIndex, $passengerIndex, $zone, $rating, $body]) {
            $driver = $people[$driverIndex] ?? null;
            $passenger = $people[$passengerIndex] ?? null;
            $date = $dates[$index] ?? null;
            if ($driver === null || $passenger === null || $date === null) {
                $skipped++;

                continue;
            }
            if (RideReview::withTrashed()->where('user_id', $passenger->id)->where('driver_id', $driver->id)->exists()) {
                continue;
            }
            if (app(CarpoolAccess::class)->blocked($passenger, $driver) || $this->occupied($driver, $date, RideLeg::Outbound)
                || RideOffer::query()->where('driver_id', $driver->id)->where('occurrence_id', $date->id)->where('leg', RideLeg::Outbound->value)->exists()) {
                $skipped++;

                continue;
            }
            $departure = CarbonImmutable::instance($date->starts_at)->subMinutes(self::PAST_LEAD_MINUTES);
            $closed = $departure->addHours(config()->integer('carpool.chat_hours'));
            DB::beginTransaction();
            try {
                $offer = new RideOffer;
                $offer->forceFill(['driver_id' => $driver->id, 'occurrence_id' => $date->id, 'leg' => RideLeg::Outbound, 'status' => RideStatus::Completed,
                    'active_key' => null, 'zone' => $zone, 'departure_at' => $departure, 'capacity' => 3, 'accessibility' => RideAccessibility::NotSpecified,
                    'note' => 'Passaggio dimostrativo: il conducente è un profilo di prova.', 'stops' => [], 'snapshot' => $this->clock->snapshot($date),
                    'revision' => 1, 'closed_at' => $closed, 'created_at' => $departure->subDays(2), 'updated_at' => $closed])->save();
                $request = new RideRequest;
                $request->forceFill(['ride_offer_id' => $offer->id, 'user_id' => $passenger->id, 'seats' => 1, 'companions_adult' => false,
                    'offer_revision' => 1, 'status' => RideRequestStatus::Accepted, 'active_key' => $passenger->id.':'.$offer->id,
                    'accepted_at' => $departure->subDay(), 'passenger_confirmed_at' => $departure->addHours(3),
                    'created_at' => $departure->subDay()->subHours(2), 'updated_at' => $departure->addHours(3)])->save();
                $request->setRelation('offer', $offer);
                if (! app(RideReviews::class)->eligibleTrip($passenger, $request)) {
                    DB::rollBack();
                    $skipped++;

                    continue;
                }
                $at = $departure->addHours(20);
                (new RideReview)->forceFill(['ride_request_id' => $request->id, 'user_id' => $passenger->id, 'driver_id' => $driver->id,
                    'rating' => $rating, 'body' => $body, 'status' => CommunityStatus::Published, 'revision' => 1,
                    'created_at' => $at, 'updated_at' => $at])->save();
                DB::commit();
                $created++;
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }
        $total = RideReview::query()->whereIn('user_id', $this->ids($people))->count();
        $this->report['Recensioni ai conducenti (viaggi conclusi)'] = [$created, $total === 0 && $dates->isEmpty() ? 'nessuna data conclusa' : $total];
        if ($skipped > 0) {
            $this->report['Recensioni saltate (mancano date concluse)'] = [$skipped, $skipped];
        }
    }

    /**
     * Le righe di **altri** utenti che `purge()` porta via: passaggi offerti
     * e ricerche sulle date della vetrina, richieste di posto, le chat di quei
     * viaggi con i messaggi scritti da loro, i feedback privati, le
     * segnalazioni e i messaggi scambiati con l'assistenza su quei casi.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $eventIds
     * @return array<string, int>
     */
    public function others(array $userIds, array $eventIds): array
    {
        $dates = $this->dates($eventIds);
        $offers = $this->offers($userIds, $dates);
        $requests = $this->requests($userIds, $offers);
        $conversations = RideConversation::query()->whereIn('ride_request_id', $requests)->pluck('conversation_id')->all();
        $cases = $this->cases($userIds, $offers, $requests)->pluck('id')->all();
        $morph = (new User)->getMorphClass();
        // Le partecipazioni delle persone vere a quelle chat: dicono quali conversazioni e quali messaggi sono loro.
        $participations = DB::table('chat_participation')->whereIn('conversation_id', $conversations)
            ->where('messageable_type', $morph)->whereNotIn('messageable_id', $userIds);

        return [
            'passaggi offerti' => RideOffer::query()->whereIn('id', $offers)->whereNotIn('driver_id', $userIds)->count(),
            'richieste di passaggio' => RideRequest::query()->whereIn('id', $requests)->whereNotIn('user_id', $userIds)->count(),
            'ricerche di passaggio' => RideSearch::query()->whereIn('occurrence_id', $dates)->whereNotIn('user_id', $userIds)->count(),
            'chat dei passaggi' => (clone $participations)->distinct()->count('conversation_id'),
            'messaggi in chat' => DB::table('chat_messages')->whereIn('participation_id', (clone $participations)->select('id'))->count(),
            'feedback sui passaggi' => DB::table('ride_feedback')->whereIn('ride_request_id', $requests)->whereNotIn('user_id', $userIds)->count(),
            'segnalazioni sui passaggi' => $this->cases($userIds, $offers, $requests)->whereNotIn('reporter_id', $userIds)->count(),
            'messaggi delle segnalazioni' => DB::table('carpool_case_messages')->where(fn ($q) => $q->whereIn('carpool_case_id', $cases)->orWhereIn('recipient_id', $userIds))
                ->whereNotIn('author_id', $userIds)->count(),
        ];
    }

    /**
     * Ciò che di vivo hanno le persone vere sulle date della vetrina: passaggi
     * che devono ancora partire e posti già accettati su viaggi futuri. Un
     * `--purge` li cancellerebbe in silenzio, e chi li aspetta resterebbe a
     * piedi: il comando si ferma e li elenca, a meno di `--force-real`.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $eventIds
     * @return list<string>
     */
    public function liveRowsOfOthers(array $userIds, array $eventIds): array
    {
        $offers = $this->offers($userIds, $this->dates($eventIds));
        $now = CarbonImmutable::now();
        $live = [RideStatus::Draft, RideStatus::Open, RideStatus::Closed];
        $lines = [];
        foreach (RideOffer::query()->whereIn('id', $offers)->whereNotIn('driver_id', $userIds)->whereIn('status', $live)
            ->where('departure_at', '>', $now)->with(['driver', 'occurrence.event.city'])->orderBy('departure_at')->orderBy('id')->get() as $offer) {
            $lines[] = sprintf('passaggio #%d di %s (utente #%d), %s, partenza %s, %s', $offer->id, $offer->driver->name ?? '?', $offer->driver_id,
                $offer->leg->label(), $this->local($offer), $offer->status->label());
        }
        foreach (RideRequest::query()->whereIn('ride_offer_id', $offers)->whereNotIn('user_id', $userIds)->where('status', RideRequestStatus::Accepted)
            ->whereHas('offer', fn ($q) => $q->where('departure_at', '>', $now))->with(['user', 'offer.occurrence.event.city'])->orderBy('id')->get() as $request) {
            $lines[] = sprintf('richiesta accettata #%d di %s (utente #%d), %d posti sul passaggio #%d, partenza %s', $request->id, $request->user->name ?? '?',
                $request->user_id, $request->seats, $request->ride_offer_id, $this->local($request->offer));
        }

        return $lines;
    }

    /**
     * Toglie i passaggi delle persone demo e quelli sulle date della vetrina
     * con tutto ciò che ne dipende. Le chiavi esterne dei passaggi non vanno in
     * cascata (proteggono lo storico): senza questo passaggio né le persone né
     * gli eventi si potrebbero cancellare.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $eventIds
     */
    public function purge(array $userIds, array $eventIds): void
    {
        $dates = $this->dates($eventIds);
        $offers = $this->offers($userIds, $dates);
        $requests = $this->requests($userIds, $offers);
        $cases = $this->cases($userIds, $offers, $requests)->pluck('id');
        $chats = RideConversation::query()->whereIn('ride_request_id', $requests)->get();

        DB::table('carpool_case_messages')->where(fn ($q) => $q->whereIn('carpool_case_id', $cases)->orWhereIn('author_id', $userIds)->orWhereIn('recipient_id', $userIds))->delete();
        DB::table('carpool_cases')->whereIn('id', $cases)->delete();
        // Partecipazioni, messaggi e loro avvisi seguono la conversazione in cascata.
        DB::table('chat_conversations')->whereIn('id', $chats->pluck('conversation_id'))->delete();
        DB::table('ride_conversations')->whereIn('id', $chats->pluck('id'))->delete();
        DB::table('ride_chat_preferences')->whereIn('user_id', $userIds)->delete();
        DB::table('ride_reviews')->where(fn ($q) => $q->whereIn('ride_request_id', $requests)->orWhereIn('user_id', $userIds)->orWhereIn('driver_id', $userIds))->delete();
        DB::table('ride_feedback')->where(fn ($q) => $q->whereIn('ride_request_id', $requests)->orWhereIn('user_id', $userIds))->delete();
        DB::table('ride_occupancies')->where(fn ($q) => $q->whereIn('ride_offer_id', $offers)->orWhereIn('user_id', $userIds))->delete();
        DB::table('ride_requests')->whereIn('id', $requests)->delete();
        DB::table('ride_searches')->where(fn ($q) => $q->whereIn('occurrence_id', $dates)->orWhereIn('user_id', $userIds))->delete();
        DB::table('ride_offers')->whereIn('id', $offers)->delete();
        DB::table('carpool_commands')->whereIn('user_id', $userIds)->delete();
        DB::table('carpool_audits')->whereIn('actor_id', $userIds)->delete();
        DB::table('community_delivery_outbox')->whereIn('user_id', $userIds)->delete();
        DB::table('community_notification_receipts')->whereIn('user_id', $userIds)->delete();
        DB::table('carpool_profiles')->whereIn('user_id', $userIds)->delete();
    }

    /**
     * @param  list<int>  $eventIds
     * @return list<int>
     */
    private function dates(array $eventIds): array
    {
        return EventOccurrence::withTrashed()->whereIn('event_id', $eventIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $dates
     * @return list<int>
     */
    private function offers(array $userIds, array $dates): array
    {
        return RideOffer::query()->where(fn ($q) => $q->whereIn('driver_id', $userIds)->orWhereIn('occurrence_id', $dates))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $offers
     * @return list<int>
     */
    private function requests(array $userIds, array $offers): array
    {
        return RideRequest::query()->where(fn ($q) => $q->whereIn('ride_offer_id', $offers)->orWhereIn('user_id', $userIds))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $offers
     * @param  list<int>  $requests
     * @return Builder<CarpoolCase>
     */
    private function cases(array $userIds, array $offers, array $requests): Builder
    {
        return CarpoolCase::query()->where(fn ($q) => $q->whereIn('ride_offer_id', $offers)->orWhereIn('ride_request_id', $requests)->orWhereIn('reporter_id', $userIds));
    }

    /**
     * L'ora locale del catalogo nel giorno dell'evento. Un ritorno a un'ora
     * precedente all'inizio vale per il giorno dopo.
     */
    private function departure(City $city, EventOccurrence $date, RideLeg $leg, string $time): CarbonImmutable
    {
        $start = CarbonImmutable::instance($date->starts_at)->timezone($city->timezone);
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $departure = $start->setTime($hour, $minute);
        if ($leg === RideLeg::Return && $departure->lessThan($start)) {
            $departure = $departure->addDay();
        }

        return $departure->utc();
    }

    /** La partenza all'ora della città dell'evento, come la vede chi guida. */
    private function local(RideOffer $offer): string
    {
        return $offer->departure_at->setTimezone($offer->occurrence->event->city->timezone ?? config('app.timezone'))->format('d/m/Y H:i');
    }

    private function occupied(User $user, EventOccurrence $date, RideLeg $leg): bool
    {
        return DB::table('ride_occupancies')->where('user_id', $user->id)->where('occurrence_id', $date->id)->where('leg', $leg->value)->exists();
    }

    private function occupy(User $user, RideOffer $offer, ?RideRequest $request): void
    {
        DB::table('ride_occupancies')->insert(['user_id' => $user->id, 'occurrence_id' => $offer->occurrence_id,
            'leg' => $offer->leg->value, 'ride_offer_id' => $offer->id, 'ride_request_id' => $request?->id]);
    }

    /**
     * @param  array<int, User>  $people
     * @return list<int>
     */
    private function ids(array $people): array
    {
        return array_values(array_map(fn (User $user): int => $user->id, $people));
    }
}
