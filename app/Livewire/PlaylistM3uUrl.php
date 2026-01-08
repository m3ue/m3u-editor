<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class PlaylistM3uUrl extends Component
{
    public Model $record;

    public function render()
    {
        return view('livewire.playlist-m3u-url');
    }
}
