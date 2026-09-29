<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously (never queued) because the queue may be what is broken.
 */
final class HealthAlertMail extends Mailable
{
    /**
     * @param  list<string>  $problems  Human-readable problem lines, no secrets.
     */
    public function __construct(public readonly array $problems, public readonly bool $recovered = false) {}

    public function envelope(): Envelope
    {
        $app = (string) config('app.name');

        return new Envelope(subject: $this->recovered
            ? "[{$app}] Recovered: all health checks pass"
            : "[{$app}] ALERT: ".count($this->problems).' problem(s) detected');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.health-alert');
    }
}
