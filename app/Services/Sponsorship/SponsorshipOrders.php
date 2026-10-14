<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\Permission;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\VenueStatus;
use App\Models\Organizer;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SponsorshipOrderDecided;
use App\Notifications\SponsorshipOrderPaid;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Il ciclo di un ordine di sponsorizzazione: aperto, pagato, deciso.
 *
 * **Il pagamento non basta.** Un ordine pagato e' una richiesta: la redazione
 * lo approva, e solo allora nasce l'abilitazione — con il periodo che parte
 * dall'approvazione, non dal pagamento, cosi' chi paga di venerdi' sera non
 * perde il fine settimana. Rifiutare rimborsa.
 *
 * `markPaid` e' idempotente perche' arriva da due strade — il webhook e il
 * ritorno dal checkout — che possono presentarsi entrambe, in qualunque
 * ordine, anche due volte.
 */
final class SponsorshipOrders
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly GrantCampaigns $campaigns,
    ) {}

    public function available(): bool
    {
        return $this->gateway->isConfigured() && SponsorshipPrice::active()->exists();
    }

    public function place(User $buyer, Venue|Organizer $sponsor, SponsorshipPrice $price, PromotionMode $mode, string $successUrl, string $cancelUrl): SponsorshipOrder
    {
        if (! $this->gateway->isConfigured()) {
            throw new PaymentGatewayException('Payments are not configured.');
        }

        Gate::forUser($buyer)->authorize('purchase', [SponsorshipOrder::class, $sponsor]);
        abort_unless($this->sponsorIsOperational($sponsor), 403);
        abort_unless($price->is_active, 403);

        $order = SponsorshipOrder::create([
            'venue_id' => $sponsor instanceof Venue ? $sponsor->getKey() : null,
            'organizer_id' => $sponsor instanceof Organizer ? $sponsor->getKey() : null,
            'user_id' => $buyer->getKey(),
            'sponsorship_price_id' => $price->getKey(),
            'placement' => $price->placement,
            'mode' => $mode,
            'months' => $price->months,
            'amount_cents' => $price->amount_cents,
            'currency' => $price->currency,
            'status' => SponsorshipOrderStatus::PendingPayment,
        ]);
        $order->setRelation('user', $buyer);

        return $this->openCheckout($order, $successUrl, $cancelUrl);
    }

    /** Riapre la pagina di pagamento di un ordine ancora da pagare. */
    public function openCheckout(SponsorshipOrder $order, string $successUrl, string $cancelUrl): SponsorshipOrder
    {
        abort_unless($order->status === SponsorshipOrderStatus::PendingPayment, 403);

        /* `{ORDER}` lo mette chi chiama, che l'ordine ancora non ce l'aveva;
           `{CHECKOUT_SESSION_ID}` resta com'e' e lo sostituisce Stripe. */
        $id = (string) $order->getKey();
        $session = $this->gateway->checkout($order, str_replace('{ORDER}', $id, $successUrl), str_replace('{ORDER}', $id, $cancelUrl));
        $order->forceFill(['checkout_session_id' => $session->id])->save();
        $order->checkout_url = $session->url;

        return $order;
    }

    /**
     * Al ritorno dal checkout: se Stripe dice che e' pagato, e' pagato anche
     * se il webhook non e' ancora arrivato.
     */
    public function reconcile(SponsorshipOrder $order): void
    {
        if ($order->status !== SponsorshipOrderStatus::PendingPayment || $order->checkout_session_id === null) {
            return;
        }

        $session = $this->gateway->session($order->checkout_session_id);

        if ($session->paid && $session->paymentIntentId !== null) {
            $this->markPaid($order, $session->paymentIntentId, CarbonImmutable::now('UTC'));
        }
    }

    public function markPaid(SponsorshipOrder $order, string $paymentIntentId, CarbonImmutable $paidAt): void
    {
        $notify = DB::transaction(function () use ($order, $paymentIntentId, $paidAt): bool {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($order->status !== SponsorshipOrderStatus::PendingPayment) {
                return false;
            }

            $order->transitionTo(SponsorshipOrderStatus::Paid);
            $order->forceFill(['payment_intent_id' => $paymentIntentId, 'paid_at' => $paidAt])->save();

            return true;
        });

        if ($notify) {
            $order->refresh();
            Notification::send($this->decisionMakers(), new SponsorshipOrderPaid($order));
        }
    }

    public function approve(SponsorshipOrder $order, User $admin): SponsorshipGrant
    {
        $grant = DB::transaction(function () use ($order, $admin): SponsorshipGrant {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->transitionTo(SponsorshipOrderStatus::Approved);

            $now = CarbonImmutable::now('UTC');
            $sponsor = $order->sponsor();

            $grant = SponsorshipGrant::create([
                'venue_id' => $order->venue_id,
                'organizer_id' => $order->organizer_id,
                'created_by' => $admin->getKey(),
                'mode' => $order->mode,
                'placement' => $order->placement,
                'enabled' => true,
                'complimentary' => false,
                'starts_at' => $now,
                'ends_at' => $now->setTimezone($this->timezoneOf($sponsor))->addMonthsNoOverflow($order->months)->utc(),
                'amount_cents' => $order->amount_cents,
                'paid_at' => $order->paid_at ?? $now,
                'payment_method' => 'stripe',
                'payment_reference' => $order->payment_intent_id,
                'notes' => __('promotions.orders.grant_note', ['order' => $order->getKey()]),
            ]);

            $order->forceFill(['decided_at' => $now, 'decided_by' => $admin->getKey(), 'sponsorship_grant_id' => $grant->getKey()])->save();

            return $grant;
        });

        $this->campaigns->sync($grant);
        $this->notifyBuyer($order->refresh());

        return $grant;
    }

    public function reject(SponsorshipOrder $order, User $admin, string $reason): void
    {
        DB::transaction(function () use ($order, $admin, $reason): void {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->transitionTo(SponsorshipOrderStatus::Rejected);

            $note = trim($reason);

            if ($order->payment_intent_id !== null) {
                try {
                    $this->gateway->refund($order->payment_intent_id);
                    $note .= "\n".__('promotions.orders.refund_done');
                } catch (PaymentGatewayException $e) {
                    /* La decisione resta: il rimborso si sistema dalla
                       dashboard di Stripe, e la nota dice perche'. */
                    $note .= "\n".__('promotions.orders.refund_failed', ['error' => $e->getMessage()]);
                }
            }

            $order->forceFill(['decided_at' => CarbonImmutable::now('UTC'), 'decided_by' => $admin->getKey(), 'decision_note' => $note])->save();
        });

        $this->notifyBuyer($order->refresh());
    }

    public function cancel(SponsorshipOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->transitionTo(SponsorshipOrderStatus::Cancelled);
            $order->save();
        });
    }

    public function sponsorIsOperational(Venue|Organizer $sponsor): bool
    {
        return $sponsor instanceof Venue
            ? $sponsor->status === VenueStatus::Approved
            : (bool) $sponsor->is_active;
    }

    private function timezoneOf(Venue|Organizer $sponsor): string
    {
        return (string) $sponsor->city->timezone;
    }

    private function notifyBuyer(SponsorshipOrder $order): void
    {
        $order->user?->notify(new SponsorshipOrderDecided($order));
    }

    /** @return Collection<int, User> */
    private function decisionMakers(): Collection
    {
        return User::permission(Permission::ManageSponsorships->value)->get();
    }
}
