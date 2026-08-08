<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableColumnPreference extends Model
{
    protected $fillable = [
        'user_id',
        'table_key',
        'value',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'value' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
