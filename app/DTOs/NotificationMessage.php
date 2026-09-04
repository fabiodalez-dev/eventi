<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\NotificationType;

/**
 * Il contenuto di una notifica, già tradotto e pronto per qualunque canale.
 *
 * Esiste perché i canali attivi sono tre — push, email, archivio in-app
 * (§15.6, D54) — e comporre il testo dentro il canale significherebbe
 * riscriverlo per ciascuno, con il rischio che il messaggio archiviato dica
 * una cosa e quello spedito un'altra. Quando era stato scritto i canali erano
 * due e il terzo era un'ipotesi: è la ragione per cui aggiungerlo non ha
 * richiesto di toccare né questa classe né `MessageFactory`.
 *
 * `url` è il **collegamento profondo alla scheda evento** che §15.4 pretende su
 * ogni notifica: mai la home. Per i riepiloghi è la lista da cui sono presi i
 * titoli, e ogni titolo porta comunque il proprio indirizzo.
 */
final readonly class NotificationMessage
{
    /**
     * @param  list<string>  $lines
     * @param  list<array{title: string, meta: string, url: string}>  $items
     */
    public function __construct(
        public NotificationType $type,
        public string $subject,
        public string $heading,
        public array $lines,
        public string $actionLabel,
        public string $url,
        public array $items = [],
        public ?int $occurrenceId = null,
        public ?int $eventId = null,
    ) {}

    /**
     * Ciò che finisce nella colonna `data` dell'archivio in-app, e che
     * `GET /v1/me/notifications` restituisce così com'è (§15.8).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->heading,
            'body' => implode(' ', $this->lines),
            'url' => $this->url,
            'items' => $this->items,
            'occurrence_id' => $this->occurrenceId,
            'event_id' => $this->eventId,
        ];
    }
}
