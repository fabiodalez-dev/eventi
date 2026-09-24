<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\AdmissionTicket;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Il biglietto nel portafoglio digitale (blocco 8 del piano di crescita).
 *
 * Due indirizzi, e il primo esiste per non mostrare un pulsante che non
 * funziona: `GET /v1/wallet` dice soltanto se la funzione è configurata, e
 * l'app disegna «Aggiungi a Google Wallet» solo quando la risposta è vera.
 * Senza credenziali dell'emittente la risposta è falsa e il secondo indirizzo
 * risponde 404: non esiste uno stato in cui il pulsante c'è e non porta a
 * niente.
 *
 * ## Che cosa finisce nel pass, e che cosa no
 *
 * Solo ciò che serve davanti alla porta: il codice di ingresso come codice a
 * barre, il nome di chi entra, titolo e ora della data, il luogo. Niente
 * email, niente identificativo dell'account, niente dati di chi ha prenotato
 * per altri — il pass è un oggetto che vive sui server di Google e viene
 * mostrato a uno sconosciuto all'ingresso.
 *
 * ## Come un pass smette di valere
 *
 * Tre strati, di cui due funzionano già oggi senza credenziali:
 *
 * 1. **Il codice a barre è il codice di ingresso**, lo stesso che il QR
 *    dell'app mostra. La verifica all'ingresso passa da
 *    `TicketingController::checkIn`, che guarda lo stato sul server: un
 *    biglietto annullato viene respinto anche se l'immagine del pass è
 *    rimasta nel telefono. È questa la garanzia che regge davvero.
 * 2. **Il pass ha una scadenza** (`validTimeInterval`) fissata alla fine
 *    della data: passata quella, Google lo mostra come scaduto da solo.
 * 3. **L'oggetto ha un identificativo prevedibile** (`…incitta-<id biglietto>`)
 *    perché il giorno in cui le credenziali esistono basti una PATCH su
 *    `eventticketobject/{id}` con `state: INACTIVE` per spegnerlo anche
 *    dentro il portafoglio. Quella chiamata va agganciata all'annullamento,
 *    cioè dentro `TicketingService::cancel()`, ed è l'unico pezzo che questo
 *    controller non può fare da solo: senza emittente non c'è niente da
 *    spegnere, e con l'emittente la scrittura appartiene al servizio che già
 *    governa la cancellazione.
 *
 * Il pass si emette solo per un biglietto `valid`: per un annullato o già
 * usato l'indirizzo risponde 404, quindi dall'app non se ne può creare uno
 * nuovo dopo l'annullamento.
 */
final class WalletPassController extends Controller
{
    /**
     * Dichiara se la funzione esiste oggi. Pubblico di proposito: non rivela
     * nulla di nessuno e l'app deve poterlo chiedere prima di disegnare.
     */
    public function availability(): JsonResponse
    {
        return response()
            ->json(['data' => ['google_wallet' => self::configured()]])
            ->header('Cache-Control', 'no-store');
    }

    public function store(AdmissionTicket $ticket): JsonResponse
    {
        abort_unless(self::configured(), 404);
        Gate::authorize('view', $ticket->booking);
        // 404 e non 403: per chi chiede, un biglietto annullato non ha un pass.
        abort_unless($ticket->displayStatus() === AdmissionStatus::Valid, 404);

        return response()
            ->json(['data' => ['save_url' => 'https://pay.google.com/gp/v/save/'.self::token($ticket)]])
            ->header('Cache-Control', 'no-store');
    }

    private static function configured(): bool
    {
        $google = config()->array('wallet.google');

        return filled($google['issuer_id'] ?? null)
            && filled($google['class_id'] ?? null)
            && filled($google['service_account_email'] ?? null)
            && filled($google['private_key'] ?? null);
    }

    /**
     * Il gettone firmato che Google scambia con il pass salvato.
     */
    private static function token(AdmissionTicket $ticket): string
    {
        $google = config()->array('wallet.google');
        /** @var string $issuer */
        $issuer = $google['issuer_id'];

        return JWT::encode(
            [
                'iss' => $google['service_account_email'],
                'aud' => 'google',
                'typ' => 'savetowallet',
                'iat' => time(),
                'origins' => $google['origins'] ?? [],
                'payload' => ['eventTicketObjects' => [self::object($ticket, $issuer, (string) $google['class_id'])]],
            ],
            // Nel file .env gli a capo della chiave PEM sono scritti \n.
            str_replace('\n', "\n", (string) $google['private_key']),
            'RS256',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(AdmissionTicket $ticket, string $issuer, string $class): array
    {
        $date = $ticket->booking?->occurrence;
        $event = $date?->event()->withTrashed()->first();
        $venue = $date?->effectiveVenue();
        // Stessa scaletta di BookingResource: fine effettiva, fine dichiarata,
        // e in mancanza di entrambe tre ore dall'inizio.
        $ends = $date->effective_ends_at ?? $date->ends_at ?? $date?->starts_at?->copy()->addHours(3);

        return array_filter([
            'id' => $issuer.'.incitta-'.$ticket->id,
            'classId' => $issuer.'.'.$class,
            'state' => 'ACTIVE',
            'ticketHolderName' => $ticket->attendee_name,
            'ticketNumber' => (string) $ticket->id,
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $ticket->code,
                'alternateText' => '#'.$ticket->id,
            ],
            'eventName' => $event?->title === null ? null : [
                'defaultValue' => ['language' => 'it', 'value' => $event->title],
            ],
            'venue' => $venue?->name === null ? null : [
                'name' => ['defaultValue' => ['language' => 'it', 'value' => $venue->name]],
                'address' => ['defaultValue' => ['language' => 'it', 'value' => (string) $venue->address]],
            ],
            'dateTime' => $date?->starts_at === null ? null : array_filter([
                'start' => $date->starts_at->toIso8601String(),
                'end' => $ends?->toIso8601String(),
            ]),
            // Scaduto il pass, il portafoglio lo archivia da solo.
            'validTimeInterval' => $ends === null ? null : [
                'end' => ['date' => $ends->toIso8601String()],
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
