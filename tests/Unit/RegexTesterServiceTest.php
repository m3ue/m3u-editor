<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\RegexTesterService;
use Illuminate\Support\HtmlString;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('returns empty array for empty pattern', function (): void {
    $results = RegexTesterService::test('', 'ui', '', ['BBC One']);

    expect($results)->toBeEmpty();
});

it('normalizes sample text into trimmed non-empty lines', function (): void {
    $samples = RegexTesterService::normalizeSamples("  US: BBC One  \n\n UK: Sky News\n   \nSport FHD ");

    expect($samples)->toBe([
        'US: BBC One',
        'UK: Sky News',
        'Sport FHD',
    ]);
});

it('matches samples against a valid pattern', function (): void {
    $results = RegexTesterService::test('^US:', 'ui', '', ['US: BBC One', 'UK: Sky News', 'US: CNN']);

    expect($results)->toHaveCount(3)
        ->and($results[0]['matches'])->toBeTrue()
        ->and($results[1]['matches'])->toBeFalse()
        ->and($results[2]['matches'])->toBeTrue();
});

it('applies replacement to matching samples', function (): void {
    $results = RegexTesterService::test('^(US|UK):\s*', 'ui', '', ['US: BBC One', 'Plain Channel']);

    expect($results[0]['output'])->toBe('BBC One')
        ->and($results[1]['output'])->toBe('Plain Channel');
});

it('returns error result for invalid regex', function (): void {
    $results = RegexTesterService::test('[unclosed', 'ui', '', ['test']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['error'])->not->toBeNull();
});

it('skips blank lines in samples', function (): void {
    $results = RegexTesterService::test('foo', 'ui', '', ['foo', '', '   ', 'bar']);

    expect($results)->toHaveCount(2);
});

it('renderResults returns empty HtmlString for empty results', function (): void {
    $html = RegexTesterService::renderResults([], false);

    expect($html)->toBeInstanceOf(HtmlString::class)
        ->and((string) $html)->toBe('');
});

it('renderResults includes match count summary', function (): void {
    $results = [
        ['input' => 'US: BBC', 'matches' => true, 'output' => 'BBC', 'error' => null],
        ['input' => 'Sky News', 'matches' => false, 'output' => 'Sky News', 'error' => null],
    ];

    $html = (string) RegexTesterService::renderResults($results, true);

    expect($html)->toContain('1 match')
        ->toContain('of 2 samples');
});

it('renderResults shows error message for invalid pattern result', function (): void {
    $results = [['input' => '', 'matches' => false, 'output' => '', 'error' => 'Invalid regex: something']];

    $html = (string) RegexTesterService::renderResults($results, false);

    expect($html)->toContain('Invalid regex');
});

it('loads live channel samples for the current user', function (): void {
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
        'title' => 'Live Channel',
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_id' => $group->id,
        'is_vod' => true,
        'title' => 'Vod Channel',
    ]);

    $samples = RegexTesterService::fetchSamplesForContext('channels', $this->user->id);

    expect($samples->all())->toBe(['Live Channel']);
});

it('loads vod channel samples for the current user', function (): void {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_id' => $group->id,
        'is_vod' => true,
        'title' => 'Vod Channel',
    ]);

    $samples = RegexTesterService::fetchSamplesForContext('vod_channels', $this->user->id);

    expect($samples->all())->toBe(['Vod Channel']);
});

it('loads live group samples for the current user', function (): void {
    Group::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'live',
        'name' => 'Live Group',
    ]);

    Group::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Vod Group',
    ]);

    $samples = RegexTesterService::fetchSamplesForContext('groups', $this->user->id);

    expect($samples->all())->toBe(['Live Group']);
});

it('loads vod group samples for the current user', function (): void {
    Group::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Vod Group',
    ]);

    $samples = RegexTesterService::fetchSamplesForContext('vod_groups', $this->user->id);

    expect($samples->all())->toBe(['Vod Group']);
});

it('loads series samples for the current user', function (): void {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'name' => 'Series Name',
    ]);

    $samples = RegexTesterService::fetchSamplesForContext('series', $this->user->id);

    expect($samples->all())->toBe(['Series Name']);
});

it('loads category samples for the current user', function (): void {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'name' => 'Category Name',
    ]);

    $samples = RegexTesterService::fetchSamplesForContext('categories', $this->user->id);

    expect($samples->all())->toBe(['Category Name']);
});

it('loads epg channel samples for the current user', function (): void {
    EpgChannel::factory()->create([
        'user_id' => $this->user->id,
        'display_name' => 'EPG Channel',
    ]);

    $samples = RegexTesterService::fetchSamplesForContext('epg_channels', $this->user->id);

    expect($samples->all())->toBe(['EPG Channel']);
});

it('returns an empty collection for an unknown sample context', function (): void {
    $samples = RegexTesterService::fetchSamplesForContext('unknown', $this->user->id);

    expect($samples)->toBeEmpty();
});
