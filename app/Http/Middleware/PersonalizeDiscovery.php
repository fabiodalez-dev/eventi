<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route-level boundary: public discovery is personal, management and purchases are not. */
final class PersonalizeDiscovery
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('personalize_discovery', true);

        return $next($request);
    }
}
