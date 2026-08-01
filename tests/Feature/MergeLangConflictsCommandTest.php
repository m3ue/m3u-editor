<?php

test('resolves conflicts without corrupting numeric-string translation keys', function () {
    $path = lang_path('en.json');
    $original = file_get_contents($path);

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
        file_put_contents($path, $original);
    }
});
