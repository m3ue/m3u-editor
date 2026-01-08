<?php

namespace App\Livewire;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class XtreamApiInfo extends Component
{
    public Model $record;

    public function render()
    {
        return view('livewire.xtream-api-info');
    }
}
