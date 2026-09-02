<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Il messaggio dell'invio di prova.
 *
 * **Non e' in coda, ed e' il punto.** `ShouldQueue` renderebbe la prova
 * inutile: il pulsante direbbe «spedito» perche' il lavoro e' stato messo in
 * fila, e il fallimento vero arriverebbe minuti dopo dentro un registro che
 * nessuno sta guardando. Qui si spedisce subito e si aspetta l'esito, che e'
 * l'unica cosa che si voleva sapere.
 */
class MailConfigurationTest extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail_settings.test.subject', ['product' => config()->string('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.configuration-test',
            with: [
                'quando' => now()->translatedFormat('j F Y \a\l\l\e H:i'),
            ],
        );
    }
}
