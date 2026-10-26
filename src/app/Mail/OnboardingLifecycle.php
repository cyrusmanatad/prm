<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class OnboardingLifecycle extends Mailable
{
    public function __construct(public string $kind, public string $returnUrl, public string $expiresAt, private string $notificationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'onboarding.submitted' => 'An onboarding form is ready for review',
            'onboarding.corrected' => 'Your onboarding form needs a correction',
            'onboarding.accepted' => 'Your onboarding submission was accepted',
        });
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->notificationId.'@notifications.weavdash.invalid');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.onboarding-lifecycle');
    }
}
