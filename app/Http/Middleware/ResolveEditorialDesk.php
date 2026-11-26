<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\City;
use App\Models\User;
use App\Support\CurrentCity;
use App\Support\Editorial\EditorialDesk;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La porta di tutte le rotte `/api/v1/editorial/…`.
 *
 * 1. L'interruttore d'emergenza (decisione 13): con `API_EDITORIAL_ENABLED`
 *    spento ogni rotta della redazione risponde 404, senza rilasciare niente.
 * 2. Solo la redazione: chi non è `isEditorialStaff()` (moderatore, admin,
 *    super admin) riceve 404, come un indirizzo che non esiste. È la stessa
 *    barriera di `canAccessPanel` per `/admin`.
 * 3. La città di lavoro: `?city=<slug>`, anche di una città non ancora
 *    attiva (la redazione le prepara prima di accenderle; `ResolveApiCity`
 *    invece le rifiuta). Senza parametro vale quello che fa il pannello: la
 *    prima città attiva, poi la prima in assoluto. Uno slug che non esiste
 *    risponde 404. La città finisce in `CurrentCity`, da cui la leggono
 *    motore temporale e servizi.
 *
 * Poi lega l'`EditorialDesk` alla richiesta. Le policy restano il secondo
 * controllo, su ogni azione.
 */
final class ResolveEditorialDesk
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config()->boolean('api.features.editorial'), 404);

        $user = $request->user();
        abort_unless($user instanceof User && $user->isEditorialStaff(), 404);

        $slug = $request->query('city');
        $city = null;
        if (is_string($slug) && trim($slug) !== '') {
            $city = City::query()->where('slug', trim($slug))->first();
            abort_unless($city instanceof City, 404);
            app(CurrentCity::class)->set($city);
        }

        $desk = EditorialDesk::for($user, $city);
        abort_if($desk === null, 404);
        if ($desk->city instanceof City && $city === null) {
            app(CurrentCity::class)->set($desk->city);
        }

        EditorialDesk::bind($request, $desk);

        return $next($request);
    }
}
