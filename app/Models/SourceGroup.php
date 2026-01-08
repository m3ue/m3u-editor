<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceGroup extends Model
{
    protected $table = 'source_groups';

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }
}
