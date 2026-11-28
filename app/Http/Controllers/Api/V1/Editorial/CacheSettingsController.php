<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Services\Cache\FrontendCacheConfiguration;
use App\Services\Editorial\EditorialPermissions;
use App\Services\Editorial\EditorialSettings;
use App\Settings\FrontendCacheSettings;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Cache e prestazioni» (R-SY-14), come la pagina del pannello: admin e
 * super admin (`system_manage`). Nell'app (decisione 5) si leggono le
 * impostazioni e si fanno le due azioni operative: svuotare le cache del
 * sito (le stesse di «Pulisci tutte le cache del sito») e verificare Redis
 * con la configurazione salvata. Le impostazioni e le credenziali di Redis
 * si scrivono solo dal sito; la password non esce mai.
 */
final class CacheSettingsController extends Controller
{
    public function show(Request $request, EditorialSettings $settings, EditorialPermissions $permissions): JsonResponse
    {
        $this->authorizeDesk($permissions);

        return response()->json(['data' => $settings->cache($this->serverSoftware($request))]);
    }

    /** Invalida pagine pubbliche, calendari, sitemap e filtri e le viste compilate; non tocca sessioni, prenotazioni, lock. */
    public function clear(FrontendCacheConfiguration $cache, EditorialPermissions $permissions): JsonResponse
    {
        $this->authorizeDesk($permissions);
        $cache->clear();

        return response()->json(['data' => ['cleared' => true], 'meta' => ['message' => __('cache_settings.cleared')]]);
    }

    /** Connessione, autenticazione, scrittura e lettura su Redis con i dati salvati, senza attivarlo. */
    public function verify(FrontendCacheConfiguration $cache, EditorialPermissions $permissions): JsonResponse
    {
        $this->authorizeDesk($permissions);
        $ok = $cache->verifyRedis(app(FrontendCacheSettings::class)->toArray());

        return response()->json([
            'data' => ['ok' => $ok],
            'meta' => ['message' => __($ok ? 'cache_settings.redis_ok' : 'cache_settings.redis_failed')],
        ]);
    }

    private function authorizeDesk(EditorialPermissions $permissions): void
    {
        abort_unless($permissions->allows(EditorialDesk::require()->user, 'system_manage'), 403);
    }

    private function serverSoftware(Request $request): ?string
    {
        $software = $request->server('SERVER_SOFTWARE');

        return is_string($software) ? $software : null;
    }
}
