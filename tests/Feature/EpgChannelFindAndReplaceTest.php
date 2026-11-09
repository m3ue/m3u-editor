<?php

use App\Jobs\EpgChannelFindAndReplace;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->epg = Epg::factory()->for($this->user)->create();
});

it('persists a replacement that is an empty string (issue #1778)', function () {
    $channel = EpgChannel::factory()->for($this->user)->for($this->epg)->create([
        'name' => 'MATCH',
        'name_custom' => null,
    ]);
    $control = EpgChannel::factory()->for($this->user)->for($this->epg)->create([
        'name' => 'UNTOUCHED',
        'name_custom' => null,
    ]);

    (new EpgChannelFindAndReplace(
        user_id: $this->user->id,
        use_regex: false,
        column: 'name',
        find_replace: 'MATCH',
        replace_with: '',
        epg_id: $this->epg->id,
    ))->handle();

    expect($channel->fresh()->name_custom)->toBe('')
        ->and($control->fresh()->name_custom)->toBeNull();
});

it('persists a replacement that is the literal string "0" (issue #1778)', function () {
    $channel = EpgChannel::factory()->for($this->user)->for($this->epg)->create([
        'name' => 'MATCH',
        'name_custom' => null,
    ]);

    (new EpgChannelFindAndReplace(
        user_id: $this->user->id,
        use_regex: false,
        column: 'name',
        find_replace: 'MATCH',
        replace_with: '0',
        epg_id: $this->epg->id,
    ))->handle();

    expect($channel->fresh()->name_custom)->toBe('0');
});

it('still persists a nonempty replacement (positive control)', function () {
    $channel = EpgChannel::factory()->for($this->user)->for($this->epg)->create([
        'name' => 'MATCH',
        'name_custom' => null,
    ]);
    $control = EpgChannel::factory()->for($this->user)->for($this->epg)->create([
        'name' => 'UNTOUCHED',
        'name_custom' => null,
    ]);

    (new EpgChannelFindAndReplace(
        user_id: $this->user->id,
        use_regex: false,
        column: 'name',
        find_replace: 'MATCH',
        replace_with: 'REPLACED',
        epg_id: $this->epg->id,
    ))->handle();

    expect($channel->fresh()->name_custom)->toBe('REPLACED')
        ->and($control->fresh()->name_custom)->toBeNull();
});
