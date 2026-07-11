<?php

/**
 * Regression tests for "Channel prefixes to remove before matching is broken"
 * (issue #1265).
 *
 * MapPlaylistChannelsToEpgChunk stripped the configured prefixes/patterns for
 * its exact-match steps, but passed the raw Channel model to
 * SimilaritySearchService — so the fuzzy stage searched on the original
 * prefixed name. Any channel that needed fuzzy matching behaved as if the
 * exclude setting was never configured. The cleaned title/name are now passed
 * through to the similarity search.
 */

use App\Jobs\MapPlaylistChannelsToEpgChunk;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SimilaritySearchService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();

    $this->user = User::factory()->create();
    $this->epg = Epg::factory()->for($this->user)->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
});

describe('SimilaritySearchService cleaned name overrides', function () {
    it('matches via cleaned title and name when the raw channel name carries a provider prefix', function () {
        $epgChannel = EpgChannel::factory()->for($this->epg)->for($this->user)->create([
            'name' => 'CBS Sports Network',
            'display_name' => 'CBS Sports Network',
            'channel_id' => 'cbssn.us',
        ]);

        $channel = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
            'title' => 'SPRT| CBS Sports Network',
            'name' => 'SPRT| CBS Sports Network',
        ]);

        $match = (new SimilaritySearchService)->findMatchingEpgChannel(
            $channel,
            $this->epg,
            cleanedTitle: 'CBS Sports Network',
            cleanedName: 'CBS Sports Network',
        );

        expect($match?->id)->toBe($epgChannel->id);
    });

    it('does not match on the raw prefixed name without cleaned overrides', function () {
        EpgChannel::factory()->for($this->epg)->for($this->user)->create([
            'name' => 'CBS Sports Network',
            'display_name' => 'CBS Sports Network',
            'channel_id' => 'cbssn.us',
        ]);

        $channel = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
            'title' => 'SPRT| CBS Sports Network',
            'name' => 'SPRT| CBS Sports Network',
        ]);

        $match = (new SimilaritySearchService)->findMatchingEpgChannel($channel, $this->epg);

        expect($match)->toBeNull();
    });
});

describe('MapPlaylistChannelsToEpgChunk exclude prefixes', function () {
    function runEpgMapChunk(Epg $epg, Playlist $playlist, Channel $channel, array $settings): string
    {
        $map = EpgMap::factory()->create([
            'epg_id' => $epg->id,
            'playlist_id' => $playlist->id,
            'user_id' => $epg->user_id,
            'settings' => $settings,
        ]);

        $batchNo = 'epg-map-batch-'.uniqid();
        (new MapPlaylistChannelsToEpgChunk(
            channelIds: [$channel->id],
            epgId: $epg->id,
            epgMapId: $map->id,
            settings: $settings,
            batchNo: $batchNo,
            totalChannels: 1,
        ))->handle();

        return $batchNo;
    }

    it('maps a prefixed channel through the similarity stage when exclude prefixes are configured', function () {
        $epgChannel = EpgChannel::factory()->for($this->epg)->for($this->user)->create([
            'name' => 'CBS Sports Network',
            'display_name' => 'CBS Sports Network',
            'channel_id' => 'cbssn.us',
        ]);

        // "HD" forces the chunk's exact-match steps to miss, so the mapping can
        // only succeed through the similarity stage with the prefix stripped.
        $channel = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
            'title' => 'SPRT| CBS Sports Network HD',
            'name' => 'SPRT| CBS Sports Network HD',
            'stream_id' => 'sprt-cbs-sports',
        ]);

        $batchNo = runEpgMapChunk($this->epg, $this->playlist, $channel, [
            'exclude_prefixes' => ['SPRT| '],
            'use_regex' => false,
            'remove_quality_indicators' => true,
        ]);

        $mapJob = Job::where('batch_no', $batchNo)->first();

        expect($mapJob)->not->toBeNull()
            ->and($mapJob->payload[0]['epg_channel_id'])->toBe($epgChannel->id);
    });

    it('does not map the same channel when no exclude prefixes are configured', function () {
        EpgChannel::factory()->for($this->epg)->for($this->user)->create([
            'name' => 'CBS Sports Network',
            'display_name' => 'CBS Sports Network',
            'channel_id' => 'cbssn.us',
        ]);

        $channel = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
            'title' => 'SPRT| CBS Sports Network HD',
            'name' => 'SPRT| CBS Sports Network HD',
            'stream_id' => 'sprt-cbs-sports',
        ]);

        $batchNo = runEpgMapChunk($this->epg, $this->playlist, $channel, [
            'use_regex' => false,
            'remove_quality_indicators' => true,
        ]);

        expect(Job::where('batch_no', $batchNo)->exists())->toBeFalse();
    });
});
