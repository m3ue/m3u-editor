<?php

namespace App\Livewire;

use Livewire\Component;

class StreamPlayer extends Component
{
    public $streamUrl = '';
    public $streamFormat = 'ts';
    public $channelTitle = '';
    public $channelLogo = '';
    public $showModal = false;
    public $playerId;

    protected $listeners = ['openStreamPlayer' => 'openPlayer', 'closeStreamPlayer' => 'closePlayer'];

    public function mount()
    {
        $this->playerId = 'stream-player-' . uniqid();
    }

    public function openPlayer($channelData = [])
    {
        $this->streamUrl = $channelData['url'] ?? '';
        $this->streamFormat = $channelData['format'] ?? 'ts';
        $this->channelTitle = $channelData['title'] ?? $channelData['display_name'] ?? 'Unknown Channel';
        $this->channelLogo = $channelData['logo'] ?? $channelData['icon'] ?? '';
        $this->showModal = true;
    }

    public function closePlayer()
    {
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
