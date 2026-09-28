<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * DVR access scoping shared by the DVR API surfaces.
 *
 * An instance is the set of DVR settings, recordings and rules a Sanctum-authenticated
 * account owner may see and manage (every DVR setting they own). The static helpers
 * resolve the same things for a playlist credential and back the Xtream DVR actions,
 * so both surfaces agree on scoping, capability gating and which DvrSetting a write
 * lands on.
 */
class DvrAccessScope
{
    /**
     * @param  array<int, int>  $dvrSettingIds
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $dvrSettingIds,
    ) {}

    /**
     * Scope an account owner to every DVR setting they own.
     */
    public static function forUser(User $user): self
    {
        return new self(
            userId: $user->id,
            dvrSettingIds: DvrSetting::where('user_id', $user->id)->pluck('id')->all(),
        );
    }

    /**
     * Whether DVR is usable at all for this caller - see DvrCapabilityGate.
     */
    public function granted(): bool
    {
        return self::grantedForSettingIds($this->dvrSettingIds, null, false);
    }

    /**
     * @return Builder<DvrRecording>
     */
    public function recordings(): Builder
    {
        return DvrRecording::whereIn('dvr_setting_id', $this->dvrSettingIds);
    }

    /**
     * @return Builder<DvrRecordingRule>
     */
    public function rules(): Builder
    {
        return DvrRecordingRule::whereIn('dvr_setting_id', $this->dvrSettingIds);
    }

    /**
     * Find a channel the caller is allowed to record from.
     */
    public function findChannel(int $channelId): ?Channel
    {
        return Channel::where('user_id', $this->userId)->whereKey($channelId)->first();
    }

    /**
     * IDs of the channels in scope whose EPG channel id (tvg_id) matches, lowest channel number first.
     *
     * @return array<int, int>
     */
    public function channelIdsForTvgId(string $tvgId): array
    {
        return Channel::where('user_id', $this->userId)
            ->whereHas('epgChannel', fn (Builder $epgQuery) => $epgQuery->where('channel_id', $tvgId))
            ->orderBy('channels.channel')
            ->pluck('channels.id')
            ->all();
    }

    /**
     * Resolve the DvrSetting a new recording/rule should be written against.
     */
    public function settingForWrite(?int $channelId = null): ?DvrSetting
    {
        if ($channelId) {
            $channel = $this->findChannel($channelId);
            if ($channel?->playlist_id) {
                $setting = DvrSetting::whereIn('id', $this->dvrSettingIds)
                    ->where('playlist_id', $channel->playlist_id)
                    ->first();
                if ($setting?->enabled) {
                    return $setting;
                }
            }
        }

        return DvrSetting::whereIn('id', $this->dvrSettingIds)
            ->where('enabled', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Single source of truth for whether DVR is usable at all: global config,
     * the DvrSettings in scope, and (for guest credentials) the PlaylistAuth's
     * own dvr_enabled flag. Granted if ANY setting in scope passes the gate, so a
     * CustomPlaylist/MergedPlaylist wrapper whose channels come from a DVR-enabled
     * source isn't gated out before its recordings are even looked up.
     *
     * @param  array<int, int>  $dvrSettingIds
     */
    public static function grantedForSettingIds(array $dvrSettingIds, ?PlaylistAuth $playlistAuth, bool $isGuestCredential): bool
    {
        if ($dvrSettingIds === []) {
            return false;
        }

        return DvrSetting::whereIn('id', $dvrSettingIds)->get()->contains(
            fn (DvrSetting $setting) => DvrCapabilityGate::granted($setting, $playlistAuth, $isGuestCredential)
        );
    }

    /**
     * Resolve every DVR setting id a playlist's recordings could be filed under: its own
     * dvrSetting (if configured directly on it), plus the dvrSettings of the source
     * Playlists behind it when it's a CustomPlaylist or MergedPlaylist wrapper, so a
     * recording remains visible and manageable no matter which of those it was scheduled
     * against.
     *
     * @return array<int, int>
     */
    public static function settingIdsForPlaylist(Playlist|CustomPlaylist|MergedPlaylist $playlist): array
    {
        if ($playlist instanceof Playlist) {
            return $playlist->dvrSetting ? [$playlist->dvrSetting->id] : [];
        }

        $sourcePlaylistIds = $playlist instanceof MergedPlaylist
            ? DB::table('merged_playlist_playlist')
                ->where('merged_playlist_id', $playlist->id)
                ->where(function ($query) {
                    // Skip sources fully excluded from the merge (all content-type
                    // toggles off) - their DVR settings shouldn't be reachable
                    // through this wrapper either.
                    $query->where('include_live', true)
                        ->orWhere('include_vod', true)
                        ->orWhere('include_series', true);
                })
                ->pluck('playlist_id')
            : $playlist->channels()
                ->whereNotNull('channels.playlist_id')
                ->groupBy('channels.playlist_id')
                ->distinct()
                ->pluck('channels.playlist_id');

        $settingIds = DvrSetting::whereIn('playlist_id', $sourcePlaylistIds)
            ->pluck('id')
            ->toArray();

        // Include the wrapper's own setting too, in case a recording was ever
        // scheduled against it directly.
        if ($playlist->dvrSetting) {
            $settingIds[] = $playlist->dvrSetting->id;
        }

        return array_values(array_unique($settingIds));
    }

    /**
     * Resolve the single DvrSetting a new recording/rule should be written against,
     * when accessed through a CustomPlaylist/MergedPlaylist wrapper. Mirrors
     * settingIdsForPlaylist()'s read-side resolution, but a write needs exactly one
     * target: prefers the given channel's own source Playlist's DvrSetting (so
     * scheduling a specific channel through a wrapper always lands on the right
     * source, even when the wrapper spans multiple DVR-enabled sources), then falls
     * back to the wrapper's own DvrSetting, then the first DVR-enabled source
     * (covers the "any channel" series-rule case).
     */
    public static function settingForWriteOnPlaylist(Playlist|CustomPlaylist|MergedPlaylist $playlist, ?int $channelId = null): ?DvrSetting
    {
        if ($channelId) {
            $channel = $playlist->channels()->where('channels.id', $channelId)->first();
            if ($channel?->playlist_id) {
                $setting = DvrSetting::where('playlist_id', $channel->playlist_id)->first();
                if ($setting?->enabled) {
                    return $setting;
                }
            }
        }

        if ($playlist->dvrSetting) {
            return $playlist->dvrSetting;
        }

        $settingIds = self::settingIdsForPlaylist($playlist);

        return $settingIds === [] ? null : DvrSetting::find($settingIds[0]);
    }
}
