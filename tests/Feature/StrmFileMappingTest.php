<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\StrmFileMapping;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->testDir = sys_get_temp_dir() . '/strm-test-' . uniqid();
    @mkdir($this->testDir, 0755, true);
});

afterEach(function () {
    // Clean up test directory
    if (isset($this->testDir) && is_dir($this->testDir)) {
        $this->recursiveDelete($this->testDir);
    }
});

// Helper closure to recursively delete a directory, available on $this
beforeEach(function () {
    $this->recursiveDelete = function (string $dir): void {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    $path = $dir . '/' . $object;
                    if (is_dir($path)) {
                        ($this->recursiveDelete)($path);
                    } else {
                        @unlink($path);
                    }
                }
            }
            @rmdir($dir);
        }
    };
});

describe('StrmFileMapping', function () {
    it('can create a new strm file', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Movies/Test Movie.strm';
        $url = 'http://example.com/stream.ts';

        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url, ['test' => true]);

        expect($mapping)->toBeInstanceOf(StrmFileMapping::class);
        expect($mapping->syncable_type)->toBe(Channel::class);
        expect($mapping->syncable_id)->toBe($channel->id);
        expect($mapping->sync_location)->toBe($this->testDir);
        expect($mapping->current_path)->toBe($path);
        expect($mapping->current_url)->toBe($url);
        expect($mapping->path_options)->toBe(['test' => true]);
        expect(file_exists($path))->toBeTrue();
        expect(file_get_contents($path))->toBe($url);
    });

    it('can rename an existing strm file when path changes', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $oldPath = $this->testDir . '/Movies/Old Name.strm';
        $newPath = $this->testDir . '/Movies/New Name.strm';
        $url = 'http://example.com/stream.ts';

        // Create initial file
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $oldPath, $url);
        expect(file_exists($oldPath))->toBeTrue();
        $originalId = $mapping->id;

        // Rename by syncing with new path
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $newPath, $url);

        expect($mapping->id)->toBe($originalId);
        expect($mapping->current_path)->toBe($newPath);
        expect(file_exists($newPath))->toBeTrue();
        expect(file_exists($oldPath))->toBeFalse();
    });

    it('can update url without renaming file', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Movies/Test Movie.strm';
        $oldUrl = 'http://example.com/old-stream.ts';
        $newUrl = 'http://example.com/new-stream.ts';

        // Create initial file
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $oldUrl);
        expect(file_get_contents($path))->toBe($oldUrl);

        // Update URL
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $newUrl);

        expect($mapping->current_url)->toBe($newUrl);
        expect(file_get_contents($path))->toBe($newUrl);
    });

    it('can delete strm file and mapping', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Movies/Test Movie.strm';
        $url = 'http://example.com/stream.ts';

        // Create file
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url);
        $mappingId = $mapping->id;
        expect(file_exists($path))->toBeTrue();

        // Delete file
        $mapping->deleteFile();

        expect(file_exists($path))->toBeFalse();
        expect(StrmFileMapping::find($mappingId))->toBeNull();
    });

    it('cleans up empty directories after file deletion', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Movies/SubFolder/Test Movie.strm';
        $url = 'http://example.com/stream.ts';

        // Create file (creates directories)
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url);
        expect(is_dir($this->testDir . '/Movies/SubFolder'))->toBeTrue();

        // Delete file
        $mapping->deleteFile();

        expect(is_dir($this->testDir . '/Movies/SubFolder'))->toBeFalse();
        expect(is_dir($this->testDir . '/Movies'))->toBeFalse();
        // Sync location should still exist
        expect(is_dir($this->testDir))->toBeTrue();
    });

    it('cleans up empty directories after file rename', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $oldPath = $this->testDir . '/OldFolder/Test Movie.strm';
        $newPath = $this->testDir . '/NewFolder/Test Movie.strm';
        $url = 'http://example.com/stream.ts';

        // Create initial file
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $oldPath, $url);
        expect(is_dir($this->testDir . '/OldFolder'))->toBeTrue();

        // Rename to new folder
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $newPath, $url);

        expect(is_dir($this->testDir . '/NewFolder'))->toBeTrue();
        expect(is_dir($this->testDir . '/OldFolder'))->toBeFalse();
    });

    it('does not delete sync location when cleaning up', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Test Movie.strm';
        $url = 'http://example.com/stream.ts';

        // Create file directly in sync location
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url);

        // Delete file
        $mapping->deleteFile();

        // Sync location should still exist
        expect(is_dir($this->testDir))->toBeTrue();
    });

    it('returns existing mapping without changes when nothing changed', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Movies/Test Movie.strm';
        $url = 'http://example.com/stream.ts';

        // Create initial file
        $mapping1 = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url, ['option' => 'value']);

        // Sync again with same values
        $mapping2 = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url, ['option' => 'value']);

        expect($mapping1->id)->toBe($mapping2->id);
        expect(StrmFileMapping::count())->toBe(1);
    });

    it('recreates file if it was externally deleted', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Movies/Test Movie.strm';
        $url = 'http://example.com/stream.ts';
        $newUrl = 'http://example.com/new-stream.ts';

        // Create initial file
        StrmFileMapping::syncFile($channel, $this->testDir, $path, $url);

        // Externally delete the file
        @unlink($path);
        expect(file_exists($path))->toBeFalse();

        // Sync with new URL should recreate the file
        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $newUrl);

        expect(file_exists($path))->toBeTrue();
        expect(file_get_contents($path))->toBe($newUrl);
    });
});

describe('StrmFileMapping relationships', function () {
    it('has morphTo syncable relationship', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Test.strm';
        $url = 'http://example.com/stream.ts';

        $mapping = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url);

        expect($mapping->syncable)->toBeInstanceOf(Channel::class);
        expect($mapping->syncable->id)->toBe($channel->id);
    });

    it('channel has morphMany strmFileMappings relationship', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path1 = $this->testDir . '/location1/Test.strm';
        $path2 = $this->testDir . '/location2/Test.strm';
        $url = 'http://example.com/stream.ts';

        StrmFileMapping::syncFile($channel, $this->testDir . '/location1', $path1, $url);
        StrmFileMapping::syncFile($channel, $this->testDir . '/location2', $path2, $url);

        expect($channel->strmFileMappings)->toHaveCount(2);
    });
});

describe('StrmFileMapping cleanup', function () {
    it('can clean up orphaned mappings', function () {
        $channel = Channel::factory()->create([
            'user_id' => $this->user->id,
            'enabled' => true,
        ]);
        $disabledChannel = Channel::factory()->create([
            'user_id' => $this->user->id,
            'enabled' => false,
        ]);

        $path1 = $this->testDir . '/enabled.strm';
        $path2 = $this->testDir . '/disabled.strm';
        $url = 'http://example.com/stream.ts';

        StrmFileMapping::syncFile($channel, $this->testDir, $path1, $url);
        StrmFileMapping::syncFile($disabledChannel, $this->testDir, $path2, $url);

        expect(StrmFileMapping::count())->toBe(2);

        // Clean up orphaned (disabled channels)
        $count = StrmFileMapping::cleanupOrphaned(Channel::class, $this->testDir);

        expect($count)->toBe(1);
        expect(StrmFileMapping::count())->toBe(1);
        expect(file_exists($path1))->toBeTrue();
        expect(file_exists($path2))->toBeFalse();
    });

    it('cleans up mappings when syncable is deleted', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Test.strm';
        $url = 'http://example.com/stream.ts';

        StrmFileMapping::syncFile($channel, $this->testDir, $path, $url);
        expect(StrmFileMapping::count())->toBe(1);

        // Delete the channel
        $channel->delete();

        // Clean up orphaned
        $count = StrmFileMapping::cleanupOrphaned(Channel::class, $this->testDir);

        expect($count)->toBe(1);
        expect(StrmFileMapping::count())->toBe(0);
    });
});

describe('StrmFileMapping findForSyncable', function () {
    it('finds mapping by syncable and location', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);
        $path = $this->testDir . '/Test.strm';
        $url = 'http://example.com/stream.ts';

        $created = StrmFileMapping::syncFile($channel, $this->testDir, $path, $url);
        $found = StrmFileMapping::findForSyncable($channel, $this->testDir);

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($created->id);
    });

    it('returns null when mapping not found', function () {
        $channel = Channel::factory()->create(['user_id' => $this->user->id]);

        $found = StrmFileMapping::findForSyncable($channel, $this->testDir);

        expect($found)->toBeNull();
    });
});
