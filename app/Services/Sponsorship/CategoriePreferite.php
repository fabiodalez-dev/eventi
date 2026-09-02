<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\SavedEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Le categorie che un utente ha mostrato di preferire **salvando eventi**.
 *
 * È l'unico segnale che questa classe legge, ed è una scelta, non un limite:
 * il salvataggio è un gesto esplicito e reversibile — l'utente lo vede nella
 * sua lista e può toglierlo — mentre cronologia e cookie racconterebbero cose
 * che non ha mai deciso di dire. Se un giorno servisse di più, il posto per
 * discuterne è qui.
 *
 * Il risultato sta in cache per utente (`sponsorship_affinity_ttl_minutes`):
 * le collocazioni sono più d'una per pagina e le pagine degli autenticati non
 * passano dalla full-page cache, quindi senza questa copia ogni richiesta
 * pagherebbe la stessa join sulla wishlist due o tre volte.
 */
final class CategoriePreferite
{
    /**
     * Gli id delle categorie preferite, vuoto se l'utente non ha salvataggi
     * recenti.
     *
     * @return list<int>
     */
    public function dellUtente(User $utente): array
    {
        /** @var list<int> $categorie */
        $categorie = Cache::remember(
            sprintf('sponsorship:categorie-preferite:%d', (int) $utente->getKey()),
            now()->addMinutes(config()->integer('eventi.sponsorship_affinity_ttl_minutes')),
            fn (): array => $this->daiSalvataggiRecenti($utente),
        );

        return $categorie;
    }

    /**
     * Le categorie distinte degli eventi salvati nella finestra
     * (`sponsorship_affinity_window_days`).
     *
     * La finestra guarda a **quando l'utente ha salvato**, non a quando
     * l'evento si svolge: è il gesto che dice cosa gli interessa adesso, e un
     * biglietto preso oggi per un concerto fra sei mesi è un segnale attuale.
     *
     * @return list<int>
     */
    private function daiSalvataggiRecenti(User $utente): array
    {
        return SavedEvent::query()
            ->where('saved_events.user_id', $utente->getKey())
            ->where('saved_events.created_at', '>=', now()->subDays(config()->integer('eventi.sponsorship_affinity_window_days')))
            ->join('event_occurrences', 'event_occurrences.id', '=', 'saved_events.occurrence_id')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->distinct()
            ->pluck('events.category_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
