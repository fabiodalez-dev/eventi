<?php

declare(strict_types=1);

namespace App\Services\Editorial\Promotion;

use App\Enums\SponsorshipOrderStatus;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Services\Sponsorship\SponsorshipOrders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gli ordini di sponsorizzazione (R-SP-07…10) nella redazione dell'app, come
 * `SponsorshipOrderResource`: l'elenco con il filtro di stato, e le tre
 * decisioni del pannello, che passano tutte da `SponsorshipOrders` (lo
 * stesso servizio, lo stesso avviso a chi ha ordinato, lo stesso rimborso su
 * Stripe al rifiuto di un ordine pagato).
 *
 * - **Approva** un ordine pagato: niente da chiedere.
 * - **Approva** una richiesta: pagata (importo, metodo, riferimento) o
 *   gratuita (motivo).
 * - **Rifiuta** con il motivo, che legge chi ha ordinato.
 */
final class EditorialSponsorshipOrders
{
    public const REASON_MAX = 2000;

    public const AMOUNT_MAX = 100000;

    public function __construct(private readonly SponsorshipOrders $orders) {}

    /**
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, SponsorshipOrder>
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return SponsorshipOrder::query()
            ->with(['venue', 'organizer', 'user', 'decider', 'event.media', 'event.city', 'event.venue:id,name'])
            ->when(($status = SponsorshipOrderStatus::tryFrom($filters['status'] ?? '')) !== null, fn (Builder $q): Builder => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function row(SponsorshipOrder $order, User $user): array
    {
        $decide = Gate::forUser($user)->allows('decide', $order);
        $tz = 'Europe/Rome';

        return [
            'id' => (int) $order->getKey(),
            'created_at' => PromotionPayload::instant($order->created_at, $tz),
            'sponsor' => $order->sponsorName(),
            'sponsor_kind' => $order->venue_id !== null ? __('promotions.venue') : __('promotions.organizer'),
            'event' => PromotionPayload::event($order->event, (string) ($order->event?->city?->timezone ?: $tz)),
            'event_label' => $order->event === null ? __('promotions.request.all_events') : (string) $order->event->title,
            'placement' => PromotionPayload::placement($order->placement),
            'months' => (int) $order->months,
            'months_label' => __('promotions.orders.months_short', ['months' => $order->months]),
            'mode' => ['value' => $order->mode->value, 'label' => $order->mode->label()],
            'amount_cents' => (int) $order->amount_cents,
            'amount_label' => $order->amount_cents > 0 ? $order->formattedAmount() : __('promotions.request.price_to_agree'),
            'status' => ['value' => $order->status->value, 'label' => $order->status->label(), 'color' => $order->status->color()],
            'buyer' => $order->user?->name,
            'paid_at' => PromotionPayload::instant($order->paid_at, $tz),
            'decided_at' => PromotionPayload::instant($order->decided_at, $tz),
            'decided_by' => $order->decider?->name,
            'request_note' => $order->request_note,
            'decision_note' => $order->decision_note,
            'payment_reference' => $order->payment_intent_id,
            'approve_lead' => $order->status === SponsorshipOrderStatus::Requested ? __('promotions.orders.approve_request_lead') : __('promotions.orders.approve_confirm'),
            'reject_lead' => $order->status === SponsorshipOrderStatus::Requested ? __('promotions.orders.reject_request_confirm') : __('promotions.orders.reject_confirm'),
            'suggested_amount' => $order->amount_cents > 0 ? PromotionPayload::euros((int) $order->amount_cents) : null,
            'abilities' => [
                'approve' => $order->status === SponsorshipOrderStatus::Paid && $decide,
                'approve_request' => $order->status === SponsorshipOrderStatus::Requested && $decide,
                'reject' => $order->status->awaitsDecision() && $decide,
            ],
        ];
    }

    /**
     * Approva: un ordine pagato così com'è, una richiesta con il saldo.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function approve(SponsorshipOrder $order, User $admin, array $input): void
    {
        if ($order->status === SponsorshipOrderStatus::Paid) {
            $this->orders->approve($order, $admin);

            return;
        }

        $settlement = $input['settlement'] ?? null;
        $data = Validator::make($input, [
            'settlement' => ['required', Rule::in(['paid', 'free'])],
            'amount' => [Rule::requiredIf($settlement === 'paid'), 'nullable', 'numeric', 'min:0.01', 'max:'.self::AMOUNT_MAX],
            'payment_method' => [Rule::requiredIf($settlement === 'paid'), 'nullable', Rule::in(array_keys(self::methods()))],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'reason' => [Rule::requiredIf($settlement === 'free'), 'nullable', 'string', 'max:'.self::REASON_MAX],
        ], [], [
            'settlement' => __('promotions.orders.settlement'),
            'amount' => __('promotions.orders.settlement_amount'),
            'payment_method' => __('promotions.orders.settlement_method'),
            'payment_reference' => __('promotions.orders.settlement_reference'),
            'reason' => __('promotions.orders.settlement_reason'),
        ])->validate();

        $this->orders->approve($order, $admin, $settlement === 'free'
            ? ['complimentary' => true, 'notes' => (string) ($data['reason'] ?? '')]
            : ['amount_cents' => (int) round((float) ($data['amount'] ?? 0) * 100), 'payment_method' => (string) ($data['payment_method'] ?? 'other'),
                'payment_reference' => filled($data['payment_reference'] ?? null) ? (string) $data['payment_reference'] : null]);
    }

    /**
     * Rifiuta con il motivo.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function reject(SponsorshipOrder $order, User $admin, array $input): void
    {
        $data = Validator::make($input, [
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX],
        ], [], ['reason' => __('promotions.orders.reject_reason')])->validate();

        $this->orders->reject($order, $admin, (string) $data['reason']);
    }

    /** @return array<string, string> */
    public static function methods(): array
    {
        /** @var array<string, string> $methods */
        $methods = (array) __('promotions.orders.methods');

        return $methods;
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function choices(): array
    {
        return [
            'statuses' => PromotionPayload::choices(SponsorshipOrderStatus::options()),
            'methods' => PromotionPayload::choices(self::methods()),
            'settlements' => PromotionPayload::choices(['paid' => __('promotions.orders.settlement_paid'), 'free' => __('promotions.orders.settlement_free')]),
        ];
    }
}
