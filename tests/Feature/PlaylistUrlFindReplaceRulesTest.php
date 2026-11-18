<?php

/**
 * Regression tests for issue #759 ("Pre-processing to help clean up m3u files").
 *
 * Providers sometimes ship malformed stream URLs (e.g. https:// on a port that only
 * serves http). This lets a user define find & replace rules on the Playlist that are
 * applied to each provider stream URL during ProcessM3uImport, before it's saved.
 */

use App\Jobs\ProcessM3uImport;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid('', true).'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
    if (isset($this->tempM3uPath) && file_exists($this->tempM3uPath)) {
        @unlink($this->tempM3uPath);
    }
});

/**
 * Runs ProcessM3uImport against a one-channel M3U file and returns the parsed channel
 * payload row that was queued for ProcessM3uImportChunk (the chain itself is faked, but
 * the M3U parsing under test happens synchronously before the chain is dispatched).
 *
 * @param  array<int, array<string, mixed>>|null  $urlFindReplaceRules
 * @return array<string, mixed>
 */
function importedChannelPayloadRowForUrl(User $user, string $streamUrl, ?array $urlFindReplaceRules): array
{
    $tempM3uPath = sys_get_temp_dir().'/playlist_url_find_replace_'.uniqid().'.m3u';
    file_put_contents($tempM3uPath, implode("\n", [
        '#EXTM3U',
        '#EXTINF:-1 tvg-id="ch-1" group-title="News",Channel One',
        $streamUrl,
    ]));
    test()->tempM3uPath = $tempM3uPath;

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $tempM3uPath,
        'xtream' => false,
        'import_prefs' => [],
        'auto_sort' => false,
        'enable_channels' => true,
        'url_find_replace_rules' => $urlFindReplaceRules,
    ]));

    Bus::fake();
    (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();

    return Job::firstOrFail()->payload[0];
}

it('does not modify the URL when no rules are set (null default)', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRowForUrl($user, 'https://example.test:80/stream/1', null);

    expect($row['url'])->toBe('https://example.test:80/stream/1');
});

it('does not modify the URL when rules are an empty array', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRowForUrl($user, 'https://example.test:80/stream/1', []);

    expect($row['url'])->toBe('https://example.test:80/stream/1');
});

it('does not modify the URL when the only rule is disabled', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRowForUrl($user, 'https://example.test:80/stream/1', [
        [
            'enabled' => false,
            'name' => 'Fix https on port 80',
            'use_regex' => false,
            'find' => 'https://',
            'replace_with' => 'http://',
        ],
    ]);

    expect($row['url'])->toBe('https://example.test:80/stream/1');
});

it('applies a plain string find & replace rule to the provider URL', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRowForUrl($user, 'https://example.test:80/stream/1', [
        [
            'enabled' => true,
            'name' => 'Fix https on port 80',
            'use_regex' => false,
            'find' => 'https://',
            'replace_with' => 'http://',
        ],
    ]);

    expect($row['url'])->toBe('http://example.test:80/stream/1');
});

it('applies a regex find & replace rule to the provider URL', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRowForUrl($user, 'https://example.test:80/stream/1', [
        [
            'enabled' => true,
            'name' => 'Strip https scheme on port 80',
            'use_regex' => true,
            'find' => '^https://(.*):80/',
            'replace_with' => 'http://$1:80/',
        ],
    ]);

    expect($row['url'])->toBe('http://example.test:80/stream/1');
});

it('applies multiple rules in order', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRowForUrl($user, 'https://example.test:80/stream/1', [
        [
            'enabled' => true,
            'name' => 'Fix https on port 80',
            'use_regex' => false,
            'find' => 'https://',
            'replace_with' => 'http://',
        ],
        [
            'enabled' => true,
            'name' => 'Swap host',
            'use_regex' => false,
            'find' => 'example.test',
            'replace_with' => 'mirror.test',
        ],
    ]);

    expect($row['url'])->toBe('http://mirror.test:80/stream/1');
});

it('leaves the URL unchanged when the find string is not present', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRowForUrl($user, 'http://example.test/stream/1', [
        [
            'enabled' => true,
            'name' => 'No match',
            'use_regex' => false,
            'find' => 'nonexistent',
            'replace_with' => 'replacement',
        ],
    ]);

    expect($row['url'])->toBe('http://example.test/stream/1');
});
