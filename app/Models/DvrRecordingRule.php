<?php

namespace App\Models;

use App\Enums\DvrRuleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DvrRecordingRule extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'dvr_setting_id',
        'type',
        'programme_id',
        'series_title',
        'channel_id',
        'epg_channel_id',
        'new_only',
        'priority',
        'start_early_seconds',
        'end_late_seconds',
        'keep_last',
        'enabled',
        'manual_start',
        'manual_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DvrRuleType::class,
            'new_only' => 'boolean',
            'priority' => 'integer',
            'start_early_seconds' => 'integer',
            'end_late_seconds' => 'integer',
            'keep_last' => 'integer',
            'enabled' => 'boolean',
            'manual_start' => 'datetime',
            'manual_end' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dvrSetting(): BelongsTo
    {
        return $this->belongsTo(DvrSetting::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function epgChannel(): BelongsTo
    {
        return $this->belongsTo(EpgChannel::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(DvrRecording::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(EpgProgramme::class, 'programme_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeSeries(Builder $query): Builder
    {
        return $query->where('type', DvrRuleType::Series->value);
    }

    public function scopeOnce(Builder $query): Builder
    {
        return $query->where('type', DvrRuleType::Once->value);
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query->where('type', DvrRuleType::Manual->value);
    }
}
