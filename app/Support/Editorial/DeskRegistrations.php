<?php

declare(strict_types=1);

namespace App\Support\Editorial;

use App\Filament\Admin\Resources\Users\RegistrationState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Nuove iscrizioni» nel riepilogo della redazione (R-DB-05), per il pannello
 * (`NewRegistrationsWidget`) e per l'app.
 *
 * Le ultime persone iscritte in ordine d'arrivo, quante negli ultimi
 * `RegistrationState::RECENT_DAYS` giorni e quante non hanno ancora
 * completato l'iscrizione. Non dipende dalla città: gli account sono della
 * piattaforma.
 *
 * Sta in `App\Support` e non in `App\Services` per lo stesso motivo di
 * `EditorialBadges`: lo stato dell'iscrizione lo definisce `RegistrationState`,
 * che vive ancora accanto alla risorsa degli utenti del pannello.
 */
final class DeskRegistrations
{
    /** Quante iscrizioni mostra il riquadro: il resto è nell'elenco. */
    public const LIMIT = 5;

    /** Le iscrizioni degli ultimi giorni. */
    public function recent(): int
    {
        return RegistrationState::scopeRecent(User::query())->count();
    }

    /** Gli account che non hanno completato l'iscrizione (la scheda «In attesa di conferma»). */
    public function pending(): int
    {
        return RegistrationState::scopeIncomplete(User::query())->count();
    }

    /**
     * Le ultime iscrizioni, dalla più recente.
     *
     * @return Builder<User>
     */
    public function latest(): Builder
    {
        return User::query()->latest('created_at')->latest('id')->limit(self::LIMIT);
    }

    /** La frase sotto il titolo: «3 iscrizioni negli ultimi 7 giorni · una in attesa di conferma». */
    public function description(int $recent, int $pending): string
    {
        return trans_choice('users.widget.description', $recent, ['count' => $recent, 'days' => RegistrationState::RECENT_DAYS])
            .' · '.trans_choice('users.widget.pending', $pending, ['count' => $pending]);
    }

    /**
     * Lo stato dell'iscrizione di una persona, con etichetta, spiegazione e colore del pannello.
     *
     * @return array{value: string, label: string, description: string, tone: string}
     */
    public function state(User $user): array
    {
        $state = RegistrationState::for($user);

        return ['value' => $state->value, 'label' => $state->getLabel(), 'description' => $state->getDescription(), 'tone' => $state->getColor()];
    }
}
