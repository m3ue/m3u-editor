{{--
    The variables table is a single HTML block (no blank lines inside), so
    CommonMark passes it through untouched and values such as file paths or
    names with underscores are never turned into markdown emphasis.
--}}
<x-mail::message>
# {{ __('Sync completed') }}

## {{ $body }}

@if (!empty($variables))
### {{ __('Variables') }}

<table class="details" role="presentation">
@foreach ($variables as $key => $value)
<tr>
<th>{{ $key }}</th>
<td>{{ $value }}</td>
</tr>
@endforeach
</table>
@endif

</x-mail::message>
