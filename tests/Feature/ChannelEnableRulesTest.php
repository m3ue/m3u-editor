<?php

/**
 * Tests for the per-playlist auto enable/disable rules (issue #1199).
 *
 * Rules are re-evaluated against every non-custom channel on each sync:
 * rules run in order, the last matching rule wins, and channels matching
 * no rule keep their current enabled state.
 */

use App\Enums\SyncRunPhase;
use App\Jobs\RunPlaylistChannelEnableRules;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
});

function makeEnableRulesPlaylist(User $user, array $rules): Playlist
{
    return Playlist::factory()->for($user)->createQuietly([
        'channel_enable_rules' => $rules,
    ]);
}

function makeEnableRulesChannel(Playlist $playlist, string $title, bool $enabled, array $attrs = []): Channel
{
    return Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'title' => $title,
        'enabled' => $enabled,
        'is_vod' => false,
        'is_custom' => false,
        ...$attrs,
    ]);
}

it('disables channels matching a disable rule and leaves others untouched', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => true, 'name' => 'No event', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'NO EVENT'],
    ]);

    $idle = makeEnableRulesChannel($playlist, 'LIVE EVENT 02 | NO EVENT TODAY', true);
    $active = makeEnableRulesChannel($playlist, 'LIVE EVENT 01 | EVENT TITLE', true);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($idle->refresh()->enabled)->toBeFalse()
        ->and($active->refresh()->enabled)->toBeTrue();
});

it('re-enables a disabled channel when its title matches an enable rule', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => true, 'name' => 'Event live', 'target' => 'channels', 'column' => 'title', 'action' => 'enable', 'pattern' => 'EVENT \\d+ \\| (?!NO EVENT)'],
    ]);

    // Previously disabled placeholder that the provider renamed to a real event
    $channel = makeEnableRulesChannel($playlist, 'EVENT 01 | Big Game Tonight', false);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($channel->refresh()->enabled)->toBeTrue();
});

it('applies rules in order with the last matching rule winning', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => true, 'name' => 'Disable all events', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => '^LIVE EVENT'],
        ['enabled' => true, 'name' => 'Enable active events', 'target' => 'channels', 'column' => 'title', 'action' => 'enable', 'pattern' => '^LIVE EVENT (?!.*NO EVENT)'],
    ]);

    $idle = makeEnableRulesChannel($playlist, 'LIVE EVENT 02 | NO EVENT TODAY', true);
    $active = makeEnableRulesChannel($playlist, 'LIVE EVENT 01 | EVENT TITLE', false);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($idle->refresh()->enabled)->toBeFalse()
        ->and($active->refresh()->enabled)->toBeTrue();
});

it('matches against the custom title override when set', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => true, 'name' => 'No event', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'NO EVENT'],
    ]);

    $channel = makeEnableRulesChannel($playlist, 'LIVE EVENT 01', true, ['title_custom' => 'NO EVENT TODAY']);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($channel->refresh()->enabled)->toBeFalse();
});

it('matches against the channel name when the rule targets the name column', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => true, 'name' => 'No event', 'target' => 'channels', 'column' => 'name', 'action' => 'disable', 'pattern' => 'NO EVENT'],
    ]);

    $channel = makeEnableRulesChannel($playlist, 'Some title', true, ['name' => 'EVENT 05 | NO EVENT']);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($channel->refresh()->enabled)->toBeFalse();
});

it('skips disabled rules and rules with invalid regex without aborting the rest', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => false, 'name' => 'Disabled rule', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'EVENT TITLE'],
        ['enabled' => true, 'name' => 'Broken rule', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => '([unclosed'],
        ['enabled' => true, 'name' => 'No event', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'NO EVENT'],
    ]);

    $active = makeEnableRulesChannel($playlist, 'LIVE EVENT 01 | EVENT TITLE', true);
    $idle = makeEnableRulesChannel($playlist, 'LIVE EVENT 02 | NO EVENT TODAY', true);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($active->refresh()->enabled)->toBeTrue()
        ->and($idle->refresh()->enabled)->toBeFalse();
});

it('only applies rules to channels of the matching target type', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => true, 'name' => 'VOD only', 'target' => 'vod_channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'NO EVENT'],
    ]);

    $live = makeEnableRulesChannel($playlist, 'NO EVENT TODAY', true);
    $vod = makeEnableRulesChannel($playlist, 'NO EVENT TODAY', true, ['is_vod' => true]);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($live->refresh()->enabled)->toBeTrue()
        ->and($vod->refresh()->enabled)->toBeFalse();
});

it('never touches custom channels', function () {
    $playlist = makeEnableRulesPlaylist($this->user, [
        ['enabled' => true, 'name' => 'No event', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'NO EVENT'],
    ]);

    $custom = makeEnableRulesChannel($playlist, 'NO EVENT TODAY', true, ['is_custom' => true]);

    (new RunPlaylistChannelEnableRules($playlist))->handle();

    expect($custom->refresh()->enabled)->toBeTrue();
});

// ── Pipeline integration ─────────────────────────────────────────────────────

it('includes the ChannelEnableRules phase after FindReplace when rules are enabled', function () {
    Bus::fake();
    Event::fake();

    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = false;
    $mock->tmdb_auto_lookup_all_new = 'enabled';
    app()->instance(GeneralSettings::class, $mock);

    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'find_replace_rules' => [['enabled' => true, 'find_replace' => 'HD', 'replace_with' => '']],
        'channel_enable_rules' => [
            ['enabled' => true, 'name' => 'No event', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'NO EVENT'],
        ],
    ]);

    $run = app(SyncPipelineService::class)->buildPipeline($playlist, app(GeneralSettings::class));

    $findReplacePos = array_search(SyncRunPhase::FindReplace->value, $run->phases);
    $enableRulesPos = array_search(SyncRunPhase::ChannelEnableRules->value, $run->phases);

    expect($findReplacePos)->not->toBeFalse()
        ->and($enableRulesPos)->not->toBeFalse()
        ->and($findReplacePos)->toBeLessThan($enableRulesPos);
});

it('omits the ChannelEnableRules phase when no rules are enabled', function () {
    Bus::fake();
    Event::fake();

    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = false;
    $mock->tmdb_auto_lookup_all_new = 'enabled';
    app()->instance(GeneralSettings::class, $mock);

    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'channel_enable_rules' => [
            ['enabled' => false, 'name' => 'Off', 'target' => 'channels', 'column' => 'title', 'action' => 'disable', 'pattern' => 'NO EVENT'],
        ],
    ]);

    $run = app(SyncPipelineService::class)->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->not->toContain(SyncRunPhase::ChannelEnableRules->value);
});
