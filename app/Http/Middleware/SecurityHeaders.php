<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le intestazioni di sicurezza di §16, dichiarate nel repository.
 *
 * In produzione tre di queste arrivavano dall'hosting — `nosniff`,
 * `X-Frame-Options`, `X-XSS-Protection` — e nel codice non ce n'era traccia.
 * Funzionavano, ma nessun test le vedeva e nessuno le avrebbe rimpiante alla
 * prima migrazione. Adesso viaggiano con l'applicazione, e sull'hosting
 * attuale semplicemente coincidono con quelle già presenti.
 *
 * ## Non sovrascrive niente
 *
 * Sta **in fondo** alla catena globale, quindi vede risposte a cui controller
 * e middleware di rotta hanno già messo mano, e ogni intestazione si scrive
 * solo se manca. Due casi in cui la differenza conta:
 *
 * - il **riquadro incorporabile** (`/widget/{venue}`) dichiara la propria
 *   `Content-Security-Policy` con `frame-ancestors *`, perché esiste per
 *   essere messo nella pagina di qualcun altro. Se qui gli si aggiungesse
 *   `frame-ancestors 'self'` smetterebbe di funzionare — e siccome due
 *   intestazioni CSP si applicano **entrambe**, nella loro intersezione, non
 *   basterebbe nemmeno che la sua arrivi prima;
 * - `TicketingPrivacy` mette `Referrer-Policy: no-referrer` sulle pagine delle
 *   prenotazioni, che è più stretto del valore generale e deve restare.
 *
 * ## Perché `X-Frame-Options` c'è ancora
 *
 * `frame-ancestors` della CSP lo rende superfluo dove la CSP è compresa, e
 * dove entrambe sono presenti la specifica impone di **ignorare**
 * `X-Frame-Options`. Costa una riga e copre i browser che la CSP non la
 * leggono: si tolga il giorno in cui quelli non contano più.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $csp = app(Csp::class);

        /*
         * Il nonce si genera **all'andata**, non al ritorno.
         *
         * Questo middleware è globale, quindi la riga qui sotto gira prima di
         * qualunque rotta, controller e vista: è l'unico momento in cui si può
         * garantire che Vite e Livewire lo ricevano prima di stampare i propri
         * tag `<script>`. Generarlo al ritorno, o lasciarlo nascere alla prima
         * vista che lo chiede, lo renderebbe dipendente dall'ordine in cui
         * capita di leggere l'intestazione della pagina.
         */
        if ($csp->appliesTo($request)) {
            $csp->nonce();
        }

        $response = $next($request);
        $headers = $response->headers;

        if (! $headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        $referrer = config()->string('security.referrer_policy');

        if ($referrer !== '' && ! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', $referrer);
        }

        $permissions = config()->string('security.permissions_policy');

        if ($permissions !== '' && ! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', $permissions);
        }

        /*
         * La CSP e la vecchia `X-Frame-Options` si decidono insieme: chi ha
         * già dichiarato una politica propria — il riquadro incorporabile — ha
         * anche già deciso da chi accetta di farsi incorniciare.
         */
        if (! $headers->has('Content-Security-Policy')) {
            /*
             * La `script-src` si decide da **questa** richiesta, non dal fatto
             * che un nonce esista già.
             *
             * Non è pignoleria: fuori da una richiesta HTTP vera — nei test,
             * sotto Octane, in un comando che disegna una vista — il
             * contenitore può essere lo stesso di prima, e un nonce nato per
             * la pagina pubblica risulterebbe «emesso» anche mentre si
             * risponde al pannello. La condizione giusta è l'indirizzo che si
             * sta servendo.
             */
            $direttive = array_filter([
                config()->string('security.csp'),
                $csp->appliesTo($request) ? $csp->scriptSrc() : '',
            ], static fn (string $direttiva): bool => $direttiva !== '');

            if ($direttive !== []) {
                $headers->set('Content-Security-Policy', implode('; ', $direttive));
            }

            if (! $headers->has('X-Frame-Options')) {
                $headers->set('X-Frame-Options', 'SAMEORIGIN');
            }
        }

        if ($request->isSecure() && config()->boolean('security.hsts.enabled') && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', $this->hsts());
        }

        return $response;
    }

    /**
     * `max-age=31536000; includeSubDomains` — senza `preload`, che è una
     * decisione con mesi di ripensamento e non un valore predefinito.
     */
    private function hsts(): string
    {
        $value = 'max-age='.config()->integer('security.hsts.max_age');

        if (config()->boolean('security.hsts.include_subdomains')) {
            $value .= '; includeSubDomains';
        }

        if (config()->boolean('security.hsts.preload')) {
            $value .= '; preload';
        }

        return $value;
    }
}
