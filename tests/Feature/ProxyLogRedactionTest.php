<?php

use App\Console\Commands\RegisterM3uProxyWebhook;
use App\Enums\TranscodeMode;
use App\Logging\AlertsHandler;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\StreamProfile;
use App\Services\AlertService;
use App\Services\M3uProxyService;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    config()->set('proxy.m3u_proxy_host', 'http://proxy.test');
    config()->set('proxy.m3u_proxy_port', null);
    config()->set('proxy.m3u_proxy_token', 'proxy-api-token');
    config()->set('logging.default', 'proxy-redaction-test');
    config()->set('logging.channels.proxy-redaction-test', [
        'driver' => 'monolog',
        'level' => 'debug',
        'handler' => TestHandler::class,
        'formatter' => JsonFormatter::class,
        'tap' => config('logging.channels.stdout.tap', []),
    ]);

    Log::forgetChannel('proxy-redaction-test');

    $this->proxyLogHandler = Log::channel('proxy-redaction-test')->getHandlers()[0];
});

function formattedProxyLogs(TestHandler $handler): string
{
    return implode("\n", array_map(
        static fn ($record): string => (string) $record->formatted,
        $handler->getRecords(),
    ));
}

it('does not log transcode payloads or proxy response bodies', function () {
    $secret = 'TRANSCODE-SENTINEL-SECRET';
    $profile = StreamProfile::factory()->create();

    Http::fake([
        '*/transcode' => Http::response([
            'error' => 'transcode failed',
            'Authorization' => "Bearer {$secret}",
            'source_url' => "https://provider.test/live/{$secret}",
        ], 502),
    ]);

    $service = new class extends M3uProxyService
    {
        public function createTestTranscode(StreamProfile $profile, string $secret): void
        {
            $this->createTranscodedStream(
                "https://provider.test/live/{$secret}",
                $profile,
                ["https://failover.test/{$secret}"],
                headers: [['header' => 'Cookie', 'value' => "session={$secret}"]],
                metadata: ['channel_id' => 42],
            );
        }
    };

    expect(fn () => $service->createTestTranscode($profile, $secret))
        ->toThrow(Exception::class);

    $logs = formattedProxyLogs($this->proxyLogHandler);

    expect($logs)->toContain('Error creating transcoded stream on m3u-proxy')
        ->not->toContain($secret)
        ->not->toContain('source_url')
        ->not->toContain('Authorization');
});

it('does not log broadcast payloads or source URLs', function () {
    $secret = 'BROADCAST-PAYLOAD-SENTINEL';
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'transcode_mode' => TranscodeMode::Direct->value,
    ]);
    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    Http::fake([
        '*/broadcast/*/start' => Http::response(['status' => 'started', 'ffmpeg_pid' => 12345]),
    ]);

    (new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy'))->invoke(
        app(NetworkBroadcastService::class),
        $network,
        "https://provider.test/video?api_key={$secret}",
        0,
        3600,
        $programme,
    );

    expect(formattedProxyLogs($this->proxyLogHandler))
        ->toContain('Starting broadcast via proxy')
        ->toContain((string) $network->id)
        ->not->toContain($secret)
        ->not->toContain('stream_url')
        ->not->toContain('callback_url')
        ->not->toContain('payload');
});

it('does not log failover response bodies', function () {
    $secret = 'FAILOVER-RESPONSE-SENTINEL';

    Http::fake([
        '*/streams/stream-123/failover' => Http::response([
            'message' => "failure token={$secret}",
            'headers' => ['Cookie' => $secret],
        ], 503),
    ]);

    expect(app(M3uProxyService::class)->triggerFailover('stream-123'))->toBeFalse();

    expect(formattedProxyLogs($this->proxyLogHandler))
        ->toContain('Failed to trigger failover')
        ->not->toContain($secret)
        ->toContain('503');
});

it('allowlists webhook callback diagnostics', function () {
    $secret = 'CALLBACK-SENTINEL-SECRET';

    $this->postJson('/api/m3u-proxy/webhooks', [
        'event_type' => 'stream_started',
        'stream_id' => 'stream-456',
        'data' => [
            'type' => 'channel',
            'id' => 99,
            'trace_id' => '123e4567-e89b-12d3-a456-426614174000',
            'nested' => [
                'aUtHoRiZaTiOn' => "Bearer {$secret}",
                'COOKIE' => $secret,
                'accessToken' => $secret,
                'api_KEY' => $secret,
                'Password' => $secret,
                'source' => "https://provider.test/{$secret}",
            ],
        ],
    ])->assertOk();

    expect(formattedProxyLogs($this->proxyLogHandler))
        ->toContain('stream_started')
        ->toContain('stream-456')
        ->not->toContain($secret)
        ->not->toContain('aUtHoRiZaTiOn')
        ->not->toContain('accessToken');
});

it('does not log failover request or exception payloads', function () {
    $secret = 'EXCEPTION-SENTINEL-SECRET';

    $this->mock(M3uProxyService::class)
        ->shouldReceive('resolveFailoverUrl')
        ->once()
        ->andThrow(new Exception("Failed for https://provider.test/{$secret}?token={$secret}"));

    $this->postJson('/api/m3u-proxy/failover-resolver', [
        'current_url' => "https://provider.test/live/{$secret}",
        'metadata' => [
            'id' => 42,
            'playlist_uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'headers' => ['Authorization' => "Bearer {$secret}"],
        ],
        'current_failover_index' => 2,
        'status_code' => 401,
        'Cookie' => $secret,
    ])->assertInternalServerError();

    expect(formattedProxyLogs($this->proxyLogHandler))
        ->toContain('Error resolving failover')
        ->toContain('42')
        ->toContain('401')
        ->not->toContain($secret)
        ->not->toContain('current_url')
        ->not->toContain('Authorization');
});

it('does not log webhook URLs when registering callbacks', function () {
    $secret = 'WEBHOOK-URL-SENTINEL';

    config()->set('proxy.m3u_resolver_url', "https://editor.test/callback/{$secret}");
    Http::fake([
        '*/webhooks' => Http::sequence()
            ->push(['webhooks' => []])
            ->push(['status' => 'ok']),
    ]);

    $this->artisan(RegisterM3uProxyWebhook::class)->assertSuccessful();

    expect(formattedProxyLogs($this->proxyLogHandler))
        ->toContain('M3U Proxy webhook registered')
        ->not->toContain($secret)
        ->not->toContain('webhook_url');
});

it('redacts the final message sent by the alerts handler', function () {
    $secret = 'ALERT-SENTINEL-SECRET';
    $alertMessage = null;

    $this->mock(AlertService::class, function ($mock) use (&$alertMessage) {
        $mock->shouldReceive('isEnabled')->once()->andReturnTrue();
        $mock->shouldReceive('send')->once()->withArgs(function (string $message) use (&$alertMessage): bool {
            $alertMessage = $message;

            return true;
        });
    });

    config()->set('logging.channels.proxy-alert-test', [
        'driver' => 'monolog',
        'level' => 'error',
        'handler' => AlertsHandler::class,
        'tap' => config('logging.channels.alerts.tap', []),
    ]);
    Log::forgetChannel('proxy-alert-test');

    Log::channel('proxy-alert-test')->error('Proxy failed', [
        'channel_id' => 42,
        'HeAdErS' => ['Authorization' => "Bearer {$secret}"],
        'sourceUrl' => "https://provider.test/{$secret}",
    ]);

    expect($alertMessage)->toContain('Proxy failed')
        ->toContain('channel_id')
        ->not->toContain($secret)
        ->not->toContain('Authorization')
        ->not->toContain('provider.test');
});
