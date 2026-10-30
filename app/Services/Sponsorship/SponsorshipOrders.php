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
use App\Notifications\SponsorshipStrayRefundFailed;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use App\Services\Sponsorship\Payment\PaymentSessionMissing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
 *
 * **Una sola pagina di pagamento aperta per ordine.** Riprovare o annullare
 * chiude prima la sessione precedente su Stripe; e se un incasso arriva lo
 * stesso su un ordine che non lo aspetta piu' (annullato, o gia' pagato con
 * un altro incasso), si rimborsa subito e lo si scrive sull'ordine, invece di
 * tenere i soldi in silenzio.
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

    /**
     * Riapre la pagina di pagamento di un ordine ancora da pagare.
     *
     * Se la sessione precedente risulta pagata l'ordine torna pagato e non se
     * ne apre un'altra (`checkout_url` resta vuoto). Se tariffa o committente
     * non reggono piu', l'ordine si annulla: l'importo e la durata sono
     * quelli del giorno in cui e' nato, e un ordine lasciato in attesa non
     * deve diventare un modo di pagare una tariffa ritirata.
     *
     * @throws OrderNoLongerPayable
     * @throws PaymentGatewayException
     */
    public function openCheckout(SponsorshipOrder $order, string $successUrl, string $cancelUrl): SponsorshipOrder
    {
        abort_unless($order->status === SponsorshipOrderStatus::PendingPayment, 403);

        if (! $this->retirePreviousSession($order)) {
            return $order->refresh();
        }

        if (! $this->stillPayable($order)) {
            $this->markCancelled($order);

            throw new OrderNoLongerPayable;
        }

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
        $outcome = DB::transaction(function () use ($order, $paymentIntentId, $paidAt): string {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($order->status !== SponsorshipOrderStatus::PendingPayment) {
                /* Lo stesso incasso che torna non e' una novita'; uno diverso
                   e' un pagamento che l'ordine non aspettava piu'. */
                return $order->payment_intent_id === $paymentIntentId ? 'known' : 'stray';
            }

            $order->transitionTo(SponsorshipOrderStatus::Paid);
            $order->forceFill(['payment_intent_id' => $paymentIntentId, 'paid_at' => $paidAt])->save();

            return 'paid';
        });

        if ($outcome === 'paid') {
            $order->refresh();
            Notification::send($this->decisionMakers(), new SponsorshipOrderPaid($order));
        } elseif ($outcome === 'stray') {
            $this->refundStrayPayment($order, $paymentIntentId);
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

            /* Il motivo resta in prima riga (e' quella che vede chi ha
               pagato); sotto, le note che c'erano gia', come un incasso
               inatteso da rimborsare a mano, che il rifiuto non cancella. */
            $note = trim($note."\n".(string) $order->decision_note);

            $order->forceFill(['decided_at' => CarbonImmutable::now('UTC'), 'decided_by' => $admin->getKey(), 'decision_note' => $note])->save();
        });

        $this->notifyBuyer($order->refresh());
    }

    /**
     * Annulla un ordine in attesa, chiudendo prima la sua pagina di pagamento.
     * Se nel frattempo risulta pagato, lo segna pagato e restituisce false:
     * un ordine pagato non si annulla, lo decide la redazione.
     *
     * @throws PaymentGatewayException
     */
    public function cancel(SponsorshipOrder $order): bool
    {
        if (! $this->retirePreviousSession($order)) {
            return false;
        }

        $this->markCancelled($order);

        return true;
    }

    public function sponsorIsOperational(Venue|Organizer $sponsor): bool
    {
        return $sponsor instanceof Venue
            ? $sponsor->status === VenueStatus::Approved
            : (bool) $sponsor->is_active;
    }

    /**
     * Chiude su Stripe la pagina di pagamento dell'ordine, se ce n'e' una.
     * Restituisce false se quella pagina risulta gia' pagata: l'ordine e'
     * appena diventato pagato, e non c'e' altro da aprire ne' da annullare.
     *
     * Senza chiavi Stripe non c'e' niente da chiudere ne' da rileggere: un
     * ordine rimasto in attesa da allora si deve poter annullare lo stesso.
     * Vale anche per una pagina che l'account Stripe di oggi non conosce
     * (nata con le chiavi di test, o su un altro account): su quell'account
     * non si puo' pagare, e senza questa uscita l'ordine resterebbe in
     * attesa per sempre. Ogni altro errore invece risale: un guasto
     * passeggero non deve far annullare un ordine che forse e' pagato.
     *
     * @throws PaymentGatewayException
     */
    private function retirePreviousSession(SponsorshipOrder $order): bool
    {
        if ($order->checkout_session_id === null || ! $this->gateway->isConfigured()) {
            return true;
        }

        try {
            $session = $this->gateway->session($order->checkout_session_id);

            if ($session->paid && $session->paymentIntentId !== null) {
                $this->markPaid($order, $session->paymentIntentId, CarbonImmutable::now('UTC'));

                return false;
            }

            $this->gateway->expire($order->checkout_session_id);
        } catch (PaymentSessionMissing $e) {
            Log::warning('Sponsorship: pagina di pagamento sconosciuta all\'account Stripe attuale, niente da chiudere', [
                'order' => $order->getKey(), 'session' => $order->checkout_session_id, 'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /** L'ordine si paga ancora alle condizioni del listino di oggi, e da un committente operativo? */
    private function stillPayable(SponsorshipOrder $order): bool
    {
        $price = SponsorshipPrice::query()->find($order->sponsorship_price_id);

        return $price !== null
            && $price->is_active
            && $price->placement === $order->placement
            && $price->months === $order->months
            && $price->amount_cents === $order->amount_cents
            && strtoupper((string) $price->currency) === strtoupper((string) $order->currency)
            && $this->sponsorIsOperational($order->sponsor());
    }

    private function markCancelled(SponsorshipOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->transitionTo(SponsorshipOrderStatus::Cancelled);
            $order->save();
        });
    }

    /**
     * Un incasso arrivato su un ordine che non lo aspettava: annullato, o gia'
     * pagato con un altro incasso (due schede aperte, un webhook in ritardo).
     * Si rimborsa subito e lo si scrive sull'ordine; se Stripe non rimborsa,
     * la nota dice che va fatto a mano e la redazione riceve un'email: la
     * nota da sola sta in una colonna nascosta, e i soldi resterebbero
     * incassati senza che nessuno lo sappia. Lo stesso incasso visto due
     * volte non si rimborsa due volte.
     */
    private function refundStrayPayment(SponsorshipOrder $order, string $paymentIntentId): void
    {
        if (str_contains((string) $order->fresh()?->decision_note, $paymentIntentId)) {
            return;
        }

        $failure = null;

        try {
            $this->gateway->refund($paymentIntentId);
            $line = __('promotions.orders.stray_refund_done', ['intent' => $paymentIntentId]);
        } catch (PaymentGatewayException $e) {
            $failure = $e->getMessage();
            $line = __('promotions.orders.stray_refund_failed', ['intent' => $paymentIntentId, 'error' => $failure]);
        }

        $status = DB::transaction(function () use ($order, $line): string {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $order->forceFill(['decision_note' => ltrim($order->decision_note."\n".$line)])->save();

            return $order->status->value;
        });

        Log::warning('Sponsorship: incasso arrivato su un ordine che non lo aspettava', [
            'order' => $order->getKey(), 'status' => $status, 'payment_intent' => $paymentIntentId,
        ]);

        if ($failure !== null) {
            Notification::send($this->decisionMakers(), new SponsorshipStrayRefundFailed($order->refresh(), $paymentIntentId, $failure));
        }
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
