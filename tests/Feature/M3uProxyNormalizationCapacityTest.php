<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    // Discard Settings resolved before the first RefreshDatabase migration.
    app(GeneralSettings::class)->refresh();
    Queue::fake();
    Http::preventStrayRequests();
    Redis::shouldReceive('exists')->andReturn(0);
    config(['proxy.m3u_proxy_host' => 'http://proxy.test', 'proxy.m3u_proxy_port' => null,
        'proxy.normalize_live_output' => true, 'cache.default' => 'array']);
    $this->user = User::factory()->admin()->create(['permissions' => ['use_proxy']]);
    $this->primary = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false, 'enable_proxy' => true, 'available_streams' => 1, 'xtream' => false,
    ]);
    $this->backup = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false, 'enable_proxy' => true, 'available_streams' => 0, 'xtream' => false,
    ]);
    $this->channel = Channel::factory()->for($this->user)->for($this->primary, 'playlist')->create([
        'is_vod' => false, 'url' => 'http://provider.invalid/primary.ts', 'group_id' => null,
    ]);
    $this->fallback = Channel::factory()->for($this->user)->for($this->backup, 'playlist')->create([
        'is_vod' => false, 'url' => 'http://provider.invalid/backup.ts', 'group_id' => null,
    ]);
    $this->actingAs($this->user);
    $this->channel->failovers()->create(['channel_failover_id' => $this->fallback->id, 'sort' => 0, 'user_id' => $this->user->id]);
    $this->pool = null;
    Http::fake(function (Request $request) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/streams')) {
            $metadata = array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $request['metadata']);
            if ($request['normalize_live_output'] ?? false) {
                $metadata['normalize_live_output'] = 'true';
                // Mirror server-owned outer metadata from the worker contract.
                $metadata['normalization_encoder'] = $request['normalization_encoder'] ?? 'cpu';
            }
            $this->pool = ['stream_id' => 'capacity-backup', 'client_count' => 1,
                'metadata' => $metadata, 'url' => $request['url']];

            return Http::response(['stream_id' => 'capacity-backup']);
        }
        if ($request->method() === 'GET' && str_contains($request->url(), '/streams/by-metadata')) {
            if ($request['field'] === 'original_channel_id') {
                $streams = $this->pool ? [$this->pool] : [];
            } elseif ($request['value'] === $this->primary->uuid) {
                $streams = [['stream_id' => 'occupied-primary', 'client_count' => 1,
                    'metadata' => ['type' => 'channel', 'id' => '99999']]];
            } else {
                $streams = $this->pool ? [$this->pool] : [];
            }

            return Http::response(['matching_streams' => $streams, 'total_matching' => count($streams)]);
        }
        throw new RuntimeException('Unexpected fake request: '.$request->method().' '.$request->url());
    });
});

it('uses the selected capacity failover VOD flag for normalization', function (string $extension, bool $vod, bool $expected) {
    $this->fallback->update(['is_vod' => $vod, 'url' => 'http://provider.invalid/backup'.$extension]);
    expect(app(M3uProxyService::class)->getChannelUrl($this->primary, $this->channel))->toContain('/stream/capacity-backup');
    Http::assertSent(function (Request $request) use ($expected) {
        if ($request->method() !== 'POST') {
            return false;
        }
        expect($request['url'])->toBe($this->fallback->url)
            ->and($request['metadata']['id'])->toBe($this->fallback->id)
            ->and(array_key_exists('normalize_live_output', $request->data()))->toBe($expected);

        return true;
    });
})->with([
    'VOD TS' => ['.ts', true, false],
    'VOD extensionless' => ['', true, false],
    'live TS control' => ['.ts', false, true],
    'live extensionless control' => ['', false, true],
]);

it('reuses the created capacity failover pool before checking saturated providers', function (string $extension, bool $vod, bool $normalized, string $encoder) {
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    $this->fallback->update(['url' => 'http://provider.invalid/backup'.$extension, 'is_vod' => $vod]);
    $service = app(M3uProxyService::class);
    expect($service->getChannelUrl($this->primary, $this->channel))->toContain('capacity-backup');
    expect($this->pool['url'])->toBe($this->fallback->url)
        ->and(($this->pool['metadata']['normalize_live_output'] ?? null) === 'true')->toBe($normalized);
    if ($normalized) {
        expect($this->pool['metadata']['normalization_encoder'])->toBe($encoder);
    }
    $this->backup->update(['available_streams' => 1]);
    $before = count(Http::recorded());
    expect($service->getChannelUrl($this->primary, $this->channel))->toContain('capacity-backup');
    expect(count(Http::recorded()) - $before)->toBe(1);
    expect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))->toHaveCount(1);
})->with([
    'HLS CPU' => ['.m3u8', false, false, 'cpu'],
    'DASH CPU' => ['.mpd', false, false, 'cpu'],
    'VOD TS CPU' => ['.ts', true, false, 'cpu'],
    'VOD extensionless CPU' => ['', true, false, 'cpu'],
    'normalized live TS CPU' => ['.ts', false, true, 'cpu'],
    'HLS VAAPI' => ['.m3u8', false, false, 'vaapi'],
    'DASH VAAPI' => ['.mpd', false, false, 'vaapi'],
    'VOD TS VAAPI' => ['.ts', true, false, 'vaapi'],
    'VOD extensionless VAAPI' => ['', true, false, 'vaapi'],
    'normalized live TS VAAPI' => ['.ts', false, true, 'vaapi'],
    'HLS QSV' => ['.m3u8', false, false, 'qsv'],
    'DASH QSV' => ['.mpd', false, false, 'qsv'],
    'VOD TS QSV' => ['.ts', true, false, 'qsv'],
    'VOD extensionless QSV' => ['', true, false, 'qsv'],
    'normalized live TS QSV' => ['.ts', false, true, 'qsv'],
]);

it('does not reuse the other encoder after a capacity failover', function (bool $saturated, string $encoder) {
    $service = app(M3uProxyService::class);
    $service->getChannelUrl($this->primary, $this->channel);
    expect($this->pool['metadata']['normalization_encoder'])->toBe('cpu');
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    if ($saturated) {
        $this->backup->update(['available_streams' => 1]);
        expect(fn () => $service->getChannelUrl($this->primary, $this->channel))->toThrow(HttpException::class);
        expect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))->toHaveCount(1);
    } else {
        $service->getChannelUrl($this->primary, $this->channel);
        expect($this->pool['metadata']['normalization_encoder'])->toBe($encoder);
        expect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))->toHaveCount(2);
    }
})->with([true, false])->with(['vaapi', 'qsv']);
it('rejects an unnormalized normalizable TS pool at capacity', function () {
    $this->backup->update(['available_streams' => 1]);
    $this->pool = ['stream_id' => 'legacy-ts', 'client_count' => 1, 'url' => $this->fallback->url,
        'metadata' => ['type' => 'channel', 'id' => (string) $this->fallback->id,
            'playlist_uuid' => $this->backup->uuid, 'original_channel_id' => (string) $this->channel->id,
            'original_playlist_uuid' => $this->primary->uuid]];
    expect(fn () => app(M3uProxyService::class)->getChannelUrl($this->primary, $this->channel))->toThrow(HttpException::class);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('uses the actual pooled URL rather than a changed channel URL', function () {
    $this->backup->update(['available_streams' => 1]);
    $this->pool = ['stream_id' => 'actual-hls', 'client_count' => 1, 'url' => 'http://provider.invalid/backup.m3u8',
        'metadata' => ['type' => 'channel', 'id' => (string) $this->fallback->id,
            'playlist_uuid' => $this->backup->uuid, 'original_channel_id' => (string) $this->channel->id,
            'original_playlist_uuid' => $this->primary->uuid]];
    expect(app(M3uProxyService::class)->getChannelUrl($this->primary, $this->channel))->toContain('actual-hls');
    Http::assertSentCount(1);
});

it('preserves legacy pool reuse without source details when normalization is disabled', function () {
    $this->channel->update(['proxy_options' => ['normalize_live_output' => false]]);
    $this->pool = ['stream_id' => 'legacy', 'client_count' => 1,
        'metadata' => ['original_channel_id' => (string) $this->channel->id,
            'original_playlist_uuid' => $this->primary->uuid]];
    expect(app(M3uProxyService::class)->getChannelUrl($this->primary, $this->channel))->toContain('legacy');
    Http::assertSentCount(1);
});

it('does not treat missing VOD attributes as live', function () {
    $partial = Channel::query()->select(['id', 'playlist_id', 'url'])->findOrFail($this->channel->id);
    $this->primary->update(['available_streams' => 0]);
    app(M3uProxyService::class)->getChannelUrl($this->primary, $partial);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('normalize_live_output', $r->data()));
});

it('fails closed on incomplete actual source metadata under opt-in', function (string $missing) {
    $this->backup->update(['available_streams' => 1]);
    $this->pool = ['stream_id' => 'incomplete', 'client_count' => 1, 'url' => $this->fallback->url,
        'metadata' => ['type' => 'channel', 'id' => (string) $this->fallback->id,
            'playlist_uuid' => $this->backup->uuid, 'original_channel_id' => (string) $this->channel->id,
            'original_playlist_uuid' => $this->primary->uuid, 'normalize_live_output' => 'true']];
    if ($missing === 'url') {
        unset($this->pool['url']);
    } else {
        unset($this->pool['metadata'][$missing]);
    }
    expect(fn () => app(M3uProxyService::class)->getChannelUrl($this->primary, $this->channel))->toThrow(HttpException::class);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
})->with(['url', 'type', 'id', 'playlist_uuid']);
