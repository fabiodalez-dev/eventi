<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PublicContact extends Mailable
{
    public function __construct(public string $targetName, public string $senderName, public string $senderEmail, public string $messageText) {}

    public function envelope(): Envelope
    {
        return new Envelope(replyTo: [new Address($this->senderEmail, $this->senderName)], subject: __('contact.subject', ['name' => $this->targetName]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.public-contact');
    }
}
