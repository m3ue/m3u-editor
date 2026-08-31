<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class Group extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Group $group): void {
            $group->assertMergedGroupRulesHold();
        });

        static::deleted(function (Group $group): void {
            // When a group is soft-deleted (e.g. manually via the UI), strip its ID
            // from the source playlist's auto-sync rules so the saved config stays valid
            // and the playlist can be edited without a validation error.
            $ruleType = $group->type === 'vod' ? 'vod_groups' : 'live_groups';
            $group->playlist?->pruneAutoSyncGroupIds([$group->id], $ruleType);
        });
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'stream_profile_id' => 'integer',
        'aed_profile_id' => 'integer',
        'parent_id' => 'integer',
        'is_merged' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function streamFileSetting(): BelongsTo
    {
        return $this->belongsTo(StreamFileSetting::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    public function aedProfile(): BelongsTo
    {
        return $this->belongsTo(AedProfile::class);
    }

    /**
     * The merged group this group has been folded into, if any. When set, playlist
     * output substitutes the parent's identity for this group's - see the effective*
     * accessors below.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child groups folded into this merged group.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Channels belonging to any child group folded into this merged group. Only
     * meaningful for a merged group (which never owns channels directly).
     */
    public function descendantChannels(): HasManyThrough
    {
        return $this->hasManyThrough(Channel::class, self::class, 'parent_id', 'group_id', 'id', 'id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * Channels belonging to this group or, when this is a merged group, to any of
     * its children. Merged groups never own channels directly.
     */
    public function allChannels(): HasMany
    {
        if ($this->is_merged) {
            return $this->hasMany(Channel::class, 'group_id', 'id')
                ->whereIn('group_id', $this->children()->select('id'));
        }

        return $this->channels();
    }

    /**
     * The group id playlist output should attribute this group's channels to:
     * the merged parent when folded in, otherwise this group.
     */
    protected function effectiveId(): Attribute
    {
        return Attribute::get(fn (): int => $this->parent_id ?? $this->id);
    }

    /**
     * The group name playlist output should display for this group's channels.
     */
    protected function effectiveName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->parent?->name ?? $this->name);
    }

    /**
     * The sort order playlist output should rank this group's channels by.
     */
    protected function effectiveSortOrder(): Attribute
    {
        return Attribute::get(fn () => $this->parent?->sort_order ?? $this->sort_order);
    }

    /**
     * Groups that a channel may be assigned to. Merged groups are containers only
     * and must never be selectable as a move/assignment target.
     */
    public function scopeAssignableTarget(Builder $query): Builder
    {
        return $query->where('is_merged', false);
    }

    /**
     * Guard the merged-group invariants on every save: a merged group cannot itself
     * be nested, and a parent must be a merged group in the same playlist and of the
     * same type. The Filament UI constrains the choices; this is defense in depth for
     * programmatic writes.
     */
    protected function assertMergedGroupRulesHold(): void
    {
        if ($this->is_merged && $this->parent_id !== null) {
            throw new InvalidArgumentException('A merged group cannot be nested inside another group.');
        }

        if ($this->parent_id === null) {
            return;
        }

        $parent = self::query()->find($this->parent_id);

        if (! $parent || ! $parent->is_merged) {
            throw new InvalidArgumentException('A group can only be folded into a merged group.');
        }

        if ($parent->playlist_id !== $this->playlist_id || $parent->type !== $this->type) {
            throw new InvalidArgumentException('A merged group must belong to the same playlist and type as its children.');
        }
    }

    public function enabled_channels(): HasMany
    {
        return $this->hasMany(Channel::class)
            ->where('enabled', true);
    }

    public function live_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): HasMany
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): HasMany
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }
}
