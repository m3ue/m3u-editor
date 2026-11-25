<?php

use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'xtream_config' => null,
        'include_series_in_m3u' => true,
    ]);
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'catchup' => 'default',
        'url' => "http://provider.example.com/o'brien/1.ts",
    ]);
    $this->alias = PlaylistAlias::create([
        'name' => 'Template Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'xtream_config' => [['url' => 'http://provider.example.com', 'username' => 'user', 'password' => 'pass']],
        'm3u_url_template' => "pipe:///wrapper.sh '{url}'",
    ]);
});

it('wraps channel URLs in the alias M3U only', function () {
    $aliasM3u = $this->get("/{$this->alias->uuid}/playlist.m3u")->assertOk()->streamedContent();
    $playlistM3u = $this->get("/{$this->playlist->uuid}/playlist.m3u")->assertOk()->streamedContent();

    expect($aliasM3u)
        ->toMatch("#^pipe:///wrapper\.sh 'http[^']+/live/[^']+/{$this->channel->id}\.ts'$#m")
        ->toMatch('#catchup-source="http[^"]+/live/#')
        ->and($playlistM3u)->toContain("/{$this->channel->id}.ts")
        ->not->toContain('pipe://');
});

it('wraps series episode URLs', function () {
    $category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $series = Series::factory()->for($this->user)->for($this->playlist)->for($category)->create(['enabled' => true]);
    $season = Season::factory()->for($this->user)->for($this->playlist)->for($series)->create();
    $episode = Episode::factory()->for($this->user)->for($this->playlist)->for($series)->for($season)->create([
        'enabled' => true,
        'container_extension' => 'mkv',
    ]);

    expect($this->get("/{$this->alias->uuid}/playlist.m3u")->assertOk()->streamedContent())
        ->toMatch("#^pipe:///wrapper\.sh 'http[^']+/series/[^']+/{$episode->id}\.mkv'$#m");
});

it('leaves URLs unchanged without a template', function (?string $template) {
    $this->alias->update(['m3u_url_template' => $template]);

    expect($this->get("/{$this->alias->uuid}/playlist.m3u")->assertOk()->streamedContent())
        ->toMatch("#^http\S+/live/\S+/{$this->channel->id}\.ts$#m");
})->with([null, '']);

it('encodes single quotes in raw provider URLs', function () {
    config(['app.disable_m3u_xtream_format' => true]);

    expect($this->get("/{$this->alias->uuid}/playlist.m3u")->assertOk()->streamedContent())
        ->toContain("pipe:///wrapper.sh 'http://provider.example.com/o%27brien/1.ts'\n");
});

it('supports the encoded placeholder and does not expand placeholders inside the URL', function () {
    $this->alias->m3u_url_template = 'http://proxy/?url={url_encoded}&raw={url}';

    expect($this->alias->formatM3uUrl('http://host/{url_encoded}'))
        ->toBe('http://proxy/?url=http%3A%2F%2Fhost%2F%7Burl_encoded%7D&raw=http://host/{url_encoded}');
});

it('does not change the HDHR lineup', function () {
    expect($this->get("/{$this->alias->uuid}/hdhr/lineup.json")->assertOk()->streamedContent())
        ->toContain("{$this->channel->id}.ts")
        ->not->toContain('pipe:');
});

it('validates the template on the alias form', function (?string $template, bool $valid) {
    $this->actingAs($this->user);

    $form = Livewire::test(EditPlaylistAlias::class, ['record' => $this->alias->getRouteKey()])
        ->fillForm(['m3u_url_template' => $template])
        ->call('save');

    if ($valid) {
        $form->assertHasNoFormErrors();
        expect($this->alias->fresh()->m3u_url_template)->toBe($template);
    } else {
        $form->assertHasFormErrors(['m3u_url_template']);
    }
})->with([
    'url' => ["pipe://ffmpeg -i '{url}' pipe:1", true],
    'encoded url' => ['http://proxy/?url={url_encoded}', true],
    'empty' => [null, true],
    'no placeholder' => ['pipe:///wrapper.sh', false],
    'multiple lines' => ["{url}\n#EXTINF:-1,Injected", false],
]);

it('applies the template to the get.php M3U download', function () {
    $this->alias->update(['username' => 'tvh', 'password' => 'tvh-pass']);

    expect($this->get('/get.php?username=tvh&password=tvh-pass&action=m3u_plus')->assertOk()->streamedContent())
        ->toMatch("#^pipe:///wrapper\.sh 'http[^']+/live/[^']+/{$this->channel->id}\.ts'$#m");
});

it('does not wrap an empty stream URL', function () {
    config(['app.disable_m3u_xtream_format' => true]);
    $this->channel->update(['url' => null, 'url_custom' => null]);

    expect($this->get("/{$this->alias->uuid}/playlist.m3u")->assertOk()->streamedContent())
        ->toContain('#EXTINF')
        ->not->toContain('pipe://');
});
