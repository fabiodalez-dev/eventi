<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\InstallerStep;
use App\Services\Installer\InstallerState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'anti-salto (D42, punto 3): chi chiede il passo N senza aver completato
 * N-1 torna al primo incompleto.
 *
 * Sta in un middleware e non nel controller perché una Form Request valida
 * **prima** che il metodo del controller cominci: un controllo scritto lì
 * arriverebbe dopo, e chi manda un POST al passo della città senza aver
 * scelto un database riceverebbe sette errori di validazione invece del
 * rimando al passo che gli manca davvero.
 *
 * Il passo si legge dal secondo segmento dell'indirizzo e non dal nome della
 * rotta: i POST non ne hanno uno, e sono proprio quelli che contano.
 */
class InstallerStepOrder
{
    public function __construct(private readonly InstallerState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        $step = InstallerStep::tryFrom($request->segment(2) ?? '');

        if ($step === null || $this->state->canOpen($step)) {
            return $next($request);
        }

        return redirect()->to($this->state->firstIncomplete()->url());
    }
}
