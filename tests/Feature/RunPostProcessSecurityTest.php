<?php

/**
 * Security boundaries of RunPostProcess:
 *  - Local scripts only run for admin-owned post processes, and the path is
 *    executed as a single argument (never through a shell), so it can't
 *    smuggle in extra commands. Enforced at run time because records can be
 *    written without form validation (e.g. Copilot create/edit tools).
 *  - Webhook redirects are held to the same private network guard as the
 *    webhook URL itself.
 */

use App\Enums\Status;
use App\Filament\Resources\PostProcesses\Pages\CreatePostProcess;
use App\Jobs\RunPostProcess;
use App\Models\Playlist;
use App\Models\PostProcess;
use App\Models\PostProcessLog;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();

    // Pin the default; a local .env may opt in to private webhook URLs.
    config(['proxy.allow_private_webhook_urls' => false]);

    $this->scriptDir = sys_get_temp_dir().'/m3u post process '.uniqid();
    File::ensureDirectoryExists($this->scriptDir);
});

afterEach(function () {
    File::deleteDirectory($this->scriptDir);
});

/**
 * Write an executable shell script into the test's temp directory.
 */
function writePostProcessScript(string $directory, string $filename, string $body): string
{
    $path = $directory.'/'.$filename;
    file_put_contents($path, "#!/bin/sh\n{$body}\n");
    chmod($path, 0755);

    return $path;
}

/**
 * Run the job for a post process owned by $owner against a playlist owned by
 * the same user, and return the log row it wrote.
 *
 * @param  array<string, mixed>  $metadata
 */
function runPostProcessFor(User $owner, array $metadata): PostProcessLog
{
    $playlist = Playlist::factory()->for($owner)->createQuietly([
        'name' => 'Security Test Playlist',
        'status' => Status::Completed,
    ]);
    $postProcess = PostProcess::factory()->create([
        'user_id' => $owner->id,
        'event' => 'synced',
        'enabled' => true,
        'metadata' => $metadata,
    ]);

    (new RunPostProcess($postProcess, $playlist))->handle(app(GeneralSettings::class));

    return PostProcessLog::where('post_process_id', $postProcess->id)->sole();
}

it('runs an admin-owned local script and passes export variables to it', function () {
    $script = writePostProcessScript($this->scriptDir, 'hook.sh', 'echo "synced $PLAYLIST_NAME"');

    $log = runPostProcessFor(User::factory()->admin()->create(), [
        'local' => 'path',
        'path' => $script,
        'script_vars' => [['export_name' => 'PLAYLIST_NAME', 'value' => 'name']],
    ]);

    expect($log->status)->toBe('success')
        ->and(trim($log->message))->toBe('synced Security Test Playlist');
});

it('refuses to run a local script owned by a non-admin user', function () {
    $script = writePostProcessScript($this->scriptDir, 'hook.sh', 'echo "should not run"');

    $log = runPostProcessFor(User::factory()->create(), [
        'local' => 'path',
        'path' => $script,
    ]);

    expect($log->status)->toBe('error')
        ->and($log->message)->toContain('owned by an admin')
        ->and($log->message)->not->toContain('should not run');
});

it('treats shell metacharacters in the script path as part of the file name', function () {
    // Through a shell this path would run "hook.sh" and then "echo INJECTED".
    // Executed as a single argument it is just an odd file name.
    $script = writePostProcessScript($this->scriptDir, 'hook.sh;echo INJECTED', 'echo "literal file ran"');

    $log = runPostProcessFor(User::factory()->admin()->create(), [
        'local' => 'path',
        'path' => $script,
    ]);

    expect($log->status)->toBe('success')
        ->and(trim($log->message))->toBe('literal file ran')
        ->and($log->message)->not->toContain('INJECTED');
});

it('fails without running anything when the script path is not an existing file', function () {
    $log = runPostProcessFor(User::factory()->admin()->create(), [
        'local' => 'path',
        'path' => '/usr/bin/id; echo INJECTED',
    ]);

    expect($log->status)->toBe('error')
        ->and($log->message)->toContain('was not found')
        ->and($log->message)->not->toContain('uid=');
});

it('does not let a non-admin save a Local file post process from the form', function () {
    $script = writePostProcessScript($this->scriptDir, 'hook.sh', 'echo "hi"');
    $this->actingAs(User::factory()->create(['permissions' => ['use_tools']]));

    Livewire::test(CreatePostProcess::class)
        ->fillForm([
            'name' => 'Script',
            'event' => 'synced',
            'metadata' => ['local' => 'path', 'path' => $script],
        ])
        ->call('create')
        ->assertHasFormErrors(['metadata.local' => 'in']);

    expect(PostProcess::count())->toBe(0);
});

it('lets an admin save a Local file post process from the form', function () {
    $script = writePostProcessScript($this->scriptDir, 'hook.sh', 'echo "hi"');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreatePostProcess::class)
        ->fillForm([
            'name' => 'Script',
            'event' => 'synced',
            'metadata' => ['local' => 'path', 'path' => $script, 'script_vars' => []],
            'conditions' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PostProcess::sole()->metadata['path'])->toBe($script);
});

it('does not follow a webhook redirect to a private address', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://198.51.100.1/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/*' => Http::response('internal secret', 200),
    ]);

    $log = runPostProcessFor(User::factory()->create(), [
        'local' => 'url',
        'path' => 'http://198.51.100.1/hook',
        'post' => false,
    ]);

    expect($log->status)->toBe('error')
        ->and($log->message)->toContain('127.0.0.1')
        ->and($log->message)->not->toContain('internal secret');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1'));
});

it('still follows a webhook redirect between public hosts', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://198.51.100.1/*' => Http::response('', 302, ['Location' => 'http://198.51.100.2/hook']),
        'http://198.51.100.2/*' => Http::response('webhook ok', 200),
    ]);

    $log = runPostProcessFor(User::factory()->create(), [
        'local' => 'url',
        'path' => 'http://198.51.100.1/hook',
        'post' => true,
    ]);

    expect($log->status)->toBe('success')
        ->and($log->message)->toBe('webhook ok');
});

it('follows webhook redirects to private addresses when private webhook URLs are allowed', function () {
    config(['proxy.allow_private_webhook_urls' => true]);

    Http::preventStrayRequests();
    Http::fake([
        'http://198.51.100.1/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/*' => Http::response('lan webhook ok', 200),
    ]);

    $log = runPostProcessFor(User::factory()->create(), [
        'local' => 'url',
        'path' => 'http://198.51.100.1/hook',
        'post' => false,
    ]);

    expect($log->status)->toBe('success')
        ->and($log->message)->toBe('lan webhook ok');
});
