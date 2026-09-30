<?php

namespace App\AI;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Providers\GeminiProvider;

class PatchedAiManager extends AiManager
{
    public function createGeminiDriver(array $config): GeminiProvider
    {
        return new GeminiProvider(
            new PatchedGeminiGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }
}
