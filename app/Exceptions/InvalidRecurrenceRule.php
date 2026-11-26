<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Una regola di ricorrenza che il generatore delle date non espande: non si
 * legge, ripete più spesso di una volta al giorno o produce troppe date.
 *
 * Estende `InvalidArgumentException` (quella che la libreria RRULE lancia
 * già) perché chi la intercettava prima continui a farlo; il messaggio è
 * quello da mostrare a chi ha scritto la regola.
 */
final class InvalidRecurrenceRule extends InvalidArgumentException {}
