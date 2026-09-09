<?php

test('resolves conflicts without corrupting numeric-string translation keys', function () {
    // Point lang_path() at a throwaway directory for the duration of this test.
    // The command rewrites every *.json under lang_path(), so operating on the
    // real lang/en.json would corrupt it for any other test rendering a Blade
    // view while this one runs (the suite runs in parallel).
    $tmpLangPath = sys_get_temp_dir().'/lang-merge-'.uniqid();
    mkdir($tmpLangPath);
    $path = $tmpLangPath.'/en.json';

    $originalLangPath = app()->langPath();
    app()->useLangPath($tmpLangPath);

    $conflicted = <<<'JSON'
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
JSON;

    file_put_contents($path, $conflicted);

    try {
        $this->artisan('lang:merge-conflicts')->assertExitCode(0);

        $merged = json_decode(file_get_contents($path), true);

        expect($merged)
            ->toHaveKey('9', 9)
            ->toHaveKey('10', 10)
            ->toHaveKey('Head only', 'Head only')
            ->toHaveKey('Their only', 'Their only');
    } finally {
        app()->useLangPath($originalLangPath);
        @unlink($path);
        @rmdir($tmpLangPath);
    }
});

test('re-sorts conflict-free lang files alphabetically', function () {
    $tmpLangPath = sys_get_temp_dir().'/lang-merge-'.uniqid();
    mkdir($tmpLangPath);
    $path = $tmpLangPath.'/en.json';

    $originalLangPath = app()->langPath();
    app()->useLangPath($tmpLangPath);

    // Deliberately out of order, no conflict markers.
    file_put_contents($path, <<<'JSON'
    {
        "Zebra": "Zebra",
        "10": 10,
        "Apple": "Apple",
        "9": 9
    }
    JSON);

    try {
        $this->artisan('lang:merge-conflicts')->assertExitCode(0);

        $raw = file_get_contents($path);
        // PHP casts numeric-string array keys to int, so 9/10 come back as integers.
        expect(array_keys(json_decode($raw, true)))->toBe([9, 10, 'Apple', 'Zebra'])
            ->and($raw)->toEndWith("}\n");
    } finally {
        app()->useLangPath($originalLangPath);
        @unlink($path);
        @rmdir($tmpLangPath);
    }
});
