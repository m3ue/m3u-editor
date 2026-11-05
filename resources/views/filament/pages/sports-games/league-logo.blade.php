{{-- A league's logo. Providers' artwork is made for dark backgrounds, so light mode gets a dark tile behind it. --}}
@if (! empty($url))
    <img
        src="{{ $url }}"
        alt=""
        loading="lazy"
        class="{{ $class }} box-content shrink-0 rounded-md bg-gray-900 object-contain p-1 dark:bg-transparent dark:p-0"
    />
@endif
