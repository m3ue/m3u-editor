<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class MediaFlowProxyUrl extends Component
{
    public Model $record;

    public function render()
    {
        return view('livewire.media-flow-proxy-url');
    }
}
