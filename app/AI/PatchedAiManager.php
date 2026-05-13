<?php

namespace App\AI;

use App\AI\DeepSeek\PatchedDeepSeek;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\DeepSeekProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Prism\Prism\PrismManager;

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

    public function createGeminiDriver(array $config): GeminiProvider
    {
        return new GeminiProvider(
            new PatchedGeminiGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    public function createDeepseekDriver(array $config): DeepSeekProvider
    {
        // Register our patched DeepSeek provider with Prism so that reasoning_content
        // is correctly passed back to the API during tool-call multi-turn conversations.
        $this->app->make(PrismManager::class)->extend('deepseek', fn ($app, $cfg) => new PatchedDeepSeek(
            apiKey: $cfg['api_key'] ?? '',
            url: $cfg['url'] ?? '',
        ));

        return new DeepSeekProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }
}
