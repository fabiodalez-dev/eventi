<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Enums\SponsorshipOrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPassword;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Services\Editorial\Promotion\EditorialSponsorshipOrders;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Gli ordini di sponsorizzazione (R-SP-07…10): elenco con il filtro di
 * stato, approvazione (ordine pagato, oppure richiesta pagata a mano o
 * gratuita) e rifiuto con motivo. Solo admin e super admin
 * (`SponsorshipOrderPolicy`). Un ordine che non aspetta più una decisione
 * risponde 409.
 */
final class SponsorshipOrderController extends Controller
{
    use ListsPlaces;

    public function __construct(
        private readonly EditorialSponsorshipOrders $orders,
        private readonly RequireRecentPassword $recentPassword,
    ) {}

    /** `filter[status]` (uno degli stati dell'ordine). */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        Gate::forUser($user)->authorize('viewAny', SponsorshipOrder::class);
        $page = $this->orders->paginate($this->listFilters($request, ['status']), $this->listPerPage($request), $this->listPage($request));

        return response()->json([
            ...$this->listPayload($page, array_values(array_map(fn (SponsorshipOrder $order): array => $this->orders->row($order, $user), $page->items()))),
            'choices' => EditorialSponsorshipOrders::choices(),
        ]);
    }

    /**
     * Approva. Un ordine pagato non chiede altro; una richiesta vuole
     * `settlement` (`paid` con `amount` in euro e `payment_method`, più
     * `payment_reference` facoltativo; `free` con `reason`).
     */
    public function approve(Request $request, int $order): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->order($user, $order);
        if (! in_array($record->status, [SponsorshipOrderStatus::Paid, SponsorshipOrderStatus::Requested], true)) {
            throw new ApiException(ApiErrorCode::Conflict, __('promotions.orders.status.'.$record->status->value));
        }
        $this->decide($record, fn () => $this->orders->approve($record, $user, $request->all()));

        return response()->json(['data' => $this->orders->row($record->refresh(), $user), 'message' => __('promotions.orders.approved')]);
    }

    /**
     * Rifiuta con `reason` (al massimo 2000 caratteri); un ordine pagato
     * online viene rimborsato su Stripe, e un rimborso non si annulla: in quel
     * caso serve la password scritta negli ultimi 15 minuti (423
     * `PASSWORD_REQUIRED`), come per le altre azioni pericolose. La rotta non
     * può dirlo da sola, perché dipende dall'ordine.
     */
    public function reject(Request $request, int $order): JsonResponse
    {
        $user = $this->user($request);
        $record = $this->order($user, $order);
        if (! $record->status->awaitsDecision()) {
            throw new ApiException(ApiErrorCode::Conflict, __('promotions.orders.status.'.$record->status->value));
        }
        if ($record->refundsOnReject()) {
            $this->recentPassword->ensure($request);
        }
        $this->decide($record, fn () => $this->orders->reject($record, $user, $request->all()));

        return response()->json(['data' => $this->orders->row($record->refresh(), $user), 'message' => __('promotions.orders.rejected')]);
    }

    /**
     * Una decisione presa mentre un'altra persona decideva lo stesso ordine:
     * il servizio rilegge la riga bloccata e `transitionTo` rifiuta il
     * passaggio. È un conflitto (409 con lo stato di adesso), non un errore
     * del server.
     *
     * @param  \Closure(): void  $decision
     */
    private function decide(SponsorshipOrder $order, \Closure $decision): void
    {
        try {
            $decision();
        } catch (InvalidArgumentException) {
            $status = SponsorshipOrder::query()->whereKey($order->getKey())->value('status');
            $status = $status instanceof SponsorshipOrderStatus ? $status : SponsorshipOrderStatus::tryFrom((string) $status);

            throw new ApiException(ApiErrorCode::Conflict, $status === null ? null : __('promotions.orders.decided_meanwhile', ['status' => $status->label()]));
        }
    }

    private function user(Request $request): User
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function order(User $user, int $id): SponsorshipOrder
    {
        $order = SponsorshipOrder::query()->with(['venue', 'organizer', 'user', 'decider', 'event.media', 'event.city', 'event.venue:id,name'])->find($id);
        if (! $order instanceof SponsorshipOrder) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize('decide', $order);

        return $order;
    }
}
