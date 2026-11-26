<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\CommunityStatus;
use App\Enums\Permission;
use App\Enums\RideStatus;
use App\Models\CarpoolAudit;
use App\Models\CarpoolCase;
use App\Models\CarpoolCaseMessage;
use App\Models\RideFeedback;
use App\Models\RideOffer;
use App\Models\RideReview;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use App\Services\Community\CommunityModeration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Le letture della pagina «Passaggi in auto» del pannello (`Carpool`,
 * R-CP-01, R-CP-09, R-CP-10): le sette sezioni con ricerca e stato, il caso
 * aperto con la conversazione, le persone a cui si può rispondere. In un
 * posto solo per il pannello e per l'app della redazione.
 *
 * Le scritture restano di `CommunitySafety` e `RideReviews`, che ricontrollano
 * permesso, rango e revisione a ogni chiamata e scrivono nel registro dei
 * passaggi. Qui ci sono le query di `getViewData()` del pannello, così
 * com'erano (la ricerca vale solo dove il pannello la applica: non sui casi e
 * non sulle consegne), il permesso che ogni sezione chiede e la forma delle
 * righe per l'API con le `abilities`.
 *
 * Il pannello vede tutte le città in questa pagina, e così l'app.
 */
final class CarpoolDesk
{
    /** Le schede della pagina, nell'ordine del pannello. */
    public const SECTIONS = ['cases', 'rides', 'reviews', 'feedback', 'users', 'audit', 'deliveries'];

    /** Le sezioni che oltre ai casi chiedono `carpool.manage`, come il pannello. */
    public const CARPOOL_SECTIONS = ['rides', 'users', 'deliveries', 'reviews', 'feedback'];

    /** Le sezioni dove il pannello applica la ricerca. */
    public const SEARCHABLE = ['rides', 'reviews', 'feedback', 'users', 'audit'];

    /** Le sezioni con il filtro di stato: i casi (`CarpoolCaseStatus`) e i passaggi (`RideStatus`). */
    public const WITH_STATUS = ['cases', 'rides'];

    /** Un passaggio si annulla dalla redazione solo in bozza, aperto o chiuso alle richieste. */
    public const CANCELLABLE = [RideStatus::Draft, RideStatus::Open, RideStatus::Closed];

    /** Una consegna si riprova solo dopo otto tentativi falliti (`CommunitySafety::retryDelivery`). */
    public const RETRY_AFTER_ATTEMPTS = 8;

    public const SEARCH_MAX = 80;

    /** I tre ambiti di una sospensione, con la colonna che li segna. */
    public const RESTRICTION_SCOPES = ['social' => 'social_suspended_at', 'carpool' => 'carpool_suspended_at', 'global' => 'community_suspended_at'];

    /** Le relazioni del caso aperto: chi l'ha segnalato, chi lo segue, le persone del passaggio, la conversazione. */
    private const CASE_RELATIONS = ['reporter', 'assignee', 'offer.driver', 'rideRequest.user', 'messages.author', 'messages.recipient'];

    /** Il permesso che una sezione chiede oltre all'ingresso della pagina (`community_cases.manage`). */
    public static function permission(string $section): Permission
    {
        return in_array($section, self::CARPOOL_SECTIONS, true) ? Permission::ManageCarpool : Permission::ManageCommunityCases;
    }

    /**
     * La query di una sezione, dalla riga più recente, con la ricerca e lo
     * stato come li applica il pannello.
     *
     * @return Builder<CarpoolCase>|Builder<RideOffer>|Builder<RideReview>|Builder<RideFeedback>|Builder<User>|Builder<CarpoolAudit>|QueryBuilder
     */
    public function query(string $section, string $search = '', string $status = ''): Builder|QueryBuilder
    {
        $needle = '%'.addcslashes(mb_substr($search, 0, self::SEARCH_MAX), '%_\\').'%';
        $query = match ($section) {
            'reviews' => RideReview::with(['user', 'driver', 'rideRequest'])->when($search !== '', fn ($q) => $q->where('body', 'like', $needle)),
            'feedback' => RideFeedback::with(['user', 'rideRequest.offer'])->where('kind', 'like', $needle),
            'rides' => RideOffer::with(['driver.communityProfile', 'occurrence.event'])->withCount('requests')->where(fn ($q) => $q->where('zone', 'like', $needle)->orWhereHas('driver', fn ($q) => $q->where('name', 'like', $needle))),
            'users' => User::with('communityProfile')->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('email', 'like', $needle)),
            'audit' => CarpoolAudit::with('actor')->where('action', 'like', $needle),
            'deliveries' => DB::table('community_delivery_outbox')->select(['id', 'user_id', 'notification_id', 'attempts', 'available_at', 'delivered_at', 'last_error'])->whereNull('delivered_at')->where('attempts', '>=', 1),
            default => CarpoolCase::with(['reporter', 'assignee'])->when($status !== '', fn ($q) => $q->where('status', $status)),
        };
        if ($section === 'rides' && in_array($status, array_column(RideStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('id');
    }

    /** Il caso aperto, con la conversazione e le persone coinvolte. */
    public function case(int $id): CarpoolCase
    {
        return CarpoolCase::with(self::CASE_RELATIONS)->findOrFail($id);
    }

    /**
     * Le persone a cui si può rispondere: chi ha segnalato, il conducente, chi
     * ha chiesto il posto. Le stesse che `CommunitySafety::manage('reply')`
     * accetta, con l'etichetta del pannello («Nome #id»).
     *
     * @return array<int, string>
     */
    public function people(?CarpoolCase $case): array
    {
        return collect([$case?->reporter, $case?->offer?->driver, $case?->rideRequest?->user])
            ->filter()->unique('id')
            ->mapWithKeys(fn (User $person): array => [$person->id => $person->name.' #'.$person->id])
            ->all();
    }

    // -------------------------------------------------------- righe dell'API

    /** @return array<string, mixed> */
    public function row(string $section, mixed $item, User $staff): array
    {
        return match (true) {
            $item instanceof CarpoolCase => $this->caseRow($item),
            $item instanceof RideOffer => $this->rideRow($item, $staff),
            $item instanceof RideReview => $this->reviewRow($item, $staff),
            $item instanceof RideFeedback => $this->feedbackRow($item),
            $item instanceof User => $this->userRow($item, $staff),
            $item instanceof CarpoolAudit => $this->auditRow($item),
            $section === 'deliveries' && $item instanceof stdClass => $this->deliveryRow($item, $staff),
            default => [],
        };
    }

    /**
     * Un caso nell'elenco: numero, chi l'ha segnalato, stato, arrivo, chi lo
     * segue, motivo. Si apre con «Apri caso».
     *
     * @return array<string, mixed>
     */
    public function caseRow(CarpoolCase $case): array
    {
        return [
            'kind' => 'case',
            'id' => $case->id,
            'reporter' => $case->reporter?->name,
            'status' => $case->status->value,
            'status_label' => $case->status->label(),
            'created_at' => $case->created_at?->toIso8601String(),
            'assignee' => $case->assignee?->name,
            'reason' => (string) $case->reason,
            'abilities' => ['show' => true],
        ];
    }

    /**
     * Il caso aperto, come la sezione in cima alla pagina del pannello: il
     * testo, la recensione segnalata, i riferimenti, la conversazione con le
     * note interne, le persone a cui rispondere e le azioni. La conservazione
     * (scadenza e «preserva/rilascia») la vede solo chi la può gestire; le
     * evidenze solo admin e super admin.
     *
     * @return array<string, mixed>
     */
    public function caseDetail(CarpoolCase $case, User $staff): array
    {
        $safety = app(CommunitySafety::class);
        $retention = $safety->staff($staff, Permission::ManageCommunityRetention, true);
        $sensitive = $safety->staff($staff, Permission::ReadCommunityMessages, true);
        $closed = $case->closed_at !== null;
        $chat = $case->ride_request_id !== null;
        $snapshot = is_array($case->review_snapshot) ? $case->review_snapshot : null;

        return [
            ...$this->caseRow($case),
            'body' => filled($case->body) ? (string) $case->body : null,
            'review' => $snapshot !== null ? ['rating' => $snapshot['rating'] ?? null, 'body' => $snapshot['body'] ?? null] : null,
            'social' => $case->social_type ? ['type' => (string) $case->social_type, 'id' => (int) $case->social_id] : null,
            'ride_offer_id' => $case->ride_offer_id,
            'ride_request_id' => $case->ride_request_id,
            'closed_at' => $case->closed_at?->toIso8601String(),
            'revision' => (int) $case->revision,
            'hold_until' => $retention ? $case->hold_until?->toIso8601String() : null,
            'messages' => $case->messages->map(fn (CarpoolCaseMessage $message): array => [
                'id' => $message->id,
                'author' => $message->author?->name,
                'recipient' => $message->recipient?->name,
                'internal' => (bool) $message->internal,
                'body' => (string) $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
            'people' => collect($this->people($case))->map(fn (string $label, int $id): array => ['id' => $id, 'label' => $label])->values()->all(),
            'abilities' => [
                'show' => true,
                'assign' => true,
                'note' => true,
                'resolve' => ! $closed,
                'reopen' => $closed,
                'reply' => true,
                'freeze' => $chat,
                'unfreeze' => $chat,
                'hold' => $retention,
                'release' => $retention,
                'evidence' => $sensitive,
                // Esportare le evidenze resta sul sito (decisione 8): l'app apre il pannello.
                'export' => $safety->staff($staff, Permission::ExportCommunityEvidence, true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function rideRow(RideOffer $offer, User $staff): array
    {
        return [
            'kind' => 'ride',
            'id' => $offer->id,
            'title' => (string) ($offer->snapshot['title'] ?? ''),
            'leg' => $offer->leg->value,
            'leg_label' => $offer->leg->label(),
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'driver' => $offer->driver?->name,
            'zone' => (string) $offer->zone,
            'departure_at' => $offer->departure_at->toIso8601String(),
            'capacity' => (int) $offer->capacity,
            'requests_count' => (int) ($offer->requests_count ?? 0),
            'abilities' => [
                'cancel' => in_array($offer->status, self::CANCELLABLE, true) && app(CommunitySafety::class)->staff($staff, Permission::ManageCarpool),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function reviewRow(RideReview $review, User $staff): array
    {
        $hidden = $review->status === CommunityStatus::Hidden;
        $can = app(CommunitySafety::class)->staff($staff, Permission::ManageCarpool);

        return [
            'kind' => 'review',
            'id' => $review->id,
            'author' => $review->user?->name,
            'driver' => $review->driver?->name,
            'rating' => $review->rating,
            'body' => $review->body !== null ? (string) $review->body : null,
            'confirmed_at' => $review->rideRequest?->passenger_confirmed_at?->toIso8601String(),
            'ride_request_id' => (int) $review->ride_request_id,
            'status' => $review->status->value,
            'hidden' => $hidden,
            'abilities' => ['hide' => $can && ! $hidden, 'restore' => $can && $hidden],
        ];
    }

    /** @return array<string, mixed> */
    public function feedbackRow(RideFeedback $feedback): array
    {
        return [
            'kind' => 'feedback',
            'id' => $feedback->id,
            'author' => $feedback->user?->name,
            'feedback_kind' => $feedback->kind->value,
            'kind_label' => $feedback->kind->label(),
            'ride_request_id' => (int) $feedback->ride_request_id,
            'created_at' => $feedback->created_at?->toIso8601String(),
            'body' => $feedback->body !== null ? (string) $feedback->body : null,
            'abilities' => (object) [],
        ];
    }

    /**
     * Una persona con i requisiti (email e WhatsApp verificati) e le tre
     * sospensioni. Per ogni ambito il pannello mostra «Sospendi» a chi sta
     * sotto nello staff e «Ripristina» se l'ambito è sospeso; «tutta la
     * community» solo a chi ha `users.manage`. L'email non esce: il pannello
     * la usa per cercare, non la mostra.
     *
     * @return array<string, mixed>
     */
    public function userRow(User $person, User $staff): array
    {
        $safety = app(CommunitySafety::class);
        $abilities = [];
        foreach (self::RESTRICTION_SCOPES as $scope => $field) {
            $allowed = ($scope !== 'global' || $staff->hasPermissionTo(Permission::ManageUsers->value))
                && $safety->staff($staff, $scope === 'social' ? Permission::ManageCommunity : Permission::ManageCarpool);
            $suspended = $person->{$field} !== null;
            $abilities['suspend_'.$scope] = $allowed && ! $suspended && CommunityModeration::outranks($staff, $person);
            $abilities['restore_'.$scope] = $allowed && $suspended;
        }

        return [
            'kind' => 'user',
            'id' => $person->id,
            'name' => $person->name,
            'email_verified_at' => $person->email_verified_at?->toIso8601String(),
            'whatsapp_verified_at' => $person->whatsapp_verified_at?->toIso8601String(),
            'social_suspended' => $person->social_suspended_at !== null,
            'carpool_suspended' => $person->carpool_suspended_at !== null,
            'community_suspended' => $person->community_suspended_at !== null,
            'abilities' => $abilities,
        ];
    }

    /** @return array<string, mixed> */
    public function auditRow(CarpoolAudit $audit): array
    {
        return [
            'kind' => 'audit',
            'id' => $audit->id,
            'created_at' => $audit->created_at?->toIso8601String(),
            'action' => (string) $audit->action,
            'subject_type' => (string) $audit->subject_type,
            'subject_id' => $audit->subject_id !== null ? (int) $audit->subject_id : null,
            'actor' => $audit->actor?->name,
            'abilities' => (object) [],
        ];
    }

    /** @return array<string, mixed> */
    public function deliveryRow(stdClass $delivery, User $staff): array
    {
        $attempts = (int) $delivery->attempts;

        return [
            'kind' => 'delivery',
            'id' => (int) $delivery->id,
            'user_id' => (int) $delivery->user_id,
            'attempts' => $attempts,
            'last_error' => $delivery->last_error !== null ? (string) $delivery->last_error : null,
            'available_at' => $delivery->available_at !== null ? Carbon::parse((string) $delivery->available_at, 'UTC')->toIso8601String() : null,
            'abilities' => [
                'retry' => $attempts >= self::RETRY_AFTER_ATTEMPTS && app(CommunitySafety::class)->staff($staff, Permission::ManageCarpool),
            ],
        ];
    }
}
