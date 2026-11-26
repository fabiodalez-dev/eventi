<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
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

    /** I testi più lunghi di così entrano troncati: il registro deve dire che cosa è cambiato, non conservare pagine. */
    public const MAX_TEXT = 2000;

    /**
     * Una scrittura della configurazione fatta da una persona della redazione
     * (impostazioni social, sorgenti di import, testi delle email, newsletter,
     * redirect, cookie, cache, invii e post programmati), dal pannello o
     * dall'app. Chi la fa è la persona collegata; il canale `app` lo aggiunge
     * da sé `Activity::creating` per le richieste della redazione nell'app.
     *
     * I dettagli portano di solito `oggetto`, `id`, `prima` e `dopo`, mai un
     * segreto: chi chiama toglie password, token e credenziali (al più dice
     * che sono cambiati). I testi lunghi si troncano.
     *
     * @param  array<string, mixed>  $dettagli
     */
    public static function configurazione(string $evento, array $dettagli = []): void
    {
        $causa = Auth::user();

        self::scrivi($evento, null, self::pulisci($dettagli), $causa instanceof User ? $causa : null);
    }

    /**
     * Le sole voci cambiate fra due istantanee, con il loro valore prima e
     * dopo; vuoto se non è cambiato niente.
     *
     * @param  array<string, mixed>  $prima
     * @param  array<string, mixed>  $dopo
     * @return array{prima: array<string, mixed>, dopo: array<string, mixed>}
     */
    public static function differenze(array $prima, array $dopo): array
    {
        $before = [];
        $after = [];
        foreach (array_unique([...array_keys($prima), ...array_keys($dopo)]) as $key) {
            $old = self::valore($prima[$key] ?? null);
            $new = self::valore($dopo[$key] ?? null);
            if (json_encode($old) !== json_encode($new)) {
                $before[$key] = $old;
                $after[$key] = $new;
            }
        }

        return ['prima' => $before, 'dopo' => $after];
    }

    /** Un valore come si scrive nel registro: enum come valore, date in ISO, testi troncati. */
    public static function valore(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_string($value) && mb_strlen($value) > self::MAX_TEXT => mb_substr($value, 0, self::MAX_TEXT).'…',
            is_array($value) => array_map(self::valore(...), $value),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $dettagli
     * @return array<string, mixed>
     */
    private static function pulisci(array $dettagli): array
    {
        return array_map(self::valore(...), $dettagli);
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
