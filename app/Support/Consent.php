<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\ConsentState;
use App\Enums\ConsentCategory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * La scelta sul consenso valida per la richiesta in corso (§16).
 *
 * ## Preventivo vuol dire questo
 *
 * Finché `state()` è `null`, `allows()` risponde `false` a tutto tranne che
 * alla categoria necessaria. Non esiste un valore predefinito «acceso in attesa
 * di rifiuto»: è la differenza fra un consenso preventivo e un banner che
 * informa a cose fatte.
 *
 * ## La memoria appartiene alla richiesta che l'ha prodotta
 *
 * Il servizio conserva la scelta appena registrata (`remember()`), così la
 * pagina che risponde al modulo del banner si comporta già secondo la scelta
 * nuova invece che secondo il cookie vecchio, che il browser non ha ancora
 * rimandato indietro.
 *
 * Quella memoria è però legata **all'oggetto richiesta** che l'ha generata, e
 * non alla vita dell'istanza. Un servizio registrato come `scoped` sopravvive a
 * più richieste in ogni contesto in cui il processo non muore fra l'una e
 * l'altra — i test HTTP, e un giorno un server persistente: senza questo
 * legame, chi accetta lascerebbe la propria scelta addosso al visitatore
 * successivo, che è il modo più grave possibile di sbagliare un consenso.
 */
final class Consent
{
    private ?ConsentState $state = null;

    private ?Request $resolvedFor = null;

    public function __construct(private readonly Container $container) {}

    public function state(): ?ConsentState
    {
        $request = $this->request();

        if ($this->resolvedFor !== $request) {
            $raw = $request->cookie($this->cookieName());

            $this->state = ConsentState::decode(is_string($raw) ? $raw : null, $this->version());
            $this->resolvedFor = $request;
        }

        return $this->state;
    }

    /**
     * La scelta appena espressa, perché valga già per la risposta in corso.
     */
    public function remember(ConsentState $state): void
    {
        $this->state = $state;
        $this->resolvedFor = $this->request();
    }

    /**
     * Una scelta è stata espressa? È la sola domanda che decide se il banner
     * compare: chi ha rifiutato ha scelto quanto chi ha accettato, e non deve
     * ritrovarselo davanti a ogni pagina.
     */
    public function decided(): bool
    {
        return $this->state() !== null;
    }

    public function allows(ConsentCategory $category): bool
    {
        if ($category === ConsentCategory::Necessary) {
            return true;
        }

        return $this->state()?->allows($category) ?? false;
    }

    /**
     * Ciò che distingue due versioni della stessa pagina in cache: la scelta
     * cambia l'HTML (il banner, lo script delle statistiche), quindi due
     * browser con scelte diverse non possono ricevere la stessa copia
     * (`App\Http\Middleware\CachePage`).
     *
     * L'identificativo del browser resta fuori di proposito: entrerebbe nella
     * chiave e darebbe a ogni visitatore una copia sua, cioè nessuna cache.
     */
    public function fingerprint(): string
    {
        $state = $this->state();

        if ($state === null) {
            return 'nd';
        }

        $flags = '';

        foreach (ConsentCategory::optional() as $category) {
            $flags .= $state->allows($category) ? '1' : '0';
        }

        return $flags;
    }

    /**
     * Il cookie da mandare al browser. `httpOnly` perché nessuno script ha
     * bisogno di leggerlo — la decisione la applica il server — e `SameSite
     * Lax` perché non deve viaggiare su richieste partite da altri siti.
     */
    public function cookie(ConsentState $state): Cookie
    {
        return cookie(
            name: $this->cookieName(),
            value: $state->encode(),
            minutes: config()->integer('consent.lifetime_days') * 24 * 60,
            secure: null,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * Il cookie che cancella la scelta: serve a chi la revoca dalla Cookie
     * Policy. Un rifiuto è una scelta e resta scritta nel cookie; «voglio che
     * me lo richiediate» è un'altra cosa, e toglie il cookie di mezzo.
     */
    public function forget(): Cookie
    {
        return cookie()->forget($this->cookieName());
    }

    public function version(): string
    {
        return (string) config('consent.version');
    }

    public function cookieName(): string
    {
        return (string) config('consent.cookie');
    }

    private function request(): Request
    {
        return $this->container->make('request');
    }
}
