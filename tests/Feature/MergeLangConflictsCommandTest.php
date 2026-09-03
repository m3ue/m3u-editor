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
