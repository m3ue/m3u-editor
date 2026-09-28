@props(['label', 'enabled', 'url', 'suffix', 'qrBody', 'recordName', 'description', 'disabledDescription'])
<div>
    <div class="flex items-center gap-2">
        <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white">{{ $label }}</span>
        @unless ($enabled)
            <x-filament::badge color="danger" size="sm">{{ __('Disabled') }}</x-filament::badge>
        @endunless
    </div>
    @if ($enabled)
        <div class="flex items-center justify-start gap-2">
            <x-filament::input.wrapper>
                <x-slot name="prefix">
                    <x-copy-to-clipboard :text="$url" />
                </x-slot>
                <x-filament::input type="text" :value="$url" readonly />
                <x-slot name="suffix">{{ $suffix }}</x-slot>
            </x-filament::input.wrapper>
            <x-qr-modal :title="$recordName" :body="$qrBody" :text="$url" />
        </div>
        <div class="fi-fo-field-wrp-helper-text mt-1 text-sm break-words text-gray-500">{{ $description }}</div>
    @else
        <div class="fi-fo-field-wrp-helper-text mt-1 text-sm break-words text-gray-500">{{ $disabledDescription }}</div>
    @endif
</div>
