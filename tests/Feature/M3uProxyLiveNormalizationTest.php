<?php

use App\Filament\Clusters\Settings\Pages\ManageProxySettings;
use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    // The first application boot can resolve Settings before RefreshDatabase migrates.
    // Reload from the migrated fixture rather than saving default-only boot values.
    app(GeneralSettings::class)->refresh();
    Queue::fake();
    Http::preventStrayRequests();
    config([
        'proxy.m3u_proxy_host' => 'http://proxy.test',
        'proxy.m3u_proxy_port' => null,
        'proxy.m3u_proxy_token' => 'test-token',
        'proxy.normalize_live_output' => false,
        'cache.default' => 'array',
    ]);
    Redis::shouldReceive('exists')->andReturn(0);
    $this->user = User::factory()->admin()->create(['permissions' => ['use_proxy']]);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false, 'enable_proxy' => true,
        'available_streams' => 0, 'xtream' => false,
    ]);
    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true, 'is_vod' => false, 'title' => 'Normalization test', 'group_id' => null,
        'url' => 'http://provider.invalid/live/test/test/123.ts',
    ]);
    $this->proxyStreams = ['matching_streams' => [], 'total_matching' => 0];
    Http::fake([
        'proxy.test/streams/by-metadata*' => fn () => Http::response($this->proxyStreams),
        'proxy.test/streams' => Http::response(['stream_id' => 'new-outer']),
        'proxy.test/transcode' => Http::response(['stream_id' => 'transcoded']),
    ]);
});

it('defaults the independent normalization config to false', function () {
    expect(config('proxy.normalize_live_output'))->toBeFalse();
    $config = require base_path('config/proxy.php');
    expect($config['normalize_live_output'])->toBeFalse();
});

it('sends only an active live opt-in and preserves legacy payload otherwise', function (bool $global, ?array $options, bool $expected) {
    config(['proxy.normalize_live_output' => $global]);
    $this->channel->update(['proxy_options' => $options]);
    $url = app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);
    expect($url)->toContain('/stream/new-outer');
    Http::assertSent(function (Request $request) use ($expected) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/streams')) {
            return false;
        }
        expect(array_key_exists('normalize_live_output', $request->data()))->toBe($expected);
        if ($expected) {
            expect($request['normalize_live_output'])->toBeTrue();
        }
        expect($request['metadata'])->not->toHaveKey('transcoding')
            ->and($request['metadata']['original_channel_id'])->toBe($this->channel->id);

        return true;
    });
})->with([
    'legacy unset' => [false, null, false],
    'unset inherits global' => [true, null, true],
    'null inherits global' => [true, ['normalize_live_output' => null], true],
    'explicit false beats global' => [true, ['normalize_live_output' => false], false],
    'explicit true beats global' => [false, ['normalize_live_output' => true], true],
]);

it('preserves the entire direct API payload when explicitly disabled under a global opt-in', function (string $encoder) {
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    $service = app(M3uProxyService::class);
    $service->getChannelUrl($this->playlist, $this->channel);
    $legacy = Http::recorded(fn (Request $r) => $r->method() === 'POST')->last()[0]->data();
    config(['proxy.normalize_live_output' => true]);
    $this->channel->update(['proxy_options' => ['normalize_live_output' => false]]);
    $service->getChannelUrl($this->playlist, $this->channel);
    $disabled = Http::recorded(fn (Request $r) => $r->method() === 'POST')->last()[0]->data();
    expect($disabled)->toBe($legacy);
    $this->channel->update(['proxy_options' => ['normalize_live_output' => true]]);
    $service->getChannelUrl($this->playlist, $this->channel);
    $enabled = Http::recorded(fn (Request $r) => $r->method() === 'POST')->last()[0]->data();
    expect($enabled)->toEqual($legacy + ['normalize_live_output' => true, 'normalization_encoder' => $encoder]);
})->with(['cpu', 'vaapi', 'qsv']);

it('allows explicitly opted-in raw TS endpoints', function (string $suffix) {
    $this->channel->update(['url' => 'http://provider.invalid/live/test/test/123'.$suffix,
        'proxy_options' => ['normalize_live_output' => true]]);
    app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r['normalize_live_output'] ?? null) === true);
})->with(['', '.TS?token=synthetic', '.mpegts']);

it('excludes VOD HLS DASH other containers and catchup from normalization', function (bool $vod, string $extension, bool $catchup, string $encoder) {
    config(['proxy.normalize_live_output' => true]);
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    $this->channel->update(['is_vod' => $vod, 'url' => 'http://provider.invalid/live/test/test/123'.$extension]);
    $request = $catchup ? Illuminate\Http\Request::create('/test', 'GET', ['timeshift_date' => '2026-10-20:12-00', 'timeshift_duration' => 60]) : null;
    app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel, $request);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('normalize_live_output', $r->data()));
})->with([
    'vod ts' => [true, '.ts', false],
    'hls' => [false, '.m3u8', false],
    'dash' => [false, '.mpd', false],
    'mp4' => [false, '.mp4', false],
    'catchup' => [false, '.ts', true],
])->with(['cpu', 'vaapi', 'qsv']);

it('does not apply normalization to a Stream Profile', function (string $encoder) {
    config(['proxy.normalize_live_output' => true]);
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    $profile = StreamProfile::factory()->for($this->user)->create();
    app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel, null, $profile);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/transcode') && ! array_key_exists('normalize_live_output', $r->data()));
})->with(['cpu', 'vaapi', 'qsv']);

it('matches direct normalization mode before provider selection and capacity checks', function (bool $wanted) {
    config(['proxy.normalize_live_output' => $wanted]);
    $this->playlist->update(['profiles_enabled' => true, 'available_streams' => 1]);
    $metadata = ['type' => 'channel', 'id' => (string) $this->channel->id, 'playlist_uuid' => $this->playlist->uuid,
        'original_channel_id' => (string) $this->channel->id, 'original_playlist_uuid' => $this->playlist->uuid];
    $this->proxyStreams = [
        'matching_streams' => [
            ['stream_id' => 'wrong-mode', 'client_count' => 1, 'url' => $this->channel->url, 'metadata' => $metadata + ['normalize_live_output' => $wanted ? 'false' : 'true']],
            ['stream_id' => 'right-mode', 'client_count' => 1, 'url' => $this->channel->url, 'metadata' => $metadata + ($wanted ? ['normalize_live_output' => 'true'] : [])],
        ], 'total_matching' => 2,
    ];
    expect(app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel))->toContain('/stream/right-mode');
    Http::assertSentCount(1);
})->with([true, false]);

it('creates a new stream rather than reusing the opposite mode when capacity is available', function (bool $wanted) {
    config(['proxy.normalize_live_output' => $wanted]);
    $this->proxyStreams = [
        'matching_streams' => [[
            'stream_id' => 'wrong-mode', 'client_count' => 1, 'url' => $this->channel->url,
            'metadata' => ['original_channel_id' => (string) $this->channel->id,
                'original_playlist_uuid' => $this->playlist->uuid, 'id' => (string) $this->channel->id,
                'type' => 'channel', 'playlist_uuid' => $this->playlist->uuid, 'normalize_live_output' => $wanted ? 'false' : 'true'],
        ]], 'total_matching' => 1,
    ];
    expect(app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel))->toContain('/stream/new-outer');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && array_key_exists('normalize_live_output', $r->data()) === $wanted);
})->with([true, false]);

it('does not reuse an incompatible pool even when capacity is exhausted', function (bool $wanted) {
    config(['proxy.normalize_live_output' => $wanted]);
    $this->playlist->update(['available_streams' => 1]);
    $this->channel->unsetRelation('playlist');
    $this->proxyStreams = [
        'matching_streams' => [[
            'stream_id' => 'wrong-mode', 'client_count' => 1, 'url' => $this->channel->url,
            'metadata' => ['channel_id' => (string) $this->channel->id, 'original_channel_id' => (string) $this->channel->id,
                'original_playlist_uuid' => $this->playlist->uuid, 'id' => (string) $this->channel->id,
                'type' => 'channel', 'playlist_uuid' => $this->playlist->uuid, 'normalize_live_output' => $wanted ? 'false' : 'true'],
        ]], 'total_matching' => 1,
    ];
    expect(fn () => app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel))
        ->toThrow(HttpException::class);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
})->with([true, false]);

it('saves persistent global normalization and encoder settings ahead of ENV defaults', function (string $encoder) {
    $this->actingAs($this->user);
    config(['proxy.normalize_live_output' => true]);
    expect(app(GeneralSettings::class)->proxy_normalize_live_output)->toBeNull()
        ->and(app(GeneralSettings::class)->proxy_normalization_encoder)->toBe('cpu');
    Livewire::test(ManageProxySettings::class)
        ->assertFormFieldIsVisible('proxy_normalization_encoder')
        ->assertFormFieldExists('proxy_normalization_encoder', fn ($field) => array_keys($field->getOptions()) === ['cpu', 'vaapi', 'qsv'])
        ->assertFormSet(['proxy_normalize_live_output' => true, 'proxy_normalization_encoder' => 'cpu'])
        ->fillForm(['proxy_normalization_encoder' => $encoder])
        ->call('save')->assertHasNoFormErrors();
    Livewire::test(ManageProxySettings::class)
        ->set('data.proxy_normalize_live_output', false)
        ->call('save')->assertHasNoFormErrors();
    $settings = app(GeneralSettings::class)->refresh();
    expect($settings->proxy_normalize_live_output)->toBeFalse()
        ->and($settings->proxy_normalization_encoder)->toBe($encoder);
    Livewire::test(ManageProxySettings::class)
        ->assertFormSet(['proxy_normalize_live_output' => false, 'proxy_normalization_encoder' => $encoder]);
    app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('normalize_live_output', $r->data()));
    config(['proxy.normalize_live_output' => false]);
    Livewire::test(ManageProxySettings::class)
        ->fillForm(['proxy_normalize_live_output' => true, 'proxy_normalization_encoder' => $encoder])
        ->call('save')->assertHasNoFormErrors();
    expect(app(GeneralSettings::class)->refresh()->proxy_normalize_live_output)->toBeTrue();
    app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);
    $payload = Http::recorded(fn (Request $r) => $r->method() === 'POST')->last()[0]->data();
    expect($payload['normalize_live_output'])->toBeTrue()->and($payload['normalization_encoder'])->toBe($encoder)
        ->and($payload['metadata'])->not->toHaveKey('normalization_encoder');
})->with(['cpu', 'vaapi', 'qsv']);

it('only shows the encoder while normalization is enabled and retains the hidden selection', function (string $encoder) {
    $this->actingAs($this->user);
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalize_live_output = false;
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    Livewire::test(ManageProxySettings::class)
        ->assertFormFieldIsHidden('proxy_normalization_encoder')
        ->assertFormSet(['proxy_normalization_encoder' => $encoder])
        ->set('data.proxy_normalize_live_output', true)
        ->assertFormFieldIsVisible('proxy_normalization_encoder')
        ->assertFormFieldExists('proxy_normalize_live_output', fn ($field) => $field->getHintIcon() === 'heroicon-m-question-mark-circle' && filled($field->getHintIconTooltip()))
        ->assertFormFieldExists('proxy_normalization_encoder', fn ($field) => $field->getHintIcon() === 'heroicon-m-question-mark-circle' && filled($field->getHintIconTooltip()))
        ->assertFormSet(['proxy_normalization_encoder' => $encoder])
        ->set('data.proxy_normalize_live_output', false)
        ->assertFormFieldIsHidden('proxy_normalization_encoder')
        ->call('save')->assertHasNoFormErrors();
    expect(app(GeneralSettings::class)->refresh()->proxy_normalize_live_output)->toBeFalse()
        ->and(app(GeneralSettings::class)->proxy_normalization_encoder)->toBe($encoder);
    Livewire::test(ManageProxySettings::class)
        ->assertFormFieldIsHidden('proxy_normalization_encoder')
        ->assertFormSet(['proxy_normalization_encoder' => $encoder])
        ->set('data.proxy_normalize_live_output', true)
        ->call('save')->assertHasNoFormErrors();
    Livewire::test(ManageProxySettings::class)
        ->assertFormFieldIsVisible('proxy_normalization_encoder')
        ->assertFormSet(['proxy_normalize_live_output' => true, 'proxy_normalization_encoder' => $encoder]);
})->with(['cpu', 'vaapi', 'qsv']);

it('registers the QSV option and guidance in the English language catalogue', function () {
    $this->actingAs($this->user);
    app()->setLocale('en');
    $translations = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

    Livewire::test(ManageProxySettings::class)
        ->assertFormFieldExists('proxy_normalization_encoder', function ($field) use ($translations) {
            $label = $field->getOptions()['qsv'];
            $tooltip = $field->getHintIconTooltip();

            expect($label)->toBe('Intel Quick Sync (QSV)')
                ->and($tooltip)->toContain('QSV requires a compatible Intel GPU with Quick Sync support.')
                ->and($translations)->toHaveKey($label)
                ->and($translations)->toHaveKey($tooltip);

            return true;
        });
});

it('rejects unsupported encoders without persisting them', function (string $encoder) {
    $this->actingAs($this->user);
    Livewire::test(ManageProxySettings::class)
        ->fillForm(['proxy_normalize_live_output' => true, 'proxy_normalization_encoder' => $encoder])
        ->call('save')->assertHasFormErrors(['proxy_normalization_encoder']);
    expect(app(GeneralSettings::class)->refresh()->proxy_normalization_encoder)->toBe('cpu');
})->with(['cuda', 'CPU', 'vaapi -i arbitrary', 'QSV', 'qsv -i arbitrary', 'h264_qsv']);

it('falls back to CPU for unsupported persisted encoder values', function (string $encoder) {
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalize_live_output = true;
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && ($r['normalize_live_output'] ?? false) === true
        && ($r['normalization_encoder'] ?? null) === 'cpu');
})->with(['', 'cuda', 'QSV', 'qsv -i arbitrary', 'h264_qsv']);

it('keeps explicit channel overrides ahead of saved global settings', function (bool $global, bool $override) {
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalize_live_output = $global;
    $settings->proxy_normalization_encoder = 'vaapi';
    $settings->save();
    config(['proxy.normalize_live_output' => ! $global]);
    $this->channel->update(['proxy_options' => ['normalize_live_output' => $override]]);
    app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && array_key_exists('normalization_encoder', $r->data()) === $override);
})->with([[true, false], [false, true]]);

it('matches encoder metadata before capacity selection and treats legacy metadata as CPU', function (string $encoder, ?string $metadataEncoder, bool $reuse) {
    $settings = app(GeneralSettings::class);
    $settings->proxy_normalize_live_output = true;
    $settings->proxy_normalization_encoder = $encoder;
    $settings->save();
    $this->playlist->update(['profiles_enabled' => true, 'available_streams' => 1]);
    $this->channel->unsetRelation('playlist');
    $metadata = ['type' => 'channel', 'id' => (string) $this->channel->id, 'playlist_uuid' => $this->playlist->uuid,
        'original_channel_id' => (string) $this->channel->id, 'original_playlist_uuid' => $this->playlist->uuid,
        'normalize_live_output' => 'true'];
    if ($metadataEncoder !== null) {
        $metadata['normalization_encoder'] = $metadataEncoder;
    }
    $this->proxyStreams = ['matching_streams' => [['stream_id' => 'encoder-pool', 'client_count' => 1,
        'url' => $this->channel->url, 'metadata' => $metadata]], 'total_matching' => 1];
    if ($reuse) {
        expect(app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel))->toContain('/stream/encoder-pool');
        Http::assertSentCount(1);
    } else {
        expect(fn () => app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel))->toThrow(HttpException::class);
    }
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
})->with([
    ['cpu', null, true], ['cpu', 'cpu', true], ['cpu', 'vaapi', false],
    ['vaapi', null, false], ['vaapi', 'cpu', false], ['vaapi', 'vaapi', true],
    ['cpu', 'qsv', false], ['vaapi', 'qsv', false],
    ['qsv', null, false], ['qsv', 'cpu', false], ['qsv', 'vaapi', false], ['qsv', 'qsv', true],
]);

it('describes the global CPU VAAPI and QSV encoders in the channel normalization guidance', function () {
    $this->actingAs($this->user);
    app()->setLocale('en');
    $translations = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

    $helper = 'Inherit Settings → Proxy → Failover & Recovery, or explicitly enable/disable normalization for this channel. Uses the global CPU/VAAPI/QSV encoder. Only compatible live MPEG-TS inputs; ignored for VOD, HLS, DASH, catchup and Stream Profiles.';

    Livewire::test(EditChannel::class, ['record' => $this->channel->id])
        ->assertSee($helper);
    expect($translations)->toHaveKey($helper);
});

it('roundtrips all channel normalization states without losing proxy siblings', function () {
    config(['proxy.normalize_live_output' => true]);
    $this->channel->update(['proxy_options' => ['unrelated' => 'preserve']]);
    $this->actingAs($this->user);
    foreach ([null, '0', '1', null, '1', '0'] as $state) {
        $expected = $state === null ? null : $state === '1';
        Livewire::test(EditChannel::class, ['record' => $this->channel->id])
            ->fillForm(['proxy_options.normalize_live_output' => $state])
            ->call('save')->assertHasNoFormErrors();
        expect($this->channel->fresh()->proxy_options)->toMatchArray([
            'normalize_live_output' => $expected, 'unrelated' => 'preserve',
        ]);
        Livewire::test(EditChannel::class, ['record' => $this->channel->id])
            ->assertFormSet(['proxy_options.normalize_live_output' => $state])
            ->call('save')->assertHasNoFormErrors();
        expect($this->channel->fresh()->proxy_options['normalize_live_output'])->toBe($expected);
    }
});
