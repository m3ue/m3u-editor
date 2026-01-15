<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpgApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Playlist $playlist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);
    }

    public function test_can_get_epg_data_for_playlist_without_epg_mapping()
    {
        // Create a group
        $group = Group::factory()->create([
            'name' => 'Test Group',
            'user_id' => $this->user->id,
        ]);

        // Create channels without EPG mapping (dummy EPG should be generated)
        // Explicitly set channel field to predictable values
        $channels = collect();
        for ($i = 1; $i <= 3; $i++) {
            $channels->push(Channel::factory()->create([
                'playlist_id' => $this->playlist->id,
                'user_id' => $this->user->id,
                'group_id' => $group->id,
                'group' => 'Test Group', // Also set the string group field for dummy EPG category
                'enabled' => true,
                'is_vod' => false,
                'channel' => 100 + $i, // Predictable channel numbers
            ]));
        }

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'playlist' => ['id', 'name', 'uuid', 'type'],
                'date_range' => ['start', 'end'],
                'pagination',
                'channels',
                'programmes',
                'cache_info',
            ]);

        // Verify dummy EPG programmes were generated for channels without EPG
        $data = $response->json();
        $this->assertNotEmpty($data['programmes'], 'Dummy EPG programmes should be generated');

        // Check that programmes were generated for each channel
        foreach ($channels as $channel) {
            $channel->refresh(); // Refresh to get latest data
            $channelId = $channel->channel ?? $channel->id;
            $this->assertArrayHasKey($channelId, $data['programmes'], "Channel {$channelId} should have programmes");

            $programmes = $data['programmes'][$channelId];
            $this->assertNotEmpty($programmes, 'Programmes should not be empty');

            // Verify programme structure
            $firstProgramme = $programmes[0];
            $this->assertArrayHasKey('start', $firstProgramme);
            $this->assertArrayHasKey('stop', $firstProgramme);
            $this->assertArrayHasKey('title', $firstProgramme);
            $this->assertArrayHasKey('desc', $firstProgramme);
            $this->assertArrayHasKey('icon', $firstProgramme);

            // Verify category is included when enabled
            $this->assertArrayHasKey('category', $firstProgramme);
            $this->assertEquals($group->name, $firstProgramme['category']);

            // Verify programme length is correct (120 minutes)
            $start = Carbon::parse($firstProgramme['start']);
            $stop = Carbon::parse($firstProgramme['stop']);
            $this->assertEquals(120, $start->diffInMinutes($stop));
        }
    }

    public function test_dummy_epg_respects_date_range()
    {
        // Create a channel without EPG mapping
        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
        ]);

        $startDate = Carbon::now()->format('Y-m-d');
        $endDate = Carbon::now()->addDay()->format('Y-m-d');

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?start_date={$startDate}&end_date={$endDate}");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->channel ?? $channel->id;
        $programmes = $data['programmes'][$channelId] ?? [];

        $this->assertNotEmpty($programmes);

        // Verify all programmes fall within the requested date range
        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();

        foreach ($programmes as $programme) {
            $programmeStart = Carbon::parse($programme['start']);
            $this->assertGreaterThanOrEqual($rangeStart, $programmeStart);
            $this->assertLessThan($rangeEnd, $programmeStart);
        }
    }

    public function test_dummy_epg_not_generated_when_disabled()
    {
        // Disable dummy EPG
        $this->playlist->update(['dummy_epg' => false]);

        // Create a channel without EPG mapping
        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->channel ?? $channel->id;

        // Programmes should be empty or not include the channel without EPG
        $this->assertEmpty($data['programmes'][$channelId] ?? []);
    }

    public function test_dummy_epg_category_can_be_disabled()
    {
        // Disable category in dummy EPG
        $this->playlist->update(['dummy_epg_category' => false]);

        // Create a group and channel
        $group = Group::factory()->create([
            'name' => 'Test Group',
            'user_id' => $this->user->id,
        ]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->channel ?? $channel->id;
        $programmes = $data['programmes'][$channelId] ?? [];

        $this->assertNotEmpty($programmes);

        // Verify category is not included
        $firstProgramme = $programmes[0];
        $this->assertArrayNotHasKey('category', $firstProgramme);
    }

    public function test_mixed_epg_and_dummy_epg_channels()
    {
        // Create a group for both channels
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create an EPG
        $epg = Epg::factory()->create([
            'user_id' => $this->user->id,
            'is_cached' => true,
        ]);

        // Create EPG channel
        $epgChannel = EpgChannel::factory()->create([
            'epg_id' => $epg->id,
            'channel_id' => 'test-channel-1',
            'user_id' => $this->user->id,
        ]);

        // Create a channel with EPG mapping
        // Set explicit sort values to ensure deterministic ordering
        $channelWithEpg = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
            'epg_channel_id' => $epgChannel->id,
            'sort' => 1,
            'channel' => 1,
            'title' => 'Channel A',
        ]);

        // Create a channel without EPG mapping (should get dummy EPG)
        // Set explicit sort values to ensure deterministic ordering
        $channelWithoutEpg = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
            'sort' => 2,
            'channel' => 2,
            'title' => 'Channel B',
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();

        // Both channels should be in the response
        $this->assertCount(2, $data['channels']);

        // Channel without EPG should have dummy programmes
        $channelWithoutEpg->refresh(); // Refresh to get latest data
        $channelId = $channelWithoutEpg->channel ?? $channelWithoutEpg->id;
        $this->assertArrayHasKey($channelId, $data['programmes']);
        $this->assertNotEmpty($data['programmes'][$channelId]);
    }

    public function test_dummy_epg_respects_pagination()
    {
        // Create multiple channels without EPG mapping
        $channels = Channel::factory()->count(5)->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
        ]);

        // Request first page with 2 items per page
        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?per_page=2&page=1");

        $response->assertSuccessful();

        $data = $response->json();

        // Should only have 2 channels on this page
        $this->assertCount(2, $data['channels']);
        $this->assertEquals(2, $data['pagination']['returned_channels']);
        $this->assertEquals(5, $data['pagination']['total_channels']);

        // Verify programmes are only generated for paginated channels
        $this->assertCount(2, $data['programmes']);
    }

    public function test_dummy_epg_with_custom_length()
    {
        // Set custom EPG length to 60 minutes
        $this->playlist->update(['dummy_epg_length' => 60]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->channel ?? $channel->id;
        $programmes = $data['programmes'][$channelId] ?? [];

        $this->assertNotEmpty($programmes);

        // Verify programme length is 60 minutes
        $firstProgramme = $programmes[0];
        $start = Carbon::parse($firstProgramme['start']);
        $stop = Carbon::parse($firstProgramme['stop']);
        $this->assertEquals(60, $start->diffInMinutes($stop));
    }
}
