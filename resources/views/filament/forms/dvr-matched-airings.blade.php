<div class="fi-fo-field-wrp col-span-full">
    @if (! empty($ruleAttributes['series_title'] ?? null) && ! empty($ruleAttributes['dvr_setting_id'] ?? null))
        {{-- wire:key forces this element to be replaced (not patched) whenever the
             inputs the preview depends on change, which re-fires wire:init below. --}}
        <div wire:key="dvr-airings-{{ $cacheKey }}">
            @if ($airings === null)
                <div wire:init="loadDvrMatchedAiringsPreview('{{ $cacheKey }}', {{ \Illuminate\Support\Js::from($ruleId) }}, {{ \Illuminate\Support\Js::from($ruleAttributes) }})">
                    @include('filament.forms.dvr-matched-airings-placeholder')
                </div>
            @else
                @include('filament.forms.dvr-matched-airings-content', ['airings' => $airings])
            @endif
        </div>
    @endif
</div>
