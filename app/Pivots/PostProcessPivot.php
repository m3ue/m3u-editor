<?php

namespace App\Pivots;

use App\Models\Epg;
use App\Models\Playlist;
use App\Models\PostProcess;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PostProcessPivot extends Pivot
{
    protected $table = 'processables';

    public function postProcess(): BelongsTo
    {
        return $this->belongsTo(PostProcess::class);
    }

    public function type(): string
    {
        switch ($this->processable_type) {
            case 'playlist':
            case Playlist::class:
                return 'Playlist';
            default:
                return 'EPG';
        }
    }

    public function model(): BelongsTo
    {
        switch ($this->processable_type) {
            case 'playlist':
            case Playlist::class:
                return $this->belongsTo(Playlist::class, 'processable_id');
            default:
                return $this->belongsTo(Epg::class, 'processable_id');
        }
    }

    public function processable(): MorphTo
    {
        return $this->morphTo();
    }
}
