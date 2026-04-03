<?php

namespace App\AI;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Providers\OpenAiProvider;

class PatchedAiManager extends AiManager
{
    public function createOpenaiDriver(array $config): OpenAiProvider
    {
        return new OpenAiProvider(
            new PatchedOpenAiGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }
}
