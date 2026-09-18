<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireConfirmedAccount
{
    /*
     * Oltre a profilo, conferma e diritti sull'account, chi non ha confermato
     * deve poter spegnere ciò che già riceve e rivedere le proprie preferenze:
     * togliere un push, un dispositivo o il calendario Google, leggere lo stato,
     * scegliere gli interessi (dal sito come dall'app).
     * Ciò che crea un nuovo canale (push.store, devices.store, connect) resta
     * dietro la conferma. Elenco esplicito, senza caratteri jolly nuovi.
     */
    private const ALLOWED = [
        'account.profile*', 'account.logout', 'verification.*', 'account.verification.*',
        'account.push.destroy', 'account.notifications', 'account.notifications.interests', 'account.notifications.interests.update',
        'google-calendar.disconnect',
        'api.v1.me.show', 'api.v1.me.update', 'api.v1.me.destroy', 'api.v1.me.export', 'api.v1.me.sessions.*',
        'api.v1.me.devices.index', 'api.v1.me.devices.destroy', 'api.v1.me.preferences.show', 'api.v1.me.preferences.update',
        'api.v1.me.calendar.google', 'api.v1.me.interests.show', 'api.v1.me.interests.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $user->hasVerifiedEmail() && ! $request->routeIs(...self::ALLOWED)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => ['code' => 'EMAIL_VERIFICATION_REQUIRED', 'message' => __('community.email_required'), 'fields' => (object) []]], 403);
            }

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
