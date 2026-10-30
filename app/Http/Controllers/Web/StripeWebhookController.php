<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SponsorshipOrder;
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use App\Services\Sponsorship\Payment\StripeWebhookSignature;
use App\Services\Sponsorship\SponsorshipOrders;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Checkout\Session;

/**
 * Il webhook di Stripe: l'unica via con cui un ordine diventa pagato senza
 * che nessuno torni sul sito.
 *
 * **400 solo sulla firma.** Per tutto il resto — eventi che non ci riguardano,
 * sessioni che non conosciamo — si risponde 200: Stripe ritenta gli errori
 * per giorni, e un ordine che non esiste non esistera' nemmeno al terzo
 * tentativo.
 *
 * **L'ordine si ritrova anche da una sessione superata.** Riprovare il
 * pagamento apre una sessione nuova e chiude la vecchia, ma fra le due c'e'
 * una finestra: se viene pagata la vecchia, l'ordine si risolve dai metadati
 * che il gateway stesso ci ha messo, con importo e valuta che devono tornare.
 * Cosa farne — pagato, o rimborsato se non lo aspettava piu' — lo decide
 * `SponsorshipOrders::markPaid`.
 */
class StripeWebhookController extends Controller
{
    /** @var list<string> */
    private const PAID_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    public function __invoke(Request $request, StripeWebhookSignature $signature, SponsorshipOrders $orders): Response
    {
        try {
            $event = $signature->event($request->getContent(), (string) $request->header(StripeWebhookSignature::HEADER));
        } catch (PaymentGatewayException) {
            return response('', Response::HTTP_BAD_REQUEST);
        }

        if (! in_array($event->type, self::PAID_EVENTS, true)) {
            return response('', Response::HTTP_OK);
        }

        $session = $event->data->object;

        if (! $session instanceof Session || $session->payment_status !== 'paid') {
            return response('', Response::HTTP_OK);
        }

        $order = SponsorshipOrder::query()->where('checkout_session_id', (string) $session->id)->first() ?? $this->orderFromMetadata($session);
        $intent = is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? null);

        if ($order === null || $intent === null) {
            return response('', Response::HTTP_OK);
        }

        $orders->markPaid($order, $intent, CarbonImmutable::createFromTimestampUTC((int) $event->created));

        return response('', Response::HTTP_OK);
    }

    private function orderFromMetadata(Session $session): ?SponsorshipOrder
    {
        /* Accesso come array: una proprieta' assente su un oggetto Stripe
           scrive un avviso nel log, `??` sull'array no. */
        $id = $session['metadata']['sponsorship_order_id'] ?? $session['client_reference_id'] ?? null;

        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $order = SponsorshipOrder::query()->find((int) $id);

        return $order !== null
            && (int) ($session['amount_total'] ?? 0) === $order->amount_cents
            && strtolower((string) ($session['currency'] ?? '')) === strtolower((string) $order->currency)
                ? $order
                : null;
    }
}
