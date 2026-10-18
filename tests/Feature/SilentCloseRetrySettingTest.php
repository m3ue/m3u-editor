<?php

/**
 * The "Retry the same stream before failing over" proxy setting is sent to m3u-proxy as
 * `silent_close_retry_window` on stream creation: the configured window when enabled,
 * and 0 (the proxy's "off" value) when disabled.
 */

use App\Filament\Clusters\Settings\Pages\ManageProxySettings;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->admin()->create(['permissions' => ['use_proxy']]);
    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765]);

    $this->createStreamPayload = function (): array {
        $playlist = Playlist::factory()->for($this->user)->create([
            'profiles_enabled' => false,
            'enable_proxy' => true,
            'available_streams' => 0,
            'xtream' => false,
        ]);

        $channel = Channel::factory()->for($this->user)->for($playlist)->create([
            'enabled' => true,
            'url' => 'http://provider.example/live/user/pass/1234.ts',
        ]);

        Http::fake([
            '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
            '*/streams' => Http::response(['stream_id' => 'silent-close-stream-id']),
        ]);

        app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

        $payload = null;
        Http::assertSent(function (Request $request) use (&$payload) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/streams')) {
                return false;
            }
            $payload = $request->data();

            return true;
        });

        return $payload;
    };
});

it('defaults the same-URL retry to disabled with a 60 second window', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->silent_close_retry_enabled)->toBeFalse()
        ->and($settings->silent_close_retry_window)->toBe(60);
});

it('sends the configured retry window to the proxy when enabled', function () {
    $settings = app(GeneralSettings::class);
    $settings->silent_close_retry_enabled = true;
    $settings->silent_close_retry_window = 90;
    $settings->save();

    expect(($this->createStreamPayload)()['silent_close_retry_window'] ?? null)->toBe(90);
});

it('sends a zero retry window to the proxy by default so the proxy keeps its old failover behavior', function () {
    expect(($this->createStreamPayload)()['silent_close_retry_window'] ?? null)->toBe(0);
});

it('sends a zero retry window to the proxy when disabled', function () {
    $settings = app(GeneralSettings::class);
    $settings->silent_close_retry_enabled = false;
    $settings->save();

    expect(($this->createStreamPayload)()['silent_close_retry_window'] ?? null)->toBe(0);
});

it('saves the retry settings from the proxy settings page', function () {
    $this->actingAs($this->user);

    Livewire::test(ManageProxySettings::class)
        ->fillForm([
            'silent_close_retry_enabled' => true,
            'silent_close_retry_window' => 45,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = app(GeneralSettings::class)->refresh();

    expect($fresh->silent_close_retry_enabled)->toBeTrue()
        ->and((int) $fresh->silent_close_retry_window)->toBe(45);
});
