<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;

class StreamPlayer extends Component
{
    public $streamUrl = '';

    public $streamFormat = 'ts';

    public $channelTitle = '';

    public $channelLogo = '';

    public $showModal = false;

    public $playerId;

    protected $listeners = ['openStreamPlayer' => 'openPlayer'];

    public function mount()
    {
        $this->playerId = 'stream-player-'.uniqid();
    }

    public function openPlayer($channelData = [])
    {
        // Debug the incoming channel data
        Log::info('StreamPlayer openPlayer called with data:', $channelData);

        $this->streamUrl = $channelData['url'] ?? '';
        $this->streamFormat = $channelData['format'] ?? 'ts';
        $this->channelTitle = Str::replace("'", '`', $channelData['title'] ?? ($channelData['name_custom'] ?? $channelData['name'] ?? 'Unknown Channel'));
        $this->channelLogo = $channelData['logo'] ?? $channelData['icon'] ?? '';
        $this->showModal = true;

        // Debug the final component state
        Log::info('StreamPlayer state after openPlayer:', [
            'streamUrl' => $this->streamUrl,
            'streamFormat' => $this->streamFormat,
            'channelTitle' => $this->channelTitle,
            'showModal' => $this->showModal,
        ]);
    }

    public function closePlayer()
    {
        Log::info('StreamPlayer closePlayer called');

        $this->showModal = false;
        $this->streamUrl = '';
        $this->streamFormat = 'ts';
        $this->channelTitle = '';
        $this->channelLogo = '';

        // Dispatch event to cleanup player
        $this->dispatch('cleanupPlayer', playerId: $this->playerId);
    }

    public function render()
    {
        return view('livewire.stream-player');
    }
}
