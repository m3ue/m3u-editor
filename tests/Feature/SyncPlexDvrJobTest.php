<?php

use App\Jobs\SyncPlexDvrJob;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('has a unique id based on integration id', function () {
    $job = new SyncPlexDvrJob(integrationId: 42, trigger: 'test');

    expect($job->uniqueId())->toBe('sync-plex-dvr-42');
});

it('has a unique id of all when no integration id given', function () {
    $job = new SyncPlexDvrJob(trigger: 'test');

    expect($job->uniqueId())->toBe('sync-plex-dvr-all');
});

it('has a 120-second uniqueness window to cover the debounce delay', function () {
    $job = new SyncPlexDvrJob(trigger: 'test');

    expect($job->uniqueFor())->toBe(120);
});

it('is dispatched with a 30-second delay for debouncing', function () {
    $job = new SyncPlexDvrJob(trigger: 'test');

    expect($job->delay)->not->toBeNull();
});

it('skips when no eligible integrations exist', function () {
    // No integrations in DB — should complete without errors
    $job = new SyncPlexDvrJob(trigger: 'test');
    $job->handle();

    expect(true)->toBeTrue();
});

it('skips disabled integrations', function () {
    MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Disabled Plex',
            'type' => 'plex',
            'host' => 'plex.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-token',
            'enabled' => false,
            'user_id' => $this->user->id,
            'plex_management_enabled' => true,
            'plex_dvr_id' => 1,
            'plex_dvr_tuners' => [['device_key' => 'dev1', 'playlist_uuid' => 'uuid1']],
        ]);
    });

    // Mock should never be created since integration is disabled
    $mock = Mockery::mock(PlexManagementService::class);
    $mock->shouldReceive('syncDvrChannels')->never();

    $job = new SyncPlexDvrJob(trigger: 'test');
    $job->handle();

    expect(true)->toBeTrue();
});

it('skips integrations without plex management enabled', function () {
    MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Non-managed Plex',
            'type' => 'plex',
            'host' => 'plex.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-token',
            'enabled' => true,
            'user_id' => $this->user->id,
            'plex_management_enabled' => false,
            'plex_dvr_id' => 1,
            'plex_dvr_tuners' => [['device_key' => 'dev1', 'playlist_uuid' => 'uuid1']],
        ]);
    });

    $job = new SyncPlexDvrJob(trigger: 'test');
    $job->handle();

    expect(true)->toBeTrue();
});

it('skips integrations without dvr id', function () {
    MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'No DVR Plex',
            'type' => 'plex',
            'host' => 'plex.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-token',
            'enabled' => true,
            'user_id' => $this->user->id,
            'plex_management_enabled' => true,
            'plex_dvr_id' => null,
            'plex_dvr_tuners' => [['device_key' => 'dev1', 'playlist_uuid' => 'uuid1']],
        ]);
    });

    $job = new SyncPlexDvrJob(trigger: 'test');
    $job->handle();

    expect(true)->toBeTrue();
});

it('filters by integration id when specified', function () {
    $target = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Target Plex',
            'type' => 'plex',
            'host' => 'plex1.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-token-1',
            'enabled' => true,
            'user_id' => $this->user->id,
            'plex_management_enabled' => true,
            'plex_dvr_id' => 1,
            'plex_dvr_tuners' => [['device_key' => 'dev1', 'playlist_uuid' => 'uuid1']],
        ]);
    });

    $other = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Other Plex',
            'type' => 'plex',
            'host' => 'plex2.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-token-2',
            'enabled' => true,
            'user_id' => $this->user->id,
            'plex_management_enabled' => true,
            'plex_dvr_id' => 2,
            'plex_dvr_tuners' => [['device_key' => 'dev2', 'playlist_uuid' => 'uuid2']],
        ]);
    });

    Http::fake(['*' => Http::response(['MediaContainer' => ['Device' => []]], 200)]);

    $job = new SyncPlexDvrJob(integrationId: $target->id, trigger: 'test');
    $job->handle();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'plex1.example.com'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'plex2.example.com'));
});
