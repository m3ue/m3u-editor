<x-mail::message>
<div style="text-align: center;">
    <img src="https://raw.githubusercontent.com/sparkison/m3u-editor/master/public/logo.png" alt="M3U Proxy Editor Logo" style="width: 80px; height: auto; margin-bottom: 20px; auto;">
</div>

# Sync completed

## {{ $body }}

@if (!empty($variables))
### Variables:

@foreach ($variables as $key => $value)
- <strong>{{ $key }}:</strong> {{ $value }}</li>

@endforeach
@endif

</x-mail::message>