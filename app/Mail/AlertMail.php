<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Markdown;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/**
 * Alert email. Alert messages are forwarded log lines and job failures (often
 * with code fences, underscores and URLs), so the text part carries the body
 * verbatim and the branded HTML part shows it in a preformatted block instead
 * of parsing it as markdown.
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
     * Build the branded HTML part and the verbatim text/plain part.
     *
     * The text part is the raw string handed to the mailer (no Blade view), so
     * it is never HTML-escaped or rendered. The HTML part is rendered through
     * the markdown mail layout directly rather than via Content(markdown:),
     * because that would also derive the text part from the markdown template.
     *
     * @return array{html: HtmlString, text: HtmlString}
     */
    protected function buildView(): array
    {
        $markdown = app(Markdown::class);
        $markdown->theme($this->theme ?? config('mail.markdown.theme', 'default'));

        return [
            'html' => $markdown->render('mail.alert', ['body' => $this->body]),
            'text' => new HtmlString($this->body),
        ];
    }
}
