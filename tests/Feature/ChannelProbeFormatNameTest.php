<?php

/**
 * Channel::probeStreamStats() requests `-show_format` from ffprobe but previously
 * only ever projected `format.bit_rate` into the persisted stats, discarding
 * `format_name`. Adaptive stream profile rules that match on the container
 * Format name (StreamProfileRuleEvaluator reading `format.format_name`) could
 * therefore never match a successfully probed channel, even when the raw
 * ffprobe response contained the matching name.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\StreamProfileRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->evaluator = app(StreamProfileRuleEvaluator::class);

    $this->target = StreamProfile::factory()->for($this->user)->create([
        'name' => 'Heavy Profile',
        'backend' => 'ffmpeg',
    ]);
    $this->fallback = StreamProfile::factory()->for($this->user)->create([
        'name' => 'Light Profile',
        'backend' => 'ffmpeg',
    ]);

    $this->fakeBinDir = storage_path('app/testing-fake-ffprobe-format-name');
    File::ensureDirectoryExists($this->fakeBinDir);
});

afterEach(function () {
    if (isset($this->originalPath)) {
        putenv('PATH='.$this->originalPath);
    }
    File::deleteDirectory($this->fakeBinDir);
});

function fakeFfprobeReturningJson(object $test, string $json): void
{
    File::put($test->fakeBinDir.'/ffprobe', "#!/bin/sh\necho '{$json}'\n");
    chmod($test->fakeBinDir.'/ffprobe', 0755);

    $test->originalPath = getenv('PATH');
    putenv('PATH='.$test->fakeBinDir.PATH_SEPARATOR.$test->originalPath);
}

it('persists format_name alongside bit_rate from a successful probe', function () {
    fakeFfprobeReturningJson($this, '{"streams":[{"codec_type":"video","codec_name":"h264","width":1920,"height":1080}],"format":{"format_name":"mpegts","bit_rate":"5000000"}}');

    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => 'http://example.test/stream.ts',
    ]);

    $formatEntry = collect($channel->probeStreamStats())->firstWhere('format');

    expect($formatEntry)->not->toBeNull()
        ->and($formatEntry['format']['format_name'])->toBe('mpegts')
        ->and($formatEntry['format']['bit_rate'])->toBe('5000000');
});

it('persists format_name even when no bitrate is present', function () {
    fakeFfprobeReturningJson($this, '{"streams":[{"codec_type":"video","codec_name":"h264","width":1920,"height":1080}],"format":{"format_name":"mpegts"}}');

    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => 'http://example.test/stream.ts',
    ]);

    $formatEntry = collect($channel->probeStreamStats())->firstWhere('format');

    expect($formatEntry)->not->toBeNull()
        ->and($formatEntry['format']['format_name'])->toBe('mpegts')
        ->and($formatEntry['format']['bit_rate'])->toBeNull();
});

it('resolves a matching format-name adaptive rule to its target using a real probe', function () {
    fakeFfprobeReturningJson($this, '{"streams":[{"codec_type":"video","codec_name":"h264","width":1920,"height":1080}],"format":{"format_name":"mpegts","bit_rate":"5000000"}}');

    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'url' => 'http://example.test/stream.ts',
    ]);

    $adaptive = StreamProfile::factory()->for($this->user)->create([
        'name' => 'Auto Adaptive',
        'backend' => 'adaptive',
        'args' => '',
        'rules' => [
            ['conditions' => [['field' => 'format.format_name', 'op' => '=', 'value' => 'mpegts']], 'stream_profile_id' => $this->target->id],
        ],
        'else_stream_profile_id' => $this->fallback->id,
    ]);

    $stats = $channel->probeStreamStats();

    expect($this->evaluator->resolve($adaptive, $stats))->toBe($this->target->id);
});
