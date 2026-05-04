<?php

use App\Filament\Resources\StreamProfiles\StreamProfileResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\StreamProfileRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();

    $this->target = StreamProfile::factory()->for($this->user)->create([
        'name' => 'Heavy Profile',
        'backend' => 'ffmpeg',
    ]);
    $this->fallback = StreamProfile::factory()->for($this->user)->create([
        'name' => 'Light Profile',
        'backend' => 'ffmpeg',
    ]);

    $this->evaluator = app(StreamProfileRuleEvaluator::class);
});

/**
 * Build an adaptive (backend=adaptive) StreamProfile with the given rules and else fallback.
 *
 * @param  array<int, array{conditions: array, stream_profile_id: int}>  $rules
 */
function makeAdaptiveProfile(User $user, array $rules, ?int $elseProfileId = null): StreamProfile
{
    return StreamProfile::factory()->for($user)->create([
        'name' => 'Auto Adaptive',
        'backend' => 'adaptive',
        'args' => '',
        'rules' => $rules,
        'else_stream_profile_id' => $elseProfileId,
    ]);
}

/**
 * Real-shaped probe payload, parameterised so each test can exercise
 * exactly the codec/resolution/etc. it needs.
 */
function probe(array $video = [], array $audio = [], array $format = []): array
{
    return [
        ['stream' => array_merge([
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'width' => 1920,
            'height' => 1080,
            'bit_rate' => '4000000',
            'avg_frame_rate' => '25/1',
            'profile' => 'High',
            'display_aspect_ratio' => '16:9',
        ], $video)],
        ['stream' => array_merge([
            'codec_type' => 'audio',
            'codec_name' => 'aac',
            'channels' => 2,
            'sample_rate' => '48000',
        ], $audio)],
        ['format' => array_merge([
            'format_name' => 'hls',
        ], $format)],
    ];
}

// ── Evaluator: rule matching ─────────────────────────────────────────────────

it('returns the first matching rule target', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    $resolved = $this->evaluator->resolve($adaptive, probe(['codec_name' => 'hevc']));

    expect($resolved)->toBe($this->target->id);
});

it('falls through to the else profile when no rule matches', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    $resolved = $this->evaluator->resolve($adaptive, probe(['codec_name' => 'h264']));

    expect($resolved)->toBe($this->fallback->id);
});

it('falls through to else when probe data is null', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, null))->toBe($this->fallback->id);
});

it('falls through to else when probe data is malformed', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.height', 'op' => '>', 'value' => 1080]], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, ['not-an-entry', null]))->toBe($this->fallback->id);
});

it('returns null when nothing matches and no else is set', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ]);

    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'h264'])))->toBeNull();
});

it('walks rules in order and stops at the first match', function () {
    $other = StreamProfile::factory()->for($this->user)->create(['backend' => 'ffmpeg']);

    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.height', 'op' => '>=', 'value' => 1080]], 'stream_profile_id' => $this->target->id],
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'h264']], 'stream_profile_id' => $other->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, probe()))->toBe($this->target->id);
});

// ── Evaluator: numeric & string operators ────────────────────────────────────

it('evaluates numeric comparison operators correctly', function (string $op, int $value, bool $expected) {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.height', 'op' => $op, 'value' => $value]], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    $resolved = $this->evaluator->resolve($adaptive, probe(['height' => 1080]));
    expect($resolved)->toBe($expected ? $this->target->id : $this->fallback->id);
})->with([
    ['=', 1080, true],
    ['=', 720, false],
    ['!=', 720, true],
    ['!=', 1080, false],
    ['>', 720, true],
    ['>', 1080, false],
    ['>=', 1080, true],
    ['<', 1080, false],
    ['<=', 1080, true],
]);

it('evaluates string equality case-insensitively', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'HEVC']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'hevc'])))->toBe($this->target->id);
});

it('evaluates the in operator against a list', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => 'in', 'value' => ['hevc', 'av1']]], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'av1'])))->toBe($this->target->id);
    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'h264'])))->toBe($this->fallback->id);
});

it('evaluates in operator case-insensitively', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => 'in', 'value' => ['HEVC', 'AV1']]], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    // lowercase probe value should match uppercase list entries
    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'hevc'])))->toBe($this->target->id);
    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'av1'])))->toBe($this->target->id);
    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'h264'])))->toBe($this->fallback->id);
});

it('evaluates the not_in operator against a list', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'audio.codec_name', 'op' => 'not_in', 'value' => ['aac', 'mp3']]], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, probe(audio: ['codec_name' => 'eac3'])))->toBe($this->target->id);
    expect($this->evaluator->resolve($adaptive, probe(audio: ['codec_name' => 'aac'])))->toBe($this->fallback->id);
});

// ── Evaluator: AND across conditions, missing fields ─────────────────────────

it('requires all conditions in a rule to match', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        [
            'conditions' => [
                ['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc'],
                ['field' => 'video.height', 'op' => '>=', 'value' => 2160],
            ],
            'stream_profile_id' => $this->target->id,
        ],
    ], elseProfileId: $this->fallback->id);

    // both true → match
    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'hevc', 'height' => 2160])))->toBe($this->target->id);
    // codec matches but height doesn't → fall through
    expect($this->evaluator->resolve($adaptive, probe(['codec_name' => 'hevc', 'height' => 1080])))->toBe($this->fallback->id);
});

it('treats a missing probe field as a failed condition', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'audio.codec_name', 'op' => '=', 'value' => 'aac']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    // payload with no audio entry at all
    $videoOnly = [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'height' => 1080, 'width' => 1920]]];
    expect($this->evaluator->resolve($adaptive, $videoOnly))->toBe($this->fallback->id);
});

// ── Evaluator: frame-rate parsing ────────────────────────────────────────────

it('parses avg_frame_rate as a num/den fraction', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.frame_rate', 'op' => '>', 'value' => 50]], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, probe(['avg_frame_rate' => '60000/1001'])))->toBe($this->target->id);
    expect($this->evaluator->resolve($adaptive, probe(['avg_frame_rate' => '25/1'])))->toBe($this->fallback->id);
});

it('treats 0/0 frame rate as missing', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.frame_rate', 'op' => '>', 'value' => 0]], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->evaluator->resolve($adaptive, probe(['avg_frame_rate' => '0/0'])))->toBe($this->fallback->id);
});

// ── Channel::getEffectiveStreamProfile() ─────────────────────────────────────

it('returns null when the channel has no profile assigned', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => null]);

    expect($channel->getEffectiveStreamProfile())->toBeNull();
});

it('returns the profile unchanged when its backend is not rules', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)
        ->create(['stream_profile_id' => $this->target->id]);

    expect($channel->getEffectiveStreamProfile()?->id)->toBe($this->target->id);
});

it('unwraps a adaptive profile to its rule target', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => $adaptive->id,
        'stream_stats' => probe(['codec_name' => 'hevc']),
    ]);

    expect($channel->getEffectiveStreamProfile()?->id)->toBe($this->target->id);
});

it('unwraps a adaptive profile to its else fallback when no rule matches', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => $adaptive->id,
        'stream_stats' => probe(['codec_name' => 'h264']),
    ]);

    expect($channel->getEffectiveStreamProfile()?->id)->toBe($this->fallback->id);
});

it('returns null when an adaptive profile resolves to a missing target', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => 99999],
    ]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => $adaptive->id,
        'stream_stats' => probe(['codec_name' => 'hevc']),
    ]);

    expect($channel->getEffectiveStreamProfile())->toBeNull();
});

// ── Form-level chaining block ────────────────────────────────────────────────

it('excludes adaptive profiles from the rule target options (chaining block)', function () {
    $transcoder = StreamProfile::factory()->for($this->user)->create([
        'name' => 'Transcoder One',
        'backend' => 'ffmpeg',
    ]);
    $adaptive = makeAdaptiveProfile($this->user, []);

    $options = StreamProfileResource::transcodingProfileOptions(null);

    expect($options)->toHaveKey($transcoder->id);
    expect($options)->not->toHaveKey($adaptive->id);
});

it('excludes the current record from its own target options (no self-reference)', function () {
    $other = StreamProfile::factory()->for($this->user)->create(['backend' => 'ffmpeg']);
    $adaptive = makeAdaptiveProfile($this->user, []);

    $options = StreamProfileResource::transcodingProfileOptions($adaptive);

    expect($options)->toHaveKey($other->id);
    expect($options)->not->toHaveKey($adaptive->id);
});

it('isAdaptive helper returns true only for backend = adaptive', function () {
    expect($this->target->isAdaptive())->toBeFalse();

    $adaptive = makeAdaptiveProfile($this->user, []);
    expect($adaptive->isAdaptive())->toBeTrue();
});

// ── Delete guard: getReferencingAdaptiveProfiles() ───────────────────────────

it('getReferencingAdaptiveProfiles returns profiles that use this profile as a rule target', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->target->getReferencingAdaptiveProfiles()->pluck('id')->all())->toBe([$adaptive->id]);
});

it('getReferencingAdaptiveProfiles returns profiles that use this profile as the else fallback', function () {
    $adaptive = makeAdaptiveProfile($this->user, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ], elseProfileId: $this->fallback->id);

    expect($this->fallback->getReferencingAdaptiveProfiles()->pluck('id')->all())->toBe([$adaptive->id]);
});

it('getReferencingAdaptiveProfiles returns empty collection when profile is not referenced', function () {
    $unreferenced = StreamProfile::factory()->for($this->user)->create(['backend' => 'ffmpeg']);

    expect($unreferenced->getReferencingAdaptiveProfiles())->toBeEmpty();
});

it('getReferencingAdaptiveProfiles does not include profiles belonging to other users', function () {
    $otherUser = User::factory()->create();
    makeAdaptiveProfile($otherUser, [
        ['conditions' => [['field' => 'video.codec_name', 'op' => '=', 'value' => 'hevc']], 'stream_profile_id' => $this->target->id],
    ]);

    expect($this->target->getReferencingAdaptiveProfiles())->toBeEmpty();
});
