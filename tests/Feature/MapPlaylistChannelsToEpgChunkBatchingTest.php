<?php

use App\Jobs\MapPlaylistChannelsToEpgChunk;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Job as JobRecord;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create();
});

it('resolves every channel in the chunk against one prefetched candidate set instead of a query per channel', function () {
    // None of these share a literal name with the playlist channel below -
    // only the normalized (quality-indicator-stripped) form matches, so each
    // one is guaranteed to miss the cheap exact-match steps (1-3) and fall
    // through to the similarity search (step 4).
    $matches = collect(['Sports Central', 'News Prime', 'Movie Zone', 'Kids World', 'Music Live'])
        ->map(fn (string $name) => EpgChannel::factory()->for($this->epg)->for($this->user)->create([
            'name' => $name,
            'display_name' => $name,
            'channel_id' => str($name)->slug().'.us',
        ]));

    $channels = $matches->map(function (EpgChannel $epgChannel) {
        return Channel::factory()
            ->for($this->playlist)
            ->for($this->user)
            ->for($this->group)
            ->create([
                'name' => "{$epgChannel->name} HD",
                'title' => "{$epgChannel->name} HD",
                'is_vod' => false,
            ]);
    });

    $map = EpgMap::factory()->create([
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'processing' => true,
        'progress' => 0,
    ]);

    $batchNo = (string) Str::uuid();

    DB::enableQueryLog();

    (new MapPlaylistChannelsToEpgChunk(
        channelIds: $channels->pluck('id')->toArray(),
        epgId: $this->epg->id,
        epgMapId: $map->id,
        settings: ['remove_quality_indicators' => true],
        batchNo: $batchNo,
        totalChannels: $channels->count(),
    ))->handle();

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    // The batched similarity search (loadEpgCandidates) selects candidate
    // rows in one query for the whole chunk - identifiable by the JSONB
    // additional_display_names scan unique to that query. Without the
    // prefetch, this would appear once per channel (5 times here).
    $candidateScans = $queries->filter(
        fn (array $query) => str_contains($query['query'], 'additional_display_names'),
    );

    expect($candidateScans)->toHaveCount(1);

    // Correctness: every channel still resolves to the right EPG channel
    // despite the batching - each was queued as a Job row for the next
    // mapping stage.
    $payloads = JobRecord::where('batch_no', $batchNo)->get()->flatMap(fn (JobRecord $job) => $job->payload);

    expect($payloads)->toHaveCount(5);

    $matches->each(function (EpgChannel $epgChannel) use ($payloads) {
        expect($payloads->pluck('epg_channel_id'))->toContain($epgChannel->id);
    });
});
