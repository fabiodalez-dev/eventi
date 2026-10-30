<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use App\Support\AccountLoginThrottle;
use App\Support\SecurityLog;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

final class Login extends \Filament\Auth\Pages\Login
{
    public function authenticate(): ?LoginResponse
    {
        $key = AccountLoginThrottle::chiave('pannello', (string) ($this->data['email'] ?? ''));
        if (! AccountLoginThrottle::prenota($key)) {
            SecurityLog::scrivi('accesso.limitato');
            $attesa = AccountLoginThrottle::attesa($key);
            throw ValidationException::withMessages(['data.email' => __('auth.throttle', ['seconds' => $attesa, 'minutes' => (int) ceil($attesa / 60)])]);
        }

        /*
         * Si contano solo i fallimenti: un accesso riuscito non è un
         * tentativo subito dall'account, e contarlo chiudeva fuori proprio chi
         * entra spesso — lo scanner, il secondo dispositivo, il giorno
         * dell'evento. Il tentativo però è già contato da `prenota()`, prima
         * della password: qui lo si restituisce quando l'accesso riesce, o
         * quando la password non è stata nemmeno verificata (il limite per
         * indirizzo di Filament risponde `null`). Un fallimento, o
         * un'eccezione qualunque, lo lascia contato.
         */
        $risposta = parent::authenticate();
        AccountLoginThrottle::restituisci($key);

        /*
         * Come sul sito e nell'app (D64): la password di un account la cui
         * casella non è ancora dimostrata da chi l'ha scelta può averla
         * scelta chiunque abbia registrato quell'indirizzo, e non apre
         * nemmeno i pannelli. Il controllo arriva dopo la password, quindi
         * il messaggio lo legge solo chi la conosce; per entrare basta un
         * accesso dal sito, che rimanda il collegamento legato al browser e
         * lascia valida la password.
         */
        $utente = Filament::auth()->user();
        if ($utente instanceof User && $utente->email_claim_pending_at !== null) {
            Filament::auth()->logout();
            session()->invalidate();
            session()->regenerateToken();

            throw ValidationException::withMessages(['data.email' => __('account.login.claim_pending_panel')]);
        }

        return $risposta;
    }
}
