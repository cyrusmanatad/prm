<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class OnboardingInvitation extends Mailable
{
    public function __construct(public string $invitationUrl, public string $expiresAt, private string $notificationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your WeavDash onboarding invitation');
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->notificationId.'@notifications.weavdash.invalid');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.onboarding-invitation');
    }
}
