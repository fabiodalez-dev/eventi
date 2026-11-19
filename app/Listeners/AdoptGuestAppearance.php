<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Il tema scelto prima di accedere resta dopo l'accesso.
 *
 * Da ospite la scelta vive nel cookie `incitta_appearance`, scritto dal
 * selettore del tema; il profilo invece nasce senza scelta. Senza questo
 * passaggio chi aveva messo il sito in chiaro lo vedeva tornare scuro
 * nell'istante in cui entrava. Al primo accesso (e alla registrazione) la
 * scelta del browser diventa quella del profilo — solo se il profilo non ne
 * ha già una: una scelta fatta dal profilo vale su tutti i dispositivi e non
 * la sovrascrive il cookie di uno di loro.
 *
 * Il cookie è fuori dalla cifratura dei cookie (vedi `bootstrap/app.php`):
 * lo scrive lo script del browser, ed è una preferenza d'aspetto, non un
 * segreto. Il valore si accetta solo se è uno dei due temi.
 */
final class AdoptGuestAppearance
{
    public function handleLogin(Login $event): void
    {
        $this->adopt($event->user);
    }

    public function handleRegistered(Registered $event): void
    {
        $this->adopt($event->user);
    }

    private function adopt(Authenticatable $user): void
    {
        if (! $user instanceof User || $user->appearance !== null || ! app()->bound('request')) {
            return;
        }

        $cookie = request()->cookies->get('incitta_appearance');

        if (! in_array($cookie, ['light', 'dark'], true)) {
            return;
        }

        $user->forceFill(['appearance' => $cookie])->saveQuietly();
    }
}
