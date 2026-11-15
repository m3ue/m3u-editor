<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/**
 * Plain-text alert email. Alert messages are forwarded log lines and job
 * failures (often with code fences, underscores and URLs), so they are sent
 * as text rather than markdown to keep them intact.
 */
class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    /**
     * Send the body as the text/plain part only, with no Blade view.
     *
     * A text view would need unescaped output to avoid HTML entities in a
     * plain-text body; passing the string straight to the mailer's text part
     * keeps it verbatim and never renders it as HTML.
     *
     * @return array{text: HtmlString}
     */
    protected function buildView(): array
    {
        return ['text' => new HtmlString($this->body)];
    }
}
