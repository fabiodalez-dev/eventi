<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ImportSource;
use App\Models\User;
use App\Models\Venue;
use App\Policies\Concerns\ScopesToVenueMembership;

/**
 * Chi può toccare una sorgente di import (§14.2).
 *
 * Ci sono due tipi di sorgente, e la differenza è tutta nel `venue_id`:
 *
 * - **con locale**: è il calendario che il locale stesso dichiara, e lo
 *   governa il suo **referente** dalla pagina «Collega il tuo calendario» di
 *   `/gestione`. Mai il collaboratore: collegare un calendario decide che cosa
 *   il locale pubblica *da qui in avanti e senza che nessuno lo riguardi*, ed
 *   è la stessa categoria di decisione degli inviti — che §3 riserva a chi
 *   risponde del locale;
 * - **senza locale**: il calendario del Comune, un teatro non ancora iscritto.
 *   Non ha un referente per definizione, quindi resta della redazione.
 *
 * La verifica del locale è quella di ogni altra Policy — `canActOnVenue()` —
 * e vale anche quando l'identificativo arriva scritto a mano nell'indirizzo
 * (§18 scenario F): il referente del locale A non vede né esegue né scollega
 * la sorgente del locale B.
 */
class ImportSourcePolicy
{
    use ScopesToVenueMembership;

    /**
     * L'elenco completo delle sorgenti è quello di `/admin`: non esiste una
     * lista «di tutte le sorgenti» filtrata per locale, perché nel pannello
     * del locale la sorgente è una sola ed è la propria.
     */
    public function viewAny(User $user): bool
    {
        return $this->isGlobalStaff($user) && $user->can(Permission::ManageImportSources->value);
    }

    public function view(User $user, ImportSource $importSource): bool
    {
        return $this->canActOnSource($user, $importSource);
    }

    /**
     * Il locale è facoltativo per la stessa ragione di `EventPolicy::create()`
     * (D24, punto 2): Filament chiede il permesso di creare prima di sapere
     * per chi. Senza locale la domanda è «può dichiarare una sorgente in
     * assoluto?», e la risposta resta alla redazione.
     */
    public function create(User $user, ?Venue $venue = null): bool
    {
        if ($venue === null) {
            return $this->isGlobalStaff($user) && $user->can(Permission::ManageImportSources->value);
        }

        return $this->canActOnVenue($user, $venue->id, Permission::ManageImportSources->value, requireOwner: true);
    }

    public function update(User $user, ImportSource $importSource): bool
    {
        return $this->canActOnSource($user, $importSource);
    }

    public function delete(User $user, ImportSource $importSource): bool
    {
        return $this->canActOnSource($user, $importSource);
    }

    /**
     * Eseguire adesso e guardare l'anteprima sono la stessa autorizzazione di
     * ogni altra modifica: l'anteprima non scrive niente, ma **legge** un
     * calendario altrui e ne mostra i titoli, e sarebbe una fuga di dati come
     * un'altra.
     */
    public function run(User $user, ImportSource $importSource): bool
    {
        return $this->canActOnSource($user, $importSource);
    }

    private function canActOnSource(User $user, ImportSource $importSource): bool
    {
        if ($importSource->venue_id === null) {
            return $this->isGlobalStaff($user) && $user->can(Permission::ManageImportSources->value);
        }

        return $this->canActOnVenue(
            $user,
            (int) $importSource->venue_id,
            Permission::ManageImportSources->value,
            requireOwner: true,
        );
    }
}
