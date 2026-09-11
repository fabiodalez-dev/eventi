<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TicketingPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Laravel's routing pipeline renders validation errors before returning here.
        // Referrer-Policy removes Referer, so background GETs cannot be trusted as "back".
        if ($request->isMethod('post') && $response instanceof RedirectResponse && in_array('errors', $request->session()->get('_flash.new', []), true)) {
            $date = $request->route('occurrence');
            $booking = $request->route('booking');
            if ($request->routeIs('ticketing.manage.*')) {
                $target = $date ?? $booking?->occurrence;
                $response->setTargetUrl($target ? route('ticketing.manage.show', $target) : route('ticketing.manage.index'));
            } elseif ($request->routeIs('tickets.*')) {
                $response->setTargetUrl($date ? route('tickets.create', $date) : ($booking ? route('tickets.show', $booking) : route('tickets.index')));
            }
        }
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        if ($request->routeIs('ticketing.manage.show')) {
            $policy = config()->string('security.permissions_policy');
            $policy = preg_replace('/(?:^|,\s*)camera=\([^)]*\)/', '', $policy);
            $response->headers->set('Permissions-Policy', trim((string) $policy, ', ').', camera=(self)');
        }

        return $response;
    }
}
