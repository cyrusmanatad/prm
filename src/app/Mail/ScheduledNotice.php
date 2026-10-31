<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class ScheduledNotice extends Mailable
{
    public function __construct(public string $kind, public string $timezone, public string $dueAt, public string $workspaceId, private string $notificationId, public array $summary = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === 'schedule.reminder' ? 'Your scheduled reminder is ready' : 'Your weekly review in WeavDash');
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->notificationId.'@notifications.weavdash.invalid');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.scheduled-notice', with: ['origin' => rtrim(config('app.url'), '/')]);
    }
}
