<?php

namespace App\AI\Gateway\MiniMax;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\Groq\Concerns\HandlesTextStreaming;
use Laravel\Ai\Gateway\Groq\Concerns\MapsAttachments;
use Laravel\Ai\Gateway\Groq\Concerns\MapsMessages;
use Laravel\Ai\Gateway\Groq\Concerns\MapsTools;
use Laravel\Ai\Gateway\Groq\Concerns\ParsesTextResponses;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\TextResponse;

class MiniMaxGateway implements TextGateway
{
    use Concerns\BuildsTextRequests;
    use HandlesRateLimiting;
    use HandlesTextStreaming;
    use InvokesTools;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesServerSentEvents;
    use ParsesTextResponses;

    public function __construct(protected Dispatcher $events)
    {
        $this->initializeToolCallbacks();
    }

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('chat/completions', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse(
            $data,
            $provider,
            filled($schema),
            $tools,
            $schema,
            $options,
            $instructions,
            $messages,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $body = $this->buildTextRequestBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
        );

        $body['stream'] = true;
        $body['stream_options'] = ['include_usage' => true];

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('chat/completions', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $response->getBody(),
            $instructions,
            $messages,
        );
    }

    /**
     * Get an HTTP client for the MiniMax API.
     */
    protected function client(TextProvider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the MiniMax API.
     */
    protected function baseUrl(TextProvider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.minimax.io/v1', '/');
    }
}
