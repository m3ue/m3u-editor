<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class MediaFlowProxyUrl extends Component
{
    public Model $record;

    /** 'links' = M3U + EPG only | 'xtream' = Xtream credentials only | 'all' = everything */
    public string $section = 'all';

    public function render()
    {
        return view('livewire.media-flow-proxy-url');
    }
}
