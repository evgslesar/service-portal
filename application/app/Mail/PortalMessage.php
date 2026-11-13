<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PortalMessage extends Mailable
{
    public function __construct(public string $eventTitle, public string $taskUrl, public bool $invitation = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('portal.company').' · '.$this->eventTitle);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.portal-message');
    }
}
