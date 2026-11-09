<?php

it('provides German explanations for the Emby overview controls', function () {
    $keys = [
        'Edit media server',
        'Enable or disable publishing this mapping.',
        'This library is managed by m3u-editor.',
        'This is an existing Emby library.',
        'Delete this mapping',
        'Edit this mapping',
        'Reconcile the published library with Emby',
    ];
    foreach ($keys as $key) {
        expect(__($key, locale: 'de'))->not->toBe($key)
            ->and(__($key, locale: 'en'))->toBe($key);
    }
});
