<?php

namespace App\Filament\Concerns;

use App\Enums\DvrMatchMode;
use App\Enums\DvrSeriesMode;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Traits\HasDvrMatchedAirings;
use Illuminate\Support\Facades\Auth;

/**
 * Livewire-side half of the deferred "Upcoming Airings" preview on the DVR
 * recording rule form (see DvrRecordingRuleResource::buildMatchedAiringsPreviewProps()
 * and resources/views/filament/forms/dvr-matched-airings.blade.php). Resolving
 * matched airings can scan every EPG-mapped channel on a playlist (hundreds of
 * thousands of channels), so it must never run on the same request that opens
 * the rule's create/edit modal - the blade view's wire:init calls
 * loadDvrMatchedAiringsPreview() in a follow-up request instead, once the
 * modal is already visible.
 *
 * Deliberately does not persist results across form edits/loads - a nested
 * #[Lazy] Livewire child was tried here first and corrupted the hosting
 * component's child tracking (broke re-opening the edit modal entirely after
 * closing it once), so this stays a plain method call on the SAME component
 * instead of a separate child component.
 *
 * Mixed into every Livewire component that can host the DVR recording rule
 * form (the list page's mounted create/edit actions, and the - currently
 * routeless but still test-exercised - Create/Edit pages).
 */
trait HasDvrMatchedAiringsPreviewCache
{
    use HasDvrMatchedAirings;

    public ?string $dvrAiringsPreviewKey = null;

    /** @var array<int, array<string, mixed>> */
    public array $dvrAiringsPreview = [];

    /**
     * wire:init target - runs in its own request after the modal has already
     * rendered. $ruleId/$ruleAttributes are attacker-controllable (any
     * authenticated user can call a public Livewire method with arbitrary
     * arguments), so both the rule and the DVR setting must be re-verified as
     * belonging to the current user before touching them.
     *
     * @param  array<string, mixed>  $ruleAttributes
     */
    public function loadDvrMatchedAiringsPreview(string $cacheKey, ?int $ruleId, array $ruleAttributes): void
    {
        $record = $ruleId ? DvrRecordingRule::where('user_id', Auth::id())->find($ruleId) : null;

        if ($ruleId && ! $record) {
            $this->dvrAiringsPreviewKey = $cacheKey;
            $this->dvrAiringsPreview = [];

            return;
        }

        $dvrSettingId = $ruleAttributes['dvr_setting_id'] ?? null;
        if ($dvrSettingId && ! DvrSetting::where('id', $dvrSettingId)->where('user_id', Auth::id())->exists()) {
            $this->dvrAiringsPreviewKey = $cacheKey;
            $this->dvrAiringsPreview = [];

            return;
        }

        $seriesMode = DvrSeriesMode::tryFrom((string) ($ruleAttributes['series_mode'] ?? ''))
            ?? $record?->series_mode
            ?? DvrSeriesMode::All;

        $tempRule = new DvrRecordingRule([
            // Existing records supply the base (fields outside the form -
            // tmdb_id, enable_comskip, keep_last, ...) so edited rules preview
            // with their full context. There is no match_mode field on this
            // form, so it always comes from the record (or the model default).
            ...($record?->getAttributes() ?? []),
            ...$ruleAttributes,
            'series_mode' => $seriesMode,
            'match_mode' => $record?->match_mode ?? DvrMatchMode::Contains,
        ]);

        $this->dvrAiringsPreviewKey = $cacheKey;
        $this->dvrAiringsPreview = static::resolveMatchedAirings($tempRule);
    }
}
