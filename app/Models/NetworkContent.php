<?php

namespace App\Models;

use App\Jobs\RegenerateNetworkSchedule;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkEpgService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NetworkContent extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'network_content';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'network_id' => 'integer',
        'sort_order' => 'integer',
        'weight' => 'integer',
        'pin_day_of_week' => 'string',
        'pin_time_of_day' => 'string',
        'chain_id' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // When content is created, queue a debounced schedule regeneration.
        // ShouldBeUnique on the job ensures bulk imports collapse into a single
        // regeneration rather than firing one per inserted row.
        static::created(function (NetworkContent $networkContent) {
            $network = $networkContent->network;

            if (! $network) {
                return;
            }

            if (! in_array($network->schedule_type, ['sequential', 'shuffle'])) {
                return;
            }

            if ($network->auto_regenerate_schedule === false) {
                return;
            }

            RegenerateNetworkSchedule::dispatch($network->id)->delay(3);
        });

        // When pin or chain fields change, queue a schedule regeneration.
        static::updated(function (NetworkContent $networkContent) {
            if (! $networkContent->wasChanged(['pin_day_of_week', 'pin_time_of_day', 'chain_id'])) {
                return;
            }

            $network = $networkContent->network;

            if (! $network || ! in_array($network->schedule_type, ['sequential', 'shuffle'])) {
                return;
            }

            if ($network->auto_regenerate_schedule === false) {
                return;
            }

            RegenerateNetworkSchedule::dispatch($network->id)->delay(3);
        });

        // When content is deleted, check if network has any remaining content
        static::deleted(function (NetworkContent $networkContent) {
            $network = $networkContent->network;

            if (! $network) {
                return;
            }

            // Check if this was the last content item
            $remainingContent = $network->networkContent()->count();

            if ($remainingContent === 0) {
                Log::info('Last content removed from network, triggering cleanup', [
                    'network_id' => $network->id,
                    'network_name' => $network->name,
                ]);

                // Stop any active broadcast
                if ($network->broadcast_enabled && $network->broadcast_pid) {
                    try {
                        app(NetworkBroadcastService::class)->stop($network);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to stop broadcast after content removal', [
                            'network_id' => $network->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Clear the schedule (will be empty now)
                $network->programmes()->delete();
                $network->update(['schedule_generated_at' => null]);

                // Regenerate EPG (will be empty but keep structure valid)
                try {
                    app(NetworkEpgService::class)->generateEpg($network);
                } catch (\Throwable $e) {
                    Log::warning('Failed to regenerate EPG after content removal', [
                        'network_id' => $network->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // A chain of one is meaningless — if this deletion left exactly one
            // member behind, clear its chain_id too.
            if ($networkContent->chain_id !== null) {
                $remaining = static::where('network_id', $network->id)
                    ->where('chain_id', $networkContent->chain_id)
                    ->get();

                if ($remaining->count() === 1) {
                    $remaining->first()->update(['chain_id' => null]);
                }
            }
        });
    }

    /**
     * Get the network this content belongs to.
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Get the content item (Episode or Channel/VOD).
     */
    public function contentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the duration of this content in seconds.
     */
    public function getDurationSecondsAttribute(): int
    {
        $content = $this->contentable;
        $seconds = 0;

        if (! $content) {
            return $seconds;
        }

        // For Episodes, duration is stored in info
        if ($content instanceof Episode) {
            // First try duration_secs (already in seconds)
            if (isset($content->info['duration_secs']) && is_numeric($content->info['duration_secs'])) {
                $seconds = (int) $content->info['duration_secs'];
            } else {
                // Try duration field - could be HH:MM:SS format or seconds
                $duration = $content->info['duration'] ?? null;
                if ($duration) {
                    $seconds = $this->parseDuration($duration);
                }
            }
        } elseif ($content instanceof Channel) {
            // First check info directly on channel
            if (isset($content->info['duration_secs']) && is_numeric($content->info['duration_secs'])) {
                $seconds = (int) $content->info['duration_secs'];
            } else {
                $duration = $content->info['duration'] ?? null;
                if ($duration) {
                    $seconds = $this->parseDuration($duration);
                } else {
                    // Fallback to movie_data structure
                    $secs = $content->movie_data['info']['duration_secs'] ?? null;
                    if ($secs && is_numeric($secs)) {
                        $seconds = (int) $secs;
                    } else {
                        $duration = $content->movie_data['info']['duration'] ?? null;
                        if ($duration) {
                            $seconds = $this->parseDuration($duration);
                        }
                    }
                }
            }
        }

        return $seconds;
    }

    /**
     * Parse duration from various formats to seconds.
     */
    protected function parseDuration(mixed $duration): int
    {
        $seconds = 0;

        if (is_numeric($duration)) {
            $seconds = (int) $duration;
        } elseif (is_string($duration)) {
            // Handle HH:MM:SS or MM:SS format
            if (preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $matches)) {
                $seconds = ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
            } elseif (preg_match('/^(\d+):(\d+)$/', $duration, $matches)) {
                $seconds = ((int) $matches[1] * 60) + (int) $matches[2];
            }
        }

        return $seconds;
    }

    /**
     * Get the title of this content.
     */
    public function getTitleAttribute(): string
    {
        $content = $this->contentable;

        if (! $content) {
            return 'Unknown';
        }

        if ($content instanceof Episode) {
            $series = $content->series;
            $seasonNum = $content->season ?? 1;
            $episodeNum = $content->episode_num ?? 1;

            return $series
                ? "{$series->name} S{$seasonNum}E{$episodeNum} - {$content->title}"
                : $content->title;
        }

        return $content->name ?? $content->title ?? 'Unknown';
    }

    /**
     * Whether this content item is pinned to a specific day and time.
     */
    public function isPinned(): bool
    {
        return $this->pin_day_of_week !== null && $this->pin_time_of_day !== null;
    }

    /**
     * Whether this content item is chained with other items to always play consecutively.
     */
    public function isChained(): bool
    {
        return $this->chain_id !== null;
    }

    /**
     * All members of this item's chain (including itself), ordered by sort_order.
     * Returns just itself when not chained.
     */
    public function chainMembers(): Collection
    {
        if (! $this->isChained()) {
            return collect([$this]);
        }

        return static::where('network_id', $this->network_id)
            ->where('chain_id', $this->chain_id)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The lead (first-playing) member of this item's chain, resolved dynamically
     * by lowest sort_order — not by matching chain_id to an id.
     */
    public function chainLead(): self
    {
        return $this->isChained() ? $this->chainMembers()->first() : $this;
    }

    /**
     * Find or create content entry for a network.
     */
    public static function findForNetwork(Network $network, Model $content): ?self
    {
        return self::where('network_id', $network->id)
            ->where('contentable_type', get_class($content))
            ->where('contentable_id', $content->id)
            ->first();
    }
}
