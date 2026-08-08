<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'disable_catchup' => false,
    ]);
});

it('points catchup source to internal timeshift when xtream format enabled', function () {
    config(['app.disable_m3u_xtream_format' => false]);

    $channel = Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'catchup' => 'default',
        'catchup_source' => null,
        'url' => 'http://provider.com/live/user/pass/123.ts',
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    // Should contain catchup attribute
    $this->assertStringContainsString('catchup="default"', $content);

    // Should generate an internal catchup-source pointing to /timeshift/ endpoint
    $this->assertStringContainsString('catchup-source="', $content);
    $this->assertStringContainsString('/timeshift/', $content);
    $this->assertStringContainsString("/{$channel->id}.", $content);

    // Should use standard {duration} and {start} placeholders
    $this->assertStringContainsString('{duration}', $content);
    $this->assertStringContainsString('{start}', $content);

    // Should NOT contain the original provider URL in catchup-source
    $this->assertStringNotContainsString('provider.com', $content);
});

it('generates catchup source for xtream import channel with tv archive', function () {
    config(['app.disable_m3u_xtream_format' => false]);

    // Xtream imports store catchup as integer 1, no catchup_source
    Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'catchup' => '1',
        'catchup_source' => null,
        'url' => 'http://provider.com/live/user/pass/456.ts',
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    // Should generate an internal catchup-source even without catchup_source in DB
    $this->assertStringContainsString('catchup-source="', $content);
    $this->assertStringContainsString('/timeshift/', $content);
});

it('does not output catchup source when disable catchup is true', function () {
    $this->playlist->update(['disable_catchup' => true]);

    Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'catchup' => 'default',
        'catchup_source' => 'http://provider.com/streaming/timeshift.php?stream={id}&start={utc_start}&duration={duration}',
        'url' => 'http://provider.com/live/user/pass/789.ts',
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    // With disable_catchup, neither catchup nor catchup-source should appear
    $this->assertStringNotContainsString('catchup=', $content);
    $this->assertStringNotContainsString('catchup-source=', $content);
});

it('uses the original catchup source when xtream format disabled', function () {
    config(['app.disable_m3u_xtream_format' => true]);

    $originalSource = 'http://provider.com/streaming/timeshift.php?stream={id}&start={utc_start}&duration={duration}';

    Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'catchup' => 'default',
        'catchup_source' => $originalSource,
        'url' => 'http://provider.com/live/user/pass/101.ts',
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    // When Xtream format is disabled (and proxy is also disabled), use original catchup-source
    $this->assertStringContainsString("catchup-source=\"{$originalSource}\"", $content);
});

it('bypasses the internal url when playlist level disable m3u xtream format is set', function () {
    config(['app.disable_m3u_xtream_format' => false]);
    $this->playlist->update(['disable_m3u_xtream_format' => true]);

    $originalSource = 'http://provider.com/streaming/timeshift.php?stream={id}&start={utc_start}&duration={duration}';

    Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'catchup' => 'default',
        'catchup_source' => $originalSource,
        'url' => 'http://provider.com/live/user/pass/202.ts',
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    // With playlist-level disable, original catchup-source should be used
    $this->assertStringContainsString("catchup-source=\"{$originalSource}\"", $content);
    // And the raw provider URL should appear (not the internal Xtream format)
    $this->assertStringContainsString('provider.com/live/user/pass/202.ts', $content);
});

it('uses the correct extension from the channel url for catchup source', function () {
    config(['app.disable_m3u_xtream_format' => false]);

    $channel = Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'catchup' => 'default',
        'catchup_source' => null,
        'url' => 'http://provider.com/live/user/pass/123.m3u8',
    ]);

    $response = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]));

    $response->assertSuccessful();
    $content = $response->streamedContent();

    // Should use m3u8 extension in the catchup-source
    $this->assertStringContainsString("/{$channel->id}.m3u8", $content);
});
