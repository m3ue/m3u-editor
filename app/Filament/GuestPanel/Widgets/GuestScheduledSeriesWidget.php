<?php

namespace App\Filament\GuestPanel\Widgets;

use App\Enums\DvrRuleType;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestDvr;
use App\Models\DvrRecordingRule;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

class GuestScheduledSeriesWidget extends Widget
{
    use HasGuestDvr;

    protected string $view = 'filament.guest-panel.widgets.guest-scheduled-series-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return Collection<int, DvrRecordingRule>
     */
    public function getSeriesRules(): Collection
    {
        $dvrSetting = static::getDvrSetting();
        if (! $dvrSetting) {
            return new Collection;
        }

        $currentAuth = static::getCurrentPlaylistAuth();

        // Stale session: the credentials no longer resolve to a live
        // PlaylistAuth row (e.g. revoked/disabled mid-session). Without this
        // guard, Laravel's query builder turns `where(col, null)` into
        // `whereNull(col)` and would happily return the playlist OWNER's
        // series rules (the only ones with playlist_auth_id = null),
        // re-opening the leak issue #1398 exists to close. isOwnerAuth() must
        // be allowed through — the owner has no PlaylistAuth row, so
        // getCurrentPlaylistAuth() legitimately returns null for them, and
        // they own the rules with playlist_auth_id = null.
        if (! $currentAuth && ! static::isOwnerAuth()) {
            return new Collection;
        }

        return DvrRecordingRule::with(['channel'])
            ->where('dvr_setting_id', $dvrSetting->id)
            ->where('type', DvrRuleType::Series)
            ->where('enabled', true)
            // Guests only see their own series rules — never another
            // guest's, nor the playlist owner's (null playlist_auth_id).
            ->where('playlist_auth_id', $currentAuth?->id)
            ->orderByDesc('priority')
            ->orderBy('series_title')
            ->get();
    }
}
