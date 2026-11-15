{{--
    HTML part of AlertMail. The body is a forwarded log line or job failure, so it
    goes in a <pre> HTML block: CommonMark passes everything up to </pre> through
    untouched, so underscores, asterisks, code fences and blank lines are never
    treated as markdown, and {{ }} escapes any HTML in it.
--}}
<x-mail::message>
# {{ __('Alert') }}

<pre class="message-block">{{ $body }}</pre>

<p class="sub">{{ __('You are receiving this because email alerts are enabled in Settings > Alerts.') }}</p>
</x-mail::message>
