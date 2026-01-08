<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EpgChannel extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'epg_id' => 'integer',
        'user_id' => 'integer',
        'additional_display_names' => 'array',
    ];

    protected $with = ['epg'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function epg(): BelongsTo
    {
        return $this->belongsTo(Epg::class)
            ->select(['id', 'name']);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
