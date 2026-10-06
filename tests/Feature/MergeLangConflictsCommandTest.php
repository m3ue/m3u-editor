<?php

beforeEach(function () {
    // Point lang_path() at a throwaway directory for the duration of each test.
    // The command rewrites every *.json under lang_path(), so operating on the
    // real lang/en.json would corrupt it for any other test rendering a Blade
    // view while this one runs (the suite runs in parallel).
    $this->tmpLangPath = sys_get_temp_dir().'/lang-merge-'.uniqid();
    mkdir($this->tmpLangPath);

    $this->originalLangPath = app()->langPath();
    app()->useLangPath($this->tmpLangPath);
});

afterEach(function () {
    app()->useLangPath($this->originalLangPath);

    foreach (glob($this->tmpLangPath.'/*.json') as $file) {
        @unlink($file);
    }

    @rmdir($this->tmpLangPath);
});

test('resolves conflicts without corrupting numeric-string translation keys', function () {
    $path = $this->tmpLangPath.'/en.json';

    file_put_contents($path, <<<'JSON'
{
<<<<<<< HEAD
    "9": 9,
    "10": 10,
    "Head only": "Head only"
=======
    "9": 9,
    "10": 10,
    "Their only": "Their only"
>>>>>>> theirs
}
JSON);

    $this->artisan('lang:merge-conflicts')->assertExitCode(0);

    expect(json_decode(file_get_contents($path), true))
        ->toHaveKey('9', 9)
        ->toHaveKey('10', 10)
        ->toHaveKey('Head only', 'Head only')
        ->toHaveKey('Their only', 'Their only');
});

test('re-sorts conflict-free lang files by key', function () {
    $path = $this->tmpLangPath.'/en.json';

    // Deliberately out of order, no conflict markers.
    file_put_contents($path, <<<'JSON'
    {
        "Zebra": "Zebra",
        "10": 10,
        "Apple": "Apple",
        "9": 9
    }
    JSON);

    $this->artisan('lang:merge-conflicts')
        ->expectsOutputToContain('Re-sorted 1 conflict-free file(s).')
        ->assertExitCode(0);

    $raw = file_get_contents($path);

    // Keys compare byte-wise as strings, so "10" sorts before "9". PHP casts
    // numeric-string array keys to int, so they come back as integers.
    expect(array_keys(json_decode($raw, true)))->toBe([10, 9, 'Apple', 'Zebra'])
        ->and($raw)->toEndWith("}\n");
});

test('sorts to the same order regardless of the incoming key order', function () {
    // Mixed int-cast and string keys made the default ksort() order depend on
    // the input order (9 < 10, "10" < "1a", "1a" < 9), so a merge that fed keys
    // in a different order produced a different "sorted" file.
    $keys = ['9', '10', '1a', '0 */6 * * *', '8.7', 'Apple', 'apple', '#'];

    $write = function (string $filename, array $orderedKeys): void {
        $entries = array_map(fn (string $key) => '    '.json_encode($key).': '.json_encode($key), $orderedKeys);
        file_put_contents($this->tmpLangPath.'/'.$filename, "{\n".implode(",\n", $entries)."\n}\n");
    };

    $write('a.json', $keys);
    $write('b.json', array_reverse($keys));
    $write('c.json', ['10', '1a', '9', 'apple', '#', '8.7', 'Apple', '0 */6 * * *']);

    $this->artisan('lang:merge-conflicts')->assertExitCode(0);

    $sorted = file_get_contents($this->tmpLangPath.'/a.json');

    expect(file_get_contents($this->tmpLangPath.'/b.json'))->toBe($sorted)
        ->and(file_get_contents($this->tmpLangPath.'/c.json'))->toBe($sorted)
        ->and(array_map('strval', array_keys(json_decode($sorted, true))))
        ->toBe(['#', '0 */6 * * *', '10', '1a', '8.7', '9', 'Apple', 'apple']);
});

test('is idempotent when run repeatedly', function () {
    $path = $this->tmpLangPath.'/en.json';

    file_put_contents($path, <<<'JSON'
{
    "Zebra": "Zebra",
<<<<<<< HEAD
    "9": 9,
    "1a": "1a",
    "Head only": "Head only",
=======
    "10": 10,
    "Their only": "Their only",
>>>>>>> theirs
    "Apple": "Apple"
}
JSON);

    $this->artisan('lang:merge-conflicts')
        ->expectsOutputToContain('Resolved conflicts in 1 file(s).')
        ->assertExitCode(0);

    $afterFirstRun = file_get_contents($path);

    $this->artisan('lang:merge-conflicts')
        ->expectsOutputToContain('already sorted')
        ->doesntExpectOutputToContain('Re-sorted')
        ->assertExitCode(0);

    expect(file_get_contents($path))->toBe($afterFirstRun);
});
