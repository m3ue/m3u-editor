<?php

use App\Mail\PostProcessMail;
use App\Mail\SmtpTestMail;
use Illuminate\Support\Facades\Mail;

it('renders the post process email in the branded layout with escaped variables', function () {
    $html = (new PostProcessMail(
        emailSubject: 'Sync completed for "Sports"',
        body: 'Sync completed for "Sports & News".',
        variables: [
            'name' => 'Sports & News',
            'path' => '/data/my_playlist_file.m3u',
            'note' => '<script>alert(1)</script>',
        ],
    ))->render();

    expect($html)->toContain('M3U Editor')
        ->and($html)->toContain('class="details"')
        ->and(html_entity_decode($html, ENT_QUOTES | ENT_HTML5))->toContain('Sync completed for "Sports & News".')
        ->and($html)->toContain('/data/my_playlist_file.m3u')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('<em>')
        ->and($html)->not->toContain('</li>');

    // No logo image: neither the old inline copy nor one in the shared header.
    expect($html)->not->toContain('<img');
});

it('omits the variables table when the post process email has none', function () {
    $html = (new PostProcessMail('Subject', 'Body'))->render();

    expect($html)->not->toContain('class="details"');
});

it('sends the post process email with html and plain text parts', function () {
    Mail::mailer('array')->to('owner@example.com')->send(new PostProcessMail('Subject', 'Body', ['name' => 'Sports']));

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect($email->getHtmlBody())->toContain('M3U Editor')
        ->and($email->getTextBody())->toContain('Body')
        ->and($email->getTextBody())->toContain('Sports');
});

it('renders the smtp test email in the branded layout', function () {
    $mail = new SmtpTestMail;

    $mail->assertHasSubject(SmtpTestMail::SUBJECT);
    $mail->assertSeeInHtml('M3U Editor');
    $mail->assertSeeInHtml(__('This is a test email to verify your SMTP settings.'));
    $mail->assertSeeInText(__('This is a test email to verify your SMTP settings.'));
});

it('applies the brand theme to markdown emails', function () {
    $html = (new SmtpTestMail)->render();

    // The m3u theme is inlined into style attributes, including the accent bar.
    expect($html)->toContain('accent-bar')
        ->and($html)->toContain('#b3509f');
});
