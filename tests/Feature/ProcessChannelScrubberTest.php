<?php

use App\Jobs\ProcessChannelScrubber;
use App\Jobs\ProcessChannelScrubberComplete;
use App\Models\Channel;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Bus::fake();
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->createQuietly());

    $this->scrubber = function (array $attributes = []): ChannelScrubber {
        return ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'name' => 'Parallel Scrubber',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'use_batching' => true,
            'scan_all' => false,
            ...$attributes,
        ]));
    };
});

it('completes an empty parallel scan without requiring a chunk to run (issue #1777)', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => false,
        'is_vod' => false,
    ]);

    $scrubber = ($this->scrubber)();

    (new ProcessChannelScrubber($scrubber->id))->handle();

    Bus::assertDispatched(ProcessChannelScrubberComplete::class, function (ProcessChannelScrubberComplete $job) use ($scrubber) {
        return $job->scrubberId === $scrubber->id && $job->batchNo === $scrubber->fresh()->uuid;
    });

    $log = ChannelScrubberLog::where('channel_scrubber_id', $scrubber->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->channel_count)->toBe(0);
});

it('still dispatches a batch for a nonempty parallel scan', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => false,
    ]);

    $scrubber = ($this->scrubber)();

    (new ProcessChannelScrubber($scrubber->id))->handle();

    Bus::assertBatched(fn ($batch) => true);
    Bus::assertNotDispatched(ProcessChannelScrubberComplete::class);
});
