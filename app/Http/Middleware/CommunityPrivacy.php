<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommunityPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('community.enabled'), 404);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Vary', 'Cookie, Authorization');

        return $response;
    }
}
