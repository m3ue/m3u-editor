<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TvNotification extends Model
{
    use MassPrunable;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'admin_only' => false,
    ];

    protected static function booted(): void
    {
        static::creating(fn ($model) => $model->id ??= (string) Str::orderedUuid());
    }

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'admin_only' => 'boolean',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where(function (Builder $q) {
            $q->whereNotNull('read_at')
                ->where('read_at', '<', now()->subDays(7));
        })->orWhere(function (Builder $q) {
            $q->where('created_at', '<', now()->subDays(30));
        });
    }
}
