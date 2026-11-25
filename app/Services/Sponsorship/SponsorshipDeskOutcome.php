<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

/**
 * Come è andato un gesto sulla pagina «Sponsorizzazioni». La pagina del
 * pannello lo traduce in un avviso, l'API in uno stato HTTP: il motivo e la
 * frase sono gli stessi.
 */
enum SponsorshipDeskOutcome: string
{
    /** La pagina di pagamento di Stripe è aperta: l'ordine porta `checkout_url`. */
    case CheckoutOpened = 'checkout';
    /** Richiesta alla redazione inviata, senza pagamento online. */
    case Requested = 'request';
    /** Manca l'evento, la posizione o la durata (o non sono più validi). */
    case Incomplete = 'incomplete';
    /** Oltre i cinque invii l'ora della persona. */
    case SlowDown = 'slow_down';
    /** Il committente ha già il massimo di richieste aperte. */
    case TooManyRequests = 'too_many';
    case CheckoutFailed = 'checkout_failed';
    /** L'ordine risultava già pagato: niente da riaprire o da annullare. */
    case Paid = 'paid';
    case NoLongerPayable = 'no_longer_payable';
    case Cancelled = 'cancelled';
    case CancelFailed = 'cancel_failed';
    case Withdrawn = 'withdrawn';
    /** Al ritorno dal checkout chiuso senza pagare. */
    case ReturnedCancelled = 'returned_cancelled';
    /** Al ritorno dal checkout, il pagamento non risulta ancora. */
    case ReturnedPending = 'returned_pending';

    /** La frase dell'avviso, la stessa nel pannello e nell'app. */
    public function message(): string
    {
        return match ($this) {
            self::CheckoutOpened => __('promotions.page.submit_online_hint'),
            self::Requested => __('promotions.request.sent_body'),
            self::Incomplete => __('promotions.request.incomplete'),
            self::SlowDown => __('promotions.request.slow_down'),
            self::TooManyRequests => __('promotions.request.too_many', ['max' => SponsorshipOrders::MAX_OPEN_REQUESTS]),
            self::CheckoutFailed => __('promotions.orders.checkout_failed'),
            self::Paid => __('promotions.orders.returned_paid'),
            self::NoLongerPayable => __('promotions.orders.no_longer_payable'),
            self::Cancelled => __('promotions.orders.cancelled'),
            self::CancelFailed => __('promotions.orders.cancel_failed'),
            self::Withdrawn => __('promotions.request.withdrawn'),
            self::ReturnedCancelled => __('promotions.orders.returned_cancelled'),
            self::ReturnedPending => __('promotions.orders.returned_pending'),
        };
    }
}
