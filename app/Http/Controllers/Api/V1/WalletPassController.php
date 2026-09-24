<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Http\Controllers\Controller;
use App\Models\AdmissionTicket;
use App\Models\EventOccurrence;
use App\Services\Ticketing\GoogleWallet;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Google Wallet issuance and cancellation share the occurrence lock. */
final class WalletPassController extends Controller
{
    /**
     * Dichiara se la funzione esiste oggi. Pubblico di proposito: non rivela
     * nulla di nessuno e l'app deve poterlo chiedere prima di disegnare.
     */
    public function availability(): JsonResponse
    {
        return response()
            ->json(['data' => ['google_wallet' => app(GoogleWallet::class)->configured()]])
            ->header('Cache-Control', 'no-store');
    }

    public function store(AdmissionTicket $ticket): JsonResponse
    {
        abort_unless(app(GoogleWallet::class)->configured(), 404);
        Gate::authorize('view', $ticket->booking);
        $token = DB::transaction(function () use ($ticket): string {
            EventOccurrence::withTrashed()->lockForUpdate()->findOrFail($ticket->booking->occurrence_id);
            $ticket = AdmissionTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $date = $ticket->booking?->occurrence;
            abort_unless($ticket->displayStatus() === AdmissionStatus::Valid
                && $ticket->booking?->status === BookingStatus::Confirmed
                && ($ticket->booking->promotion_expires_at === null || $ticket->booking->promotion_expires_at->isFuture())
                && $date?->event?->status === EventStatus::Published
                && in_array($date->status, [OccurrenceStatus::Scheduled, OccurrenceStatus::Moved, OccurrenceStatus::SoldOut], true), 404);
            $google = config()->array('wallet.google');
            app(GoogleWallet::class)->create(self::object($ticket, $google['issuer_id'], $google['class_id']));
            $ticket->update(['wallet_requested_at' => now()]);

            return self::token($ticket);
        });

        return response()
            ->json(['data' => ['save_url' => 'https://pay.google.com/gp/v/save/'.$token]])
            ->header('Cache-Control', 'no-store');
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
                'payload' => ['eventTicketObjects' => [['id' => $issuer.'.incitta-'.$ticket->id, 'classId' => $issuer.'.'.$google['class_id']]]],
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
            'textModulesData' => array_values(array_filter([
                $event?->title ? ['id' => 'event', 'header' => __('decision.wallet_event'), 'body' => $event->title] : null,
                $venue?->name ? ['id' => 'venue', 'header' => __('decision.wallet_venue'), 'body' => $venue->name.' — '.$venue->address] : null,
                $date?->starts_at ? ['id' => 'date', 'header' => __('decision.wallet_date'), 'body' => $date->starts_at->toIso8601String()] : null,
            ])),
            // Scaduto il pass, il portafoglio lo archivia da solo.
            'validTimeInterval' => $ends === null ? null : [
                'end' => ['date' => $ends->toIso8601String()],
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
