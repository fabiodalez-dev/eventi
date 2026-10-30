<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Payment;

/**
 * La pagina di pagamento non esiste per l'account Stripe di oggi: nata con
 * le chiavi di test prima di passare a quelle live, o su un altro account.
 *
 * Non e' un guasto passeggero e riprovare non serve: chi chiama la tratta
 * come una pagina che non c'e' piu', non da chiudere ne' da rileggere.
 */
final class PaymentSessionMissing extends PaymentGatewayException {}
