<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\InstallerStep;
use App\Services\Installer\DatabaseInspector;
use App\Services\Installer\InstallerState;
use App\Services\Installer\InstallLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Decide se l'installer può rispondere (D42, punto 2).
 *
 * Il marcatore da solo non basta, in nessuna delle due direzioni, ed è questo
 * che rende il controllo utile invece che decorativo:
 *
 * - **Marcatore presente, database rotto.** Non si risponde «già installato» e
 *   basta: si riprova la connessione e si mostra una *diagnosi* — cosa manca e
 *   cosa fare. Una pagina che dice «è tutto a posto» a chi ha il sito giù è
 *   peggio di nessuna pagina.
 * - **Marcatore assente, database vivo.** È il sito che era in produzione
 *   *prima* che l'installer esistesse: il marcatore lo si scrive da sé e si
 *   risponde 404. Il primo rilascio che porta l'installer non deve mostrare un
 *   wizard di installazione a un sistema installato.
 *
 * Nessun percorso di reinstallazione: reinstallare un sistema vivo è un gesto
 * da RUNBOOK e da SSH, non da endpoint web non autenticato.
 */
class InstallerGate
{
    public function __construct(
        private readonly InstallLock $lock,
        private readonly DatabaseInspector $inspector,
        private readonly InstallerState $state,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->lock->exists()) {
            $problems = $this->diagnose();

            if ($problems !== []) {
                return response()->view('installer.diagnosis', [
                    'title' => __('installer.diagnosis.title'),
                    'problems' => $problems,
                    'lock' => $this->lock->path(),
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            /*
             * L'installazione appena conclusa in questa sessione può ancora
             * leggere la propria schermata finale: il marcatore l'ha già chiusa
             * a chiunque altro, e togliere il riepilogo proprio a chi ha appena
             * finito significherebbe fargli perdere le righe di cron.
             */
            if ($request->routeIs('installer.'.InstallerStep::Done->value)
                && $this->state->isCompleted(InstallerStep::Run)) {
                return $next($request);
            }

            abort(404);
        }

        if (! $this->state->hasStarted() && $this->diagnose() === []) {
            $this->markExistingInstallation();

            abort(404);
        }

        return $next($request);
    }

    /**
     * Cosa non funziona, in ordine di causa: senza connessione non ha senso
     * chiedersi delle tabelle.
     *
     * @return array<int, array{key: string, tables?: string}>
     */
    private function diagnose(): array
    {
        if (! $this->inspector->canConnect()) {
            return [['key' => 'connection']];
        }

        if (! $this->inspector->hasSchema()) {
            return [['key' => 'schema']];
        }

        $missing = $this->inspector->missingTables();

        return $missing === []
            ? []
            : [['key' => 'tables', 'tables' => implode(', ', $missing)]];
    }

    /**
     * Scrive il marcatore per un'installazione che esisteva già, annotando a
     * quale migrazione era arrivato lo schema. Se la scrittura non riesce —
     * `storage/` in sola lettura — non è un motivo per aprire il wizard su un
     * sistema vivo: il 404 arriva comunque.
     */
    private function markExistingInstallation(): void
    {
        try {
            $latest = DB::table('migrations')->orderByDesc('id')->value('migration');

            $this->lock->write(is_string($latest) ? $latest : null);
        } catch (Throwable) {
            // Il 404 che segue non dipende da questa scrittura.
        }
    }
}
