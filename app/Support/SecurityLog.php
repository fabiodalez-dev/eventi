<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Il registro degli eventi di sicurezza (A09:2021 — *Security Logging and
 * Monitoring Failures*).
 *
 * ## Cosa non c'era
 *
 * `activity()` era già in uso per il ticketing e per le modifiche editoriali,
 * ma **non** per le cose che si vanno a cercare quando qualcosa è andato
 * storto: accessi falliti, blocchi per troppi tentativi, reimpostazioni di
 * password, emissione di token dell'applicazione, cambi di ruolo. Un
 * attaccante attraversava il sistema senza lasciare una riga — e la prima
 * domanda dopo un incidente («da quando, e da dove?») non aveva risposta.
 *
 * ## Cosa si scrive e cosa no
 *
 * Mai la password, mai il token, mai il contenuto di una sessione. Le
 * intestazioni non si copiano in blocco: si prendono l'indirizzo e l'agente,
 * perché sono le due cose che servono a riconoscere una serie.
 *
 * **L'email si registra solo quando corrisponde a un account che esiste.** Un
 * tentativo su un indirizzo inesistente è quasi sempre un errore di battitura
 * o una scansione: conservarlo vorrebbe dire raccogliere indirizzi di persone
 * che con questo sito non hanno niente a che fare, cioè creare un archivio di
 * dati personali per difendersi da un rumore. Quando il bersaglio è reale,
 * invece, sapere **quale** account è sotto attacco è esattamente ciò che
 * serve.
 */
final class SecurityLog
{
    public static function blocco(): void
    {
        $key = 'security-lockout:'.hash('sha256', request()->ip() ?? 'unknown');
        if (Cache::add($key, true, 60)) {
            self::scrivi('accesso_bloccato');
        }
    }

    /**
     * @param  array<string, mixed>  $dettagli
     */
    public static function scrivi(string $evento, ?User $soggetto = null, array $dettagli = [], ?User $causa = null): void
    {
        $registro = activity('sicurezza')->withProperties([...self::contesto(), ...$dettagli]);

        if ($causa instanceof User) {
            $registro->causedBy($causa);
        }

        if ($soggetto instanceof User) {
            $registro->performedOn($soggetto);
        }

        $registro->log($evento);
    }

    /**
     * Il tentativo di accesso non riuscito.
     *
     * L'email entra nel registro solo se quell'account esiste: vedi la nota in
     * testa alla classe.
     */
    public static function accessoFallito(mixed $email): void
    {
        $indirizzo = is_string($email) ? mb_strtolower(trim($email)) : '';
        $utente = $indirizzo === '' ? null : User::query()->where('email', $indirizzo)->first();

        self::scrivi(
            'accesso_fallito',
            $utente instanceof User ? $utente : null,
            $utente instanceof User
                ? ['email' => $indirizzo]
                : ['bersaglio' => 'account inesistente'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function contesto(): array
    {
        if (! app()->bound('request')) {
            return ['origine' => 'console'];
        }

        $request = app('request');

        return [
            'ip' => $request->ip(),
            /* Troncato: un `User-Agent` è scritto da chi chiama e non ha un
               tetto naturale, e questa riga finisce in una colonna JSON. */
            'agente' => mb_substr((string) $request->userAgent(), 0, 255),
        ];
    }
}
