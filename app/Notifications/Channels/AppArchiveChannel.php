<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La riga nella casella degli avvisi che erano solo email (Q3).
 *
 * Scrive con il canale `database` di Laravel (stessa tabella, stessa forma),
 * con due differenze, perché qui l'email parte prima e conta di più:
 *
 * - la riga si scrive dopo il commit. Molti di questi avvisi partono dentro
 *   una transazione (l'approvazione di una richiesta, l'invito in un locale)
 *   e `toMail` ha già emesso, nella stessa transazione, il gettone per
 *   scegliere la password: se la scrittura fallisse lì dentro, la
 *   transazione si annullerebbe con l'approvazione e con il gettone, e la
 *   persona resterebbe con un collegamento che non vale più. Se invece è la
 *   transazione ad annullarsi da sola, con lei sparisce anche la riga, come
 *   la push (`AppPush` parte dopo il commit). Fuori da una transazione si
 *   scrive subito;
 * - un guasto si registra e non risale, come in `AppPushChannel`: l'email è
 *   già partita, e chi l'ha mandata non deve crederla fallita (il rapporto
 *   mensile, per esempio, restituirebbe la presa in carico e lo rimanderebbe,
 *   il riepilogo settimanale partirebbe due volte).
 */
final class AppArchiveChannel
{
    public function __construct(private readonly DatabaseChannel $database) {}

    public function send(object $notifiable, Notification $notification): void
    {
        DB::afterCommit(function () use ($notifiable, $notification): void {
            try {
                $this->database->send($notifiable, $notification);
            } catch (Throwable $error) {
                report($error);
            }
        });
    }
}
