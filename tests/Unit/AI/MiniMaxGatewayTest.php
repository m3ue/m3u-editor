<?php

use App\AI\MiniMaxProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;

beforeEach(function () {
    $this->provider = new MiniMaxProvider(
        config: [
            'name' => 'minimax',
            'driver' => 'minimax',
            'key' => 'test-key',
            'url' => 'https://api.minimax.io/v1',
        ],
        events: app(Dispatcher::class),
    );
});

it('generates text via the chat completions endpoint', function () {
    Http::fake([
        'api.minimax.io/*' => Http::response([
            'choices' => [
                [
                    'finish_reason' => 'stop',
                    'index' => 0,
                    'message' => [
                        'content' => 'Hello! I am MiniMax.',
                        'role' => 'assistant',
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]),
    ]);

    $response = $this->provider->textGateway()->generateText(
        provider: $this->provider,
        model: 'MiniMax-M2.7',
        instructions: 'You are a helpful assistant.',
        messages: [['role' => 'user', 'content' => 'Say hello.']],
    );

    expect($response->text)->toBe('Hello! I am MiniMax.');
});

it('streams text via the chat completions endpoint', function () {
    $sseBody = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"},\"index\":0,\"finish_reason\":null}]}\n"
        ."data: {\"choices\":[{\"delta\":{\"content\":\"!\"},\"index\":0,\"finish_reason\":null}]}\n"
        ."data: {\"choices\":[{\"delta\":{},\"index\":0,\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n"
        ."data: [DONE]\n";

    Http::fake([
        'api.minimax.io/*' => Http::response($sseBody),
    ]);

    $events = iterator_to_array($this->provider->textGateway()->streamText(
        invocationId: '550e8400-e29b-41d4-a716-446655440000',
        provider: $this->provider,
        model: 'MiniMax-M2.7',
        instructions: 'You are a helpful assistant.',
        messages: [['role' => 'user', 'content' => 'Say hello.']],
    ));

    expect($events)->toHaveCount(6);
    expect($events[0])->toBeInstanceOf(StreamStart::class);
    expect($events[1])->toBeInstanceOf(TextStart::class);
    expect($events[2])->toBeInstanceOf(TextDelta::class);
    expect($events[2]->delta)->toBe('Hello');
    expect($events[3])->toBeInstanceOf(TextDelta::class);
    expect($events[3]->delta)->toBe('!');
    expect($events[4])->toBeInstanceOf(TextEnd::class);
    expect($events[5])->toBeInstanceOf(StreamEnd::class);
});

it('handles error responses from the api', function () {
    Http::fake([
        'api.minimax.io/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid model specified.',
            ],
        ], 400),
    ]);

    $this->expectException(RequestException::class);

    $this->provider->textGateway()->generateText(
        provider: $this->provider,
        model: 'bad-model',
        instructions: null,
        messages: [['role' => 'user', 'content' => 'Hello']],
    );
});
