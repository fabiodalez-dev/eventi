<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Carpool\CarpoolAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CarpoolPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('carpool.enabled'), 404);
        app(CarpoolAccess::class)->notImpersonating();
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Vary', 'Cookie, Authorization');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
