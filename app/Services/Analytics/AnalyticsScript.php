<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsProvider;
use App\Enums\ConsentCategory;
use App\Support\Consent;

/**
 * Decide se una pagina porta lo script delle statistiche, e quale (§16:
 * «Analytics privacy-first»).
 *
 * Due domande distinte, che non vanno confuse:
 *
 * - `configured()` — esiste uno strumento di statistiche? Dipende soltanto dal
 *   file `.env`. Se è `false` **nessuna richiesta parte verso alcun terzo**:
 *   non c'è uno script spento, non c'è un tag vuoto, non c'è niente.
 * - `enabled()` — va caricato *adesso*? È `configured()` più il consenso di chi
 *   sta guardando (§16: consenso preventivo).
 *
 * Il banner ha bisogno della prima per dire la verità su cosa il sito usa; la
 * vista ha bisogno della seconda.
 */
final class AnalyticsScript
{
    public function __construct(private readonly Consent $consent) {}

    public function provider(): ?AnalyticsProvider
    {
        $provider = trim((string) config('analytics.provider'));

        return $provider === '' ? null : AnalyticsProvider::tryFrom($provider);
    }

    /**
     * Uno strumento è configurato quando tutte e tre le variabili hanno un
     * valore e l'indirizzo dello script è `https`. Mancarne una sola spegne
     * tutto: uno script senza dominio conterebbe le visite di nessuno, e un
     * dominio senza script non conterebbe niente — in entrambi i casi il
     * risultato utile è zero e il costo è una richiesta a un terzo.
     */
    public function configured(): bool
    {
        return $this->provider() !== null
            && $this->domain() !== ''
            && str_starts_with($this->src(), 'https://');
    }

    public function enabled(): bool
    {
        return $this->configured() && $this->consent->allows(ConsentCategory::Statistics);
    }

    public function domain(): string
    {
        return trim((string) config('analytics.domain'));
    }

    public function src(): string
    {
        return trim((string) config('analytics.src'));
    }

    /**
     * Gli attributi dello `<script>`, già decisi: la vista li stampa e basta.
     * `defer` perché un contatore non deve mai stare fra il visitatore e il
     * disegno della pagina.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $provider = $this->provider();

        if ($provider === null) {
            return [];
        }

        return [
            'src' => $this->src(),
            $provider->siteAttribute() => $this->domain(),
        ];
    }
}
