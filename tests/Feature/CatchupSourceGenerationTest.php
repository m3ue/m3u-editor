<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CatchupSourceGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Playlist $playlist;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create([
            'disable_catchup' => false,
        ]);
    }

    public function test_catchup_source_points_to_internal_timeshift_when_xtream_format_enabled(): void
    {
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
    }

    public function test_catchup_source_generated_for_xtream_import_channel_with_tv_archive(): void
    {
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
    }

    public function test_catchup_source_not_output_when_disable_catchup_is_true(): void
    {
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
    }

    public function test_original_catchup_source_used_when_xtream_format_disabled(): void
    {
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
    }

    public function test_playlist_level_disable_m3u_xtream_format_bypasses_internal_url(): void
    {
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
    }

    public function test_catchup_source_uses_correct_extension_from_channel_url(): void
    {
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
    }
}
