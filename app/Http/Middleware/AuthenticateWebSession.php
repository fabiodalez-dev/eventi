<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * `AuthenticateSession` di Laravel, agganciato **al guard `web`** invece che a
 * quello predefinito.
 *
 * ## A cosa serve il middleware
 *
 * Scrive nella sessione l'impronta della password e la confronta a ogni
 * richiesta: quando la password cambia, le sessioni aperte con quella vecchia
 * cadono. È ciò che fa sì che reimpostare la password **chiuda** la sessione
 * del ladro, invece di lasciarlo dentro col suo cookie fino alla scadenza
 * naturale — che era il comportamento di prima, e rendeva la reimpostazione un
 * gesto che non riprendeva il controllo di niente.
 *
 * ## Perché non si usa la classe di Laravel così com'è
 *
 * Quella risolve il guard **predefinito** (`$this->auth->guard()`), e il
 * predefinito è uno stato globale che qualcuno può spostare durante la
 * richiesta. Con Sanctum in casa succede: `Sanctum::actingAs()` chiama
 * `shouldUse('sanctum')`, e da quel momento `guard()` restituisce un
 * `RequestGuard` — che non ha `viaRemember()`, quindi il middleware non
 * fallisce con un 403, fallisce con un `BadMethodCallException`.
 *
 * L'ha trovato `SavedAndFollowTest::synchronizes independent dates across web
 * and authenticated API`, che in un solo scenario tocca prima il sito e poi
 * l'API: uno scenario legittimo, e l'unico posto in cui i due guard si
 * incontrano. In produzione il predefinito su una rotta web è sempre `web`,
 * quindi il guasto si sarebbe visto solo nei test — ma un middleware di
 * sicurezza che dipende da uno stato globale mutabile è una difesa che
 * qualcuno può spegnere per sbaglio, e non vale la pena tenerla così.
 *
 * Qui il guard è dichiarato, e dove la sessione non è in gioco il middleware
 * si fa da parte invece di indovinare.
 */
final class AuthenticateWebSession extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        /* Nessuna sessione da proteggere: una richiesta autenticata con un
           token non ha un'impronta in sessione da confrontare. */
        if (! $this->guard() instanceof StatefulGuard) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * Il guard della sessione del sito, dichiarato invece di dedotto.
     */
    protected function guard(): Guard
    {
        return $this->auth->guard('web');
    }
}
