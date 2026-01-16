<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->integration = MediaServerIntegration::create([
        'name' => 'Test Jellyfin Server',
        'type' => 'jellyfin',
        'host' => '192.168.1.100',
        'port' => 8096,
        'api_key' => 'test-api-key',
        'enabled' => true,
        'ssl' => false,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'user_id' => $this->user->id,
    ]);
});

it('can create a media server integration', function () {
    expect($this->integration)->toBeInstanceOf(MediaServerIntegration::class);
    expect($this->integration->name)->toBe('Test Jellyfin Server');
    expect($this->integration->type)->toBe('jellyfin');
});

it('has correct initial status', function () {
    expect($this->integration->status)->toBe('idle');
    expect($this->integration->progress)->toBe(0);
    expect($this->integration->movie_progress)->toBe(0);
    expect($this->integration->series_progress)->toBe(0);
});

it('can update sync progress', function () {
    $this->integration->update([
        'status' => 'processing',
        'progress' => 50,
        'movie_progress' => 75,
        'series_progress' => 25,
    ]);

    $this->integration->refresh();

    expect($this->integration->status)->toBe('processing');
    expect($this->integration->progress)->toBe(50);
    expect($this->integration->movie_progress)->toBe(75);
    expect($this->integration->series_progress)->toBe(25);
});

it('can mark sync as completed', function () {
    $this->integration->update([
        'status' => 'completed',
        'progress' => 100,
        'movie_progress' => 100,
        'series_progress' => 100,
        'last_synced_at' => now(),
    ]);

    $this->integration->refresh();

    expect($this->integration->status)->toBe('completed');
    expect($this->integration->progress)->toBe(100);
    expect($this->integration->last_synced_at)->not->toBeNull();
});

it('can mark sync as failed', function () {
    $this->integration->update([
        'status' => 'failed',
        'progress' => 0,
    ]);

    $this->integration->refresh();

    expect($this->integration->status)->toBe('failed');
    expect($this->integration->progress)->toBe(0);
});
