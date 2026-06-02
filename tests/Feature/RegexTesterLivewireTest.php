<?php

use App\Livewire\RegexTester;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('runs regex tests with normalized samples', function (): void {
    Livewire::test(RegexTester::class, ['flags' => 'ui'])
        ->set('pattern', '^US:')
        ->set('samples', "  US: BBC One  \n\nUK: Sky News\n   ")
        ->call('test')
        ->assertSet('tested', true)
        ->assertSet('results', function (array $results): bool {
            return count($results) === 2
                && $results[0]['input'] === 'US: BBC One'
                && $results[0]['matches'] === true
                && $results[1]['input'] === 'UK: Sky News'
                && $results[1]['matches'] === false;
        });
});

it('loads real samples for the configured context', function (): void {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_id' => $group->id,
        'is_vod' => false,
        'title' => 'BBC One',
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_id' => $group->id,
        'is_vod' => true,
        'title' => 'Movie Channel',
    ]);

    Livewire::test(RegexTester::class, ['samplesContext' => 'channels'])
        ->call('loadSamples')
        ->assertSet('samples', 'BBC One');
});

it('keeps results empty when no pattern is provided', function (): void {
    Livewire::test(RegexTester::class)
        ->set('samples', "BBC One\nSky News")
        ->call('test')
        ->assertSet('tested', true)
        ->assertSet('results', []);
});
