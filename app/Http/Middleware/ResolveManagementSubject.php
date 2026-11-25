<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Support\Management\ManagementSubject;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * La porta delle rotte `/api/v1/management/venues/{venue}/…` e
 * `/api/v1/management/organizers/{organizer}/…`.
 *
 * Fa per l'API quello che `IdentifyTenant` fa per i pannelli: trova il
 * soggetto per id, chiede `User::canAccessTenant` (appartenenza accettata,
 * nessun provvedimento sul locale, organizzazione attiva) e risponde 404 se
 * dice di no, così un id scritto a mano non rivela nemmeno che il soggetto
 * esiste. Poi lega il `ManagementSubject` alla richiesta.
 *
 * È il controllo che le policy da sole non fanno: `EventPolicy` e
 * `VenuePolicy` guardano l'appartenenza al locale ma non se il locale è
 * sospeso, e senza questa porta il membro di un locale sospeso potrebbe
 * scrivere dall'app ciò che il pannello non gli lascia più toccare (D52).
 * Le policy restano il secondo controllo, su ogni azione.
 *
 * Il parametro del soggetto esce dalla rotta dopo l'uso: i controller lo
 * leggono da `ManagementSubject`, e i parametri rimasti (`{event}`, `{media}`)
 * arrivano ai metodi senza scorrere di posizione.
 */
final class ResolveManagementSubject
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $user = $request->user();
        abort_unless($route instanceof Route, 404);

        [$parameter, $model] = match (true) {
            $route->hasParameter('venue') => ['venue', Venue::class],
            $route->hasParameter('organizer') => ['organizer', Organizer::class],
            default => [null, null],
        };
        abort_if($parameter === null || $model === null, 404);

        $id = $route->parameter($parameter);
        $subject = is_string($id) && ctype_digit($id) ? $model::query()->find((int) $id) : null;

        abort_unless($user instanceof User && ($subject instanceof Venue || $subject instanceof Organizer) && $user->canAccessTenant($subject), 404);

        ManagementSubject::bind($request, new ManagementSubject($subject, $user));
        $route->forgetParameter($parameter);

        return $next($request);
    }
}
