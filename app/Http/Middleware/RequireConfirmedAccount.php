<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireConfirmedAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->hasVerifiedEmail()
            && ! $request->routeIs('account.profile*', 'account.logout', 'verification.*', 'account.verification.*', 'api.v1.me.show', 'api.v1.me.update', 'api.v1.me.destroy', 'api.v1.me.export', 'api.v1.me.sessions.*')) {
            if ($request->expectsJson()) {
                return response()->json(['error' => ['code' => 'EMAIL_VERIFICATION_REQUIRED', 'message' => __('community.email_required'), 'fields' => (object) []]], 403);
            }

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
