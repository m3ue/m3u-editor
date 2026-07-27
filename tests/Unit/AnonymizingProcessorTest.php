<?php

use App\Logging\AddAnonymizingProcessor;
use App\Logging\AnonymizingProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

it('redacts nested case-variant secrets in the final formatted record', function () {
    $secret = 'NESTED-SENTINEL-SECRET';
    $record = new LogRecord(
        datetime: now()->toDateTimeImmutable(),
        channel: 'test',
        level: Level::Error,
        message: "Proxy exception for https://provider.test/{$secret}",
        context: [
            'channel_id' => 42,
            'status_code' => 502,
            'trace_id' => '123e4567-e89b-12d3-a456-426614174000',
            'nested' => [
                'aUtHoRiZaTiOn' => "Bearer {$secret}",
                'COOKIE' => $secret,
                'accessToken' => $secret,
                'api_KEY' => $secret,
                'Password' => $secret,
                'sourceUrl' => "https://provider.test/{$secret}",
                'request_headers' => ['X-API-Token' => $secret],
            ],
        ],
    );

    $formatted = (new JsonFormatter)->format((new AnonymizingProcessor)($record));

    expect($formatted)->toContain('channel_id')
        ->toContain('status_code')
        ->toContain('trace_id')
        ->not->toContain($secret)
        ->not->toContain('provider.test')
        ->not->toContain('aUtHoRiZaTiOn')
        ->not->toContain('accessToken')
        ->not->toContain('sourceUrl');
});

it('attaches redaction before every configured log handler', function () {
    $handlerChannels = collect(config('logging.channels'))
        ->reject(fn (array $channel, string $name): bool => in_array($name, ['stack', 'null', 'emergency'], true));

    expect($handlerChannels)->not->toBeEmpty();

    $handlerChannels->each(function (array $channel): void {
        expect($channel['tap'] ?? [])
            ->toContain(AddAnonymizingProcessor::class);
    });
});
