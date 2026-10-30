<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use RuntimeException;

/**
 * Un ordine in attesa che non si puo' piu' pagare cosi' com'e': la tariffa e'
 * stata tolta o cambiata, oppure il committente non e' piu' operativo.
 * L'ordine e' gia' annullato quando arriva; chi lo voleva ne fa uno nuovo.
 */
final class OrderNoLongerPayable extends RuntimeException {}
