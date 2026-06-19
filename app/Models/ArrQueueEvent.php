<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrQueueEvent extends Model
{
    use HasFactory;
    use MassPrunable;

    public function prunable(): Builder
    {
        return static::query()->where('last_event_at', '<', now()->subDays(30));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_event_at' => 'datetime',
            'size' => 'integer',
            'progress' => 'integer',
        ];
    }

    public function arrIntegration(): BelongsTo
    {
        return $this->belongsTo(ArrIntegration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
