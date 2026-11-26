<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\Permission;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\VenueStatus;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SponsorshipOrderDecided;
use App\Notifications\SponsorshipOrderPaid;
use App\Notifications\SponsorshipOrderRequested;
use App\Notifications\SponsorshipStrayRefundFailed;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use App\Services\Sponsorship\Payment\PaymentSessionMissing;
use App\Support\SecurityLog;
use App\Support\Sponsorship\SponsorableEvents;
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
 *
 * **Senza Stripe si chiede.** Quando i pagamenti online non sono configurati
 * l'ordine nasce `requested`: stesse scelte (posizione, durata, evento), nessun
 * addebito, e la redazione approva registrando a mano il pagamento o
 * concedendola gratis. Prima di questa strada la pagina mostrava solo un
 * elenco vuoto e nessun modo di cominciare.
 */
final class SponsorshipOrders
{
    /** Quante richieste aperte puo' avere un committente nello stesso momento. */
    public const MAX_OPEN_REQUESTS = 3;

    /** Le durate proposte quando il listino non ha voci per una posizione. */
    public const FALLBACK_MONTHS = [1, 3, 6, 12];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly GrantCampaigns $campaigns,
    ) {}

    /** Acquisto online con carta: Stripe configurato e un listino da cui scegliere. */
    public function available(): bool
    {
        return $this->gateway->isConfigured() && SponsorshipPrice::active()->exists();
    }

    /**
     * Le durate fra cui scegliere per una posizione: le voci del listino, se
     * ce ne sono; altrimenti durate fisse con il prezzo da concordare (solo
     * per le richieste: senza prezzo non si paga online).
     *
     * @return list<array{key: string, months: int, amount_cents: int|null, price_id: int|null}>
     */
    public function durations(SponsorshipPlacement $placement): array
    {
        $prices = SponsorshipPrice::active()->where('placement', $placement)->orderBy('months')->get();

        if ($prices->isNotEmpty()) {
            return $prices->map(fn (SponsorshipPrice $price): array => [
                'key' => 'price:'.$price->getKey(), 'months' => (int) $price->months,
                'amount_cents' => (int) $price->amount_cents, 'price_id' => (int) $price->getKey(),
            ])->values()->all();
        }

        return array_map(fn (int $months): array => ['key' => 'months:'.$months, 'months' => $months, 'amount_cents' => null, 'price_id' => null], self::FALLBACK_MONTHS);
    }

    /**
     * Una richiesta senza pagamento online. Non attiva niente: la redazione
     * riceve un'email e decide dall'elenco ordini.
     */
    public function request(User $buyer, Venue|Organizer $sponsor, SponsorshipPlacement $placement, int $months, ?SponsorshipPrice $price, PromotionMode $mode, ?Event $event, ?string $note = null): SponsorshipOrder
    {
        Gate::forUser($buyer)->authorize('purchase', [SponsorshipOrder::class, $sponsor]);
        abort_unless($this->sponsorIsOperational($sponsor), 403);
        $this->assertEvent($sponsor, $mode, $event);

        if ($price !== null) {
            abort_unless($price->is_active && $price->placement === $placement && (int) $price->months === $months, 403);
        } else {
            /* Senza voce di listino si accettano solo le durate proposte, e
               solo se il listino per quella posizione e' davvero vuoto. */
            abort_unless(in_array($months, self::FALLBACK_MONTHS, true)
                && ! SponsorshipPrice::active()->where('placement', $placement)->exists(), 403);
        }

        $open = SponsorshipOrder::query()->forSponsor($sponsor)->where('status', SponsorshipOrderStatus::Requested)->count();
        abort_if($open >= self::MAX_OPEN_REQUESTS, 429, __('promotions.request.too_many', ['max' => self::MAX_OPEN_REQUESTS]));

        $order = SponsorshipOrder::create([
            'venue_id' => $sponsor instanceof Venue ? $sponsor->getKey() : null,
            'organizer_id' => $sponsor instanceof Organizer ? $sponsor->getKey() : null,
            'event_id' => $event?->getKey(),
            'user_id' => $buyer->getKey(),
            'sponsorship_price_id' => $price?->getKey(),
            'placement' => $placement,
            'mode' => $mode,
            'months' => $months,
            'amount_cents' => $price !== null ? (int) $price->amount_cents : 0,
            'currency' => $price !== null ? $price->currency : 'EUR',
            'request_note' => filled($note) ? trim((string) $note) : null,
            'status' => SponsorshipOrderStatus::Requested,
        ]);

        Notification::send($this->decisionMakers(), new SponsorshipOrderRequested($order->load(['venue', 'organizer', 'event'])));

        return $order;
    }

    /** Chi ha chiesto ritira la richiesta prima che la redazione decida. */
    public function withdraw(SponsorshipOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            abort_unless($order->status === SponsorshipOrderStatus::Requested, 403);
            $order->transitionTo(SponsorshipOrderStatus::Cancelled);
            $order->save();
        });
    }

    public function place(User $buyer, Venue|Organizer $sponsor, SponsorshipPrice $price, PromotionMode $mode, string $successUrl, string $cancelUrl, ?Event $event = null): SponsorshipOrder
    {
        if (! $this->gateway->isConfigured()) {
            throw new PaymentGatewayException('Payments are not configured.');
        }

        Gate::forUser($buyer)->authorize('purchase', [SponsorshipOrder::class, $sponsor]);
        abort_unless($this->sponsorIsOperational($sponsor), 403);
        abort_unless($price->is_active, 403);
        $this->assertEvent($sponsor, $mode, $event);

        $order = SponsorshipOrder::create([
            'venue_id' => $sponsor instanceof Venue ? $sponsor->getKey() : null,
            'organizer_id' => $sponsor instanceof Organizer ? $sponsor->getKey() : null,
            'event_id' => $event?->getKey(),
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

    /**
     * La redazione approva: nasce l'abilitazione, con il periodo che parte da
     * adesso. Un ordine pagato online porta con se' il pagamento; una
     * richiesta no, e `$payment` dice come e' stata saldata:
     * `['complimentary' => true, 'notes' => motivo]` oppure
     * `['amount_cents' => …, 'payment_method' => …, 'payment_reference' => …]`.
     * Se l'ordine indicava un evento, l'evento entra subito in evidenza.
     *
     * @param  array{complimentary?: bool, notes?: string|null, amount_cents?: int|null, payment_method?: string|null, payment_reference?: string|null}  $payment
     */
    public function approve(SponsorshipOrder $order, User $admin, array $payment = []): SponsorshipGrant
    {
        $grant = DB::transaction(function () use ($order, $admin, $payment): SponsorshipGrant {
            $order = SponsorshipOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $requested = $order->status === SponsorshipOrderStatus::Requested;
            $order->transitionTo(SponsorshipOrderStatus::Approved);

            $now = CarbonImmutable::now('UTC');
            $sponsor = $order->sponsor();
            $note = __('promotions.orders.grant_note', ['order' => $order->getKey()]);

            $money = ['complimentary' => false, 'amount_cents' => $order->amount_cents, 'paid_at' => $order->paid_at ?? $now,
                'payment_method' => 'stripe', 'payment_reference' => $order->payment_intent_id, 'notes' => $note];

            if ($requested) {
                $complimentary = (bool) ($payment['complimentary'] ?? false);
                $amount = (int) ($payment['amount_cents'] ?? $order->amount_cents);
                abort_if(! $complimentary && $amount < 1, 422, __('promotions.request.amount_required'));
                abort_if($complimentary && blank($payment['notes'] ?? null), 422, __('promotions.request.reason_required'));

                $money = $complimentary
                    ? ['complimentary' => true, 'amount_cents' => null, 'paid_at' => null, 'payment_method' => null, 'payment_reference' => null,
                        'notes' => trim($note.' '.trim((string) $payment['notes']))]
                    : ['complimentary' => false, 'amount_cents' => $amount, 'paid_at' => $now,
                        'payment_method' => filled($payment['payment_method'] ?? null) ? (string) $payment['payment_method'] : 'manual',
                        'payment_reference' => filled($payment['payment_reference'] ?? null) ? (string) $payment['payment_reference'] : null, 'notes' => $note];
            }

            $grant = SponsorshipGrant::create([
                'venue_id' => $order->venue_id,
                'organizer_id' => $order->organizer_id,
                'created_by' => $admin->getKey(),
                'mode' => $order->mode,
                'placement' => $order->placement,
                'enabled' => true,
                'starts_at' => $now,
                'ends_at' => $now->setTimezone($this->timezoneOf($sponsor))->addMonthsNoOverflow($order->months)->utc(),
                ...$money,
            ]);

            $order->forceFill(['decided_at' => $now, 'decided_by' => $admin->getKey(), 'sponsorship_grant_id' => $grant->getKey()])->save();

            return $grant;
        });

        $this->campaigns->sync($grant);
        $order->refresh();
        SecurityLog::scrivi('ordine_sponsorizzazione_approvato', $order->user, [
            'ordine_id' => (int) $order->getKey(),
            'abilitazione_id' => (int) $grant->getKey(),
            'importo_centesimi' => $grant->amount_cents,
            'gratuito' => (bool) $grant->complimentary,
            'rimborso' => false,
        ], causa: $admin);
        $this->attachChosenEvent($order, $grant);
        $this->notifyBuyer($order);

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

        $order->refresh();
        SecurityLog::scrivi('ordine_sponsorizzazione_rifiutato', $order->user, [
            'ordine_id' => (int) $order->getKey(),
            'importo_centesimi' => (int) $order->amount_cents,
            'rimborso' => $order->payment_intent_id !== null,
        ], causa: $admin);
        $this->notifyBuyer($order);
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

    /**
     * L'evento indicato con l'ordine: del committente, sponsorizzabile, e
     * solo con la modalita' «eventi scelti». Il controllo e' qui e non nella
     * pagina, perche' un valore inviato da Livewire si puo' falsificare.
     */
    private function assertEvent(Venue|Organizer $sponsor, PromotionMode $mode, ?Event $event): void
    {
        if ($event === null) {
            return;
        }

        abort_unless($mode === PromotionMode::Selected
            && SponsorableEvents::belongsTo($event, $sponsor)
            && SponsorableEvents::isSponsorable($event), 403);
    }

    /**
     * Dopo l'approvazione, l'evento scelto con l'ordine entra in evidenza. Se
     * nel frattempo non e' piu' sponsorizzabile (annullato, passato) non si
     * blocca l'approvazione: l'abilitazione resta e il committente ne sceglie
     * un altro dal pannello.
     */
    private function attachChosenEvent(SponsorshipOrder $order, SponsorshipGrant $grant): void
    {
        $event = $order->event_id !== null ? Event::query()->find($order->event_id) : null;

        if ($event === null || $grant->mode !== PromotionMode::Selected || ! SponsorableEvents::isSponsorable($event)) {
            return;
        }

        $this->campaigns->attach($grant, $event);
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
