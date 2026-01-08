<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceCategory extends Model
{
    protected $table = 'source_categories';

    public function playlist()
    {
        return $this->belongsTo(Playlist::class);
    }
}
