<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** HTML contains session-specific CSRF tokens, even for anonymous visitors. */
final class PreventSharedResponseCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
