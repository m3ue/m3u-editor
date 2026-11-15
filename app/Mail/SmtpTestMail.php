<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by the "Send Test Email" action in Settings > SMTP. Uses the same
 * branded markdown layout as the other emails, so the test doubles as a
 * preview of how they render in the recipient's mail client.
 */
class SmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECT = 'Test Email from m3u editor';

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::SUBJECT,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.smtp_test',
        );
    }
}
