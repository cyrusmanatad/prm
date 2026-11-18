<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class FollowUpNotice extends Mailable
{
    public function __construct(public bool $review, private string $notificationId, public string $messageSubject, public string $messageBody, private string $replyAddress) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->review ? 'A private follow-up is ready for your review' : $this->messageSubject, replyTo: $this->review ? [] : [new Address($this->replyAddress)]);
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->notificationId.'@notifications.weavdash.invalid');
    }

    public function content(): Content
    {
        // The approved message stays plain text: it is exactly the reviewed body, sent on the owner's behalf.
        return new Content(markdown: $this->review ? 'mail.html.follow-up-review' : null, text: 'mail.follow-up', with: ['origin' => rtrim(config('app.url'), '/')]);
    }
}
