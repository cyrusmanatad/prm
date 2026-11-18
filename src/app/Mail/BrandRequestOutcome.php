<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** States only that a review outcome exists. The submission and the reviewer's feedback stay in the application. */
class BrandRequestOutcome extends Mailable
{
    public function __construct(public string $kind, public string $requestUrl, private string $notificationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'brand_request.approved' => 'Your Brand workspace request was approved',
            'brand_request.needs_information' => 'Your Brand workspace request needs more information',
            'brand_request.declined' => 'Your Brand workspace request was reviewed',
        });
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->notificationId.'@notifications.weavdash.invalid');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.html.brand-request-outcome', text: 'mail.brand-request-outcome');
    }
}
