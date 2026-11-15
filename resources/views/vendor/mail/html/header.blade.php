{{--
    Branded header for every markdown email: a text wordmark only. No logo
    image, because remote images are blocked by many mail clients and local
    mail catchers, and SVG does not render in Gmail or Outlook.
--}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="brand-name">{{ trim($slot) !== '' ? trim($slot) : 'M3U Editor' }}</span>
</a>
</td>
</tr>
