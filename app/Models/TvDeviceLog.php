<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A diagnostic log snapshot uploaded from the M3U TV app (Settings > General
 * > Logs & Diagnostics). The content is redacted on the device before upload.
 */
class TvDeviceLog extends Model
{
    use HasFactory, MassPrunable;

    /** Uploads kept per device; older ones are dropped on each new upload. */
    public const KEEP_PER_DEVICE = 10;

    /** Uploads older than this are pruned regardless of count. */
    public const RETENTION_DAYS = 30;

    /** Upload size cap, matching the app's own trim before sending. */
    public const MAX_BYTES = 1024 * 1024;

    protected $fillable = [
        'tv_device_id',
        'app_version',
        'size_bytes',
        'content',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(TvDevice::class, 'tv_device_id');
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
