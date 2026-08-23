<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SavedEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Le date che la persona collegata ha in agenda, per la richiesta in corso.
 *
 * Esiste per una ragione sola: il cuore compare su ogni card, in ogni lista,
 * e deve nascere già acceso su ciò che è salvato. Chiederlo card per card
 * significherebbe una lettura per ciascuna; passarlo come proprietà da ogni
 * controller a ogni vista significherebbe dimenticarsene in metà delle pagine
 * — e un cuore spento su una data salvata non è un dettaglio estetico, è una
 * risposta sbagliata.
 *
 * Una interrogazione per richiesta, e solo se qualcuno chiede. Per chi non è
 * collegato non ne parte nessuna: i suoi salvataggi stanno nel browser, e il
 * server non li conosce (§15.1).
 */
final class CurrentSaves
{
    /** @var array<int, true>|null */
    private ?array $ids = null;

    /**
     * La richiesta a cui appartiene la lettura conservata.
     *
     * Il servizio è registrato come `scoped`, che in un processo per richiesta
     * coincide con «una volta per richiesta»; sotto un runtime persistente —
     * o dentro una prova che ne esegue tre di seguito — non coinciderebbe, e
     * una data appena salvata comparirebbe ancora come non salvata. Il
     * confronto con l'oggetto richiesta corrente è ciò che rende la promessa
     * vera in entrambi i casi.
     */
    private ?Request $request = null;

    public function has(int $occurrenceId): bool
    {
        return isset($this->all()[$occurrenceId]);
    }

    /**
     * @return array<int, true>
     */
    public function all(): array
    {
        $request = request();

        if ($this->ids !== null && $this->request === $request) {
            return $this->ids;
        }

        $this->request = $request;
        $user = Auth::user();

        if (! $user instanceof User) {
            return $this->ids = [];
        }

        $ids = [];

        foreach (SavedEvent::query()->where('user_id', $user->getKey())->pluck('occurrence_id') as $id) {
            $ids[(int) $id] = true;
        }

        return $this->ids = $ids;
    }
}
