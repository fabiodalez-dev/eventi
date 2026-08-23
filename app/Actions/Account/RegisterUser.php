<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * La nascita di un account (§15.2), una sola per sito e API.
 *
 * Profilo minimo e nient'altro: nome (facoltativo), email, fuso, lingua (§16).
 * Fuso e lingua non si scrivono qui — la tabella ha già i propri valori
 * predefiniti, che sono quelli della città servita; copiarci sopra
 * `app.timezone` registrerebbe ogni nuovo utente su UTC.
 *
 * La verifica dell'email parte subito perché è la condizione di **qualunque**
 * invio successivo (§15.2): senza, un account resterebbe per sempre capace di
 * salvare e incapace di ricevere, senza sapere perché.
 */
final class RegisterUser
{
    /**
     * @param  array{name?: string|null, email: string, password: string, marketing_opt_in?: bool}  $data
     */
    public function __invoke(array $data): User
    {
        $user = new User([
            'name' => $this->name($data['name'] ?? null),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        /*
         * Il consenso marketing è giuridicamente distinto dalle notifiche
         * transazionali (§15.9): ha una data propria, e senza spunta esplicita
         * quella data non si scrive.
         */
        if (($data['marketing_opt_in'] ?? false) === true) {
            $user->marketing_opt_in_at = Carbon::now();
        }

        $user->save();
        $user->assignRole(UserRole::User->value);

        /*
         * Rileggere dopo il salvataggio non è superfluo: fuso e lingua li
         * scrive il database con i propri valori predefiniti, e l'oggetto in
         * memoria non li conosce finché non li rilegge.
         */
        $user->refresh();
        $user->sendEmailVerificationNotification();

        return $user;
    }

    private function name(?string $name): ?string
    {
        $name = $name === null ? '' : trim($name);

        return $name === '' ? null : $name;
    }
}
