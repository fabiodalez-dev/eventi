<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Il segnale che fa aggiornare il server.
 *
 * **Perché esiste.** L'integrazione continua non riesce a spingere i file qui:
 * la porta 22 di questo host non accetta connessioni da fuori e ogni tentativo
 * muore in `Connection timed out`. Le connessioni in uscita invece passano —
 * il server raggiunge GitHub — quindi si ribalta il verso: da fuori arriva
 * soltanto un segnale, e a tirare il codice è il server.
 *
 * **Cosa può fare chi ha il segreto.** Portare il server all'ultimo commit di
 * un ramo del repository e riallineare database, permessi e cache. Nient'altro:
 * non si passa un comando, non si passa un percorso, e il ramo è validato dal
 * comando che lo riceve. Il segreto è lo stesso di `/stato` — se non è
 * configurato, questa rotta non esiste affatto (404, non 403: un endpoint che
 * si annuncia è un endpoint che qualcuno prova).
 *
 * **Uno alla volta.** Due rilasci sovrapposti si contendono `composer install`
 * e le migrazioni: il lucchetto li mette in fila, e chi arriva secondo riceve
 * un 409 invece di aspettare — un rilascio che aspetta dieci minuti non è mai
 * ciò che si voleva.
 */
final class DeployController extends Controller
{
    /** Quanto può durare al massimo un rilascio prima che il lucchetto scada. */
    private const LOCK_SECONDS = 900;

    public function __invoke(Request $request): JsonResponse
    {
        $lock = Cache::lock('deploy:pull', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return response()->json([
                'stato' => 'occupato',
                'messaggio' => 'Un rilascio è già in corso.',
            ], 409);
        }

        try {
            $branch = (string) ($request->input('branch') ?? 'main');

            $esito = Artisan::call('deploy:pull', ['--branch' => $branch]);
            $output = trim(Artisan::output());

            /* Il registro tiene traccia di ogni rilascio: quando il sito
               cambia comportamento senza che nessuno abbia toccato niente, la
               prima domanda è «cosa è stato rilasciato e quando». */
            Log::log($esito === 0 ? 'info' : 'error', 'Rilascio richiesto da remoto.', [
                'ramo' => $branch,
                'esito' => $esito,
                'output' => $output,
            ]);

            return response()->json([
                'stato' => $esito === 0 ? 'rilasciato' : 'fallito',
                'output' => $output,
            ], $esito === 0 ? 200 : 500);
        } finally {
            $lock->release();
        }
    }
}
