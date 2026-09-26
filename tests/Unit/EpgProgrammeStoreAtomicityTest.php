<?php

use App\Services\EpgProgrammeStore;

it('removes the building tempfile after a successful atomic finish', function () {
    $dir = sys_get_temp_dir().'/xmltv-1519-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    $path = $dir.'/programmes.sqlite';
    $store = new EpgProgrammeStore;

    try {
        $store->beginWrite($path);
        $store->insert('station', '2025-09-15', 1_000, 2_000, [
            ...EpgProgrammeStore::EMPTY_PROGRAMME,
            'channel' => 'station', 'start' => 'x', 'title' => 'Test',
        ]);
        $store->finish();

        expect(is_file($path))->toBeTrue()
            ->and(glob($path.'.building-*'))->toBe([]);
    } finally {
        $store->discard();
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('preserves the destination and removes the building tempfile after failure', function () {
    $dir = sys_get_temp_dir().'/xmltv-1519-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);
    $path = $dir.'/programmes.sqlite';
    file_put_contents($path, 'existing-destination');
    $store = new EpgProgrammeStore;

    try {
        $store->beginWrite($path);
        $store->insert('station', '2025-09-15', 1_000, 2_000, [
            ...EpgProgrammeStore::EMPTY_PROGRAMME,
            'channel' => 'station', 'start' => 'x', 'title' => 'Transient',
        ]);
        $store->discard();

        expect(file_get_contents($path))->toBe('existing-destination')
            ->and(glob($path.'.building-*'))->toBe([]);
    } finally {
        $store->discard();
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});
