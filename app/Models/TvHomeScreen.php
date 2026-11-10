<?php

namespace App\Models;

use App\Services\TvClientConfigService;
use App\Support\TvClientSettings;
use App\Support\TvHomeScreenLayout;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One playlist's M3U TV Home screen: section order, nav and Home toggles,
 * and each section's rows. Every login to the playlist gets it, Playlist
 * Auths included (through the playlist they're assigned to). In `default`
 * mode the user can still rearrange it in the app; `enforced` locks it.
 *
 * It belongs to exactly one Playlist, Custom Playlist, Merged Playlist or
 * Playlist Alias, set through `target_key` (`<column>:<id>`).
 */
class TvHomeScreen extends Model
{
    use HasFactory;

    /**
     * The playlist foreign keys, one per type a login can resolve to.
     *
     * @var array<string, class-string<Model>>
     */
    public const TARGET_COLUMNS = [
        'playlist_id' => Playlist::class,
        'custom_playlist_id' => CustomPlaylist::class,
        'merged_playlist_id' => MergedPlaylist::class,
        'playlist_alias_id' => PlaylistAlias::class,
    ];

    protected $fillable = [
        'user_id',
        'playlist_id',
        'custom_playlist_id',
        'merged_playlist_id',
        'playlist_alias_id',
        'target_key',
        'name',
        'description',
        'mode',
        'sections',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'playlist_id' => 'integer',
            'custom_playlist_id' => 'integer',
            'merged_playlist_id' => 'integer',
            'playlist_alias_id' => 'integer',
            'sections' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TvHomeScreen $homeScreen): void {
            $homeScreen->user_id ??= auth()->id();
        });

        static::saving(function (TvHomeScreen $homeScreen): void {
            $homeScreen->sections = TvHomeScreenLayout::normalize($homeScreen->sections);

            if (! in_array($homeScreen->mode, TvClientSettings::MODES, true)) {
                $homeScreen->mode = TvClientSettings::MODE_DEFAULT;
            }
        });

        // getOriginal() still holds the pre-save playlist inside `saved`, so a
        // Home screen moved to another playlist also clears the old one's.
        static::saved(function (TvHomeScreen $homeScreen): void {
            app(TvClientConfigService::class)->notifyPlaylists([
                ...$homeScreen->targetsOf($homeScreen->getAttributes()),
                ...$homeScreen->targetsOf($homeScreen->getOriginal()),
            ]);
        });

        static::deleted(function (TvHomeScreen $homeScreen): void {
            app(TvClientConfigService::class)->notifyPlaylists($homeScreen->targetsOf($homeScreen->getAttributes()));
        });
    }

    /**
     * The Home screen for a login's resolved playlist, if it has one.
     */
    public static function forPlaylist(?Model $playlist): ?self
    {
        if ($playlist === null) {
            return null;
        }

        $column = array_search($playlist::class, self::TARGET_COLUMNS, true);

        if ($column === false) {
            return null;
        }

        return static::query()->where($column, $playlist->getKey())->first();
    }

    /**
     * The playlist a `<column>:<id>` key names, if [$userId] owns it.
     */
    public static function resolveTargetKey(mixed $targetKey, ?int $userId): ?Model
    {
        if (! is_string($targetKey) || ! str_contains($targetKey, ':')) {
            return null;
        }

        [$column, $id] = explode(':', $targetKey, 2);
        $model = self::TARGET_COLUMNS[$column] ?? null;

        if ($model === null || ! ctype_digit($id)) {
            return null;
        }

        return $model::query()->where('user_id', $userId)->find((int) $id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function mergedPlaylist(): BelongsTo
    {
        return $this->belongsTo(MergedPlaylist::class);
    }

    public function playlistAlias(): BelongsTo
    {
        return $this->belongsTo(PlaylistAlias::class);
    }

    /**
     * The playlist this Home screen belongs to.
     */
    public function target(): Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null
    {
        return $this->playlist ?? $this->customPlaylist ?? $this->mergedPlaylist ?? $this->playlistAlias;
    }

    /**
     * `<column>:<id>` for the playlist it belongs to. Setting it points the
     * Home screen at that playlist and clears the other columns.
     */
    protected function targetKey(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                foreach (array_keys(self::TARGET_COLUMNS) as $column) {
                    if ($this->attributes[$column] ?? null) {
                        return $column.':'.$this->attributes[$column];
                    }
                }

                return null;
            },
            set: function (?string $value): array {
                $attributes = array_fill_keys(array_keys(self::TARGET_COLUMNS), null);
                [$column, $id] = array_pad(explode(':', (string) $value, 2), 2, null);

                if (array_key_exists((string) $column, $attributes) && ctype_digit((string) $id)) {
                    $attributes[$column] = (int) $id;
                }

                return $attributes;
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function layout(): array
    {
        return TvHomeScreenLayout::normalize($this->sections);
    }

    public function customRowCount(): int
    {
        return array_sum(array_map(
            fn (array $section): int => count($section['rows']) - 1,
            $this->layout(),
        ));
    }

    /**
     * The playlists named in a set of attributes, as [model class, id] pairs.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<array{0: class-string<Model>, 1: int}>
     */
    private function targetsOf(array $attributes): array
    {
        $targets = [];

        foreach (self::TARGET_COLUMNS as $column => $model) {
            if (filled($attributes[$column] ?? null)) {
                $targets[] = [$model, (int) $attributes[$column]];
            }
        }

        return $targets;
    }
}
