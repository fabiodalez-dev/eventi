<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Il numero usa-e-getta con cui gli script di **questa** risposta si fanno
 * riconoscere (§16).
 *
 * ## Che cosa difende
 *
 * Il sito pubblico stampa testo che non ha scritto la redazione: titoli di
 * eventi proposti dal modulo pubblico, nomi di locali, descrizioni arrivate da
 * un calendario importato. Blade sfugge tutto, ed è la difesa vera; la CSP è
 * la seconda, quella che serve il giorno in cui una vista dimentica un `e()` o
 * un `{!! !!}` finisce dove non doveva.
 *
 * Con `script-src` legata a un nonce, uno `<script>` iniettato nel documento
 * **non gira**: non ha il numero, e il numero cambia a ogni risposta. Non c'è
 * `'unsafe-inline'` da nessuna parte — e se ci fosse, i browser moderni lo
 * ignorerebbero proprio perché c'è un nonce.
 *
 * ## Perché `'strict-dynamic'`, e perché anche `'self'`
 *
 * Gli script che contano ne caricano altri: il pacchetto di Vite importa i
 * propri pezzi, l'analitica e gli eventuali script del consenso possono
 * chiamare i propri. Elencarne gli indirizzi a mano significherebbe rompere il
 * sito ogni volta che uno di loro cambia CDN. `'strict-dynamic'` dice invece
 * che uno script già autorizzato può caricarne altri: è la forma che regge nel
 * tempo senza elenchi da aggiornare.
 *
 * `'self'` resta accanto per i browser che `'strict-dynamic'` non lo
 * conoscono: loro leggono nonce e origine, e ottengono comunque una difesa.
 * Chi lo conosce ignora `'self'`, come prevede la specifica.
 *
 * ## Perché non nei pannelli
 *
 * `/admin`, `/gestione` e `/organizza` sono Filament, che disegna markup suo e
 * inietta script propri: una politica stretta lì andrebbe verificata schermata
 * per schermata, e non porterebbe quasi niente — sono pagine dietro
 * autenticazione che non stampano contenuti di sconosciuti. La superficie
 * vera è il sito pubblico, ed è lì che la difesa si mette.
 */
final class Csp
{
    private ?string $nonce = null;

    /**
     * Il nonce di questa risposta, generato alla prima richiesta e poi sempre
     * lo stesso.
     *
     * Generarlo **iscrive anche Vite e Livewire**: sono i due che emettono tag
     * `<script>` per conto loro, e senza il numero i loro script sarebbero i
     * primi a essere bloccati. Va fatto qui e non nelle viste, perché qui
     * accade una volta sola e prima che qualunque vista giri.
     */
    public function nonce(): string
    {
        if ($this->nonce !== null) {
            return $this->nonce;
        }

        $this->nonce = Str::random(40);

        Vite::useCspNonce($this->nonce);
        Livewire::useScriptTagAttributes(['nonce' => $this->nonce]);

        return $this->nonce;
    }

    /**
     * Il nonce già generato, o `null`.
     *
     * Serve a chi deve solo sapere se questa risposta ne ha uno — la cache
     * delle pagine — senza farne nascere uno che nessuno userà.
     */
    public function issued(): ?string
    {
        return $this->nonce;
    }

    /**
     * Questa richiesta riceve una `script-src` stretta?
     */
    public function appliesTo(Request $request): bool
    {
        if (! config()->boolean('security.script_src.enabled')) {
            return false;
        }

        /** @var list<string> $escluse */
        $escluse = config()->array('security.script_src.excluded_paths');

        return ! $request->is(...$escluse);
    }

    /**
     * L'attributo da stampare su un tag `<script>`, o la stringa vuota.
     *
     * Legge il nonce **già emesso** e non ne fa nascere uno: dove la politica
     * non si applica — i pannelli, o un ambiente in cui è spenta — le viste
     * restano identiche a prima, senza attributi appesi che non vogliono dire
     * niente. È ciò che tiene `@cspNonce` innocuo ovunque lo si metta.
     */
    public function attribute(): string
    {
        return $this->nonce === null ? '' : ' nonce="'.e($this->nonce).'"';
    }

    /**
     * La direttiva, con il nonce di questa risposta dentro.
     */
    public function scriptSrc(): string
    {
        return sprintf("script-src 'self' 'nonce-%s' 'strict-dynamic'", $this->nonce());
    }
}
