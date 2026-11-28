<?php

use Symfony\Component\Process\Process;

it('stops floating players using their model ids and preserves the player client id', function () {
    $process = new Process(['node', '--input-type=module', '-e', <<<'JS'
        import assert from 'node:assert/strict';
        import { readFileSync } from 'node:fs';
        import { runInNewContext } from 'node:vm';

        const stops = [];
        const context = {
            window: { notifyProxyStreamStop: (...args) => stops.push(args) },
            document: {
                documentElement: { clientWidth: 1280, clientHeight: 720 },
                getElementById: () => null,
            },
        };
        runInNewContext(
            readFileSync('resources/js/vendor/multi-stream-manager.js', 'utf8'),
            context,
        );

        for (const payload of [
            { id: 'episode-1722', stream_id: 1722, type: 'episode' },
            { id: 42, stream_id: 42, type: 'channel' },
            { id: 43, type: 'channel' },
        ]) {
            const manager = context.window.multiStreamManager();
            manager.openStream({ ...payload, url: 'https://example.test/stream' });
            const playerId = manager.players[0].id;

            manager.closeStream(playerId);

            assert.deepEqual(stops.at(-1), [
                payload.stream_id ?? payload.id,
                payload.type,
                playerId,
            ]);
            assert.equal(manager.players.length, 0);
        }

        const manager = context.window.multiStreamManager();
        manager.openStream({
            id: 'episode-1722', stream_id: 1722, type: 'episode',
            url: 'https://example.test/stream',
        });
        const playerId = manager.players[0].id;
        manager.cleanupAllStreams();
        manager.cleanupAllStreams();

        assert.deepEqual(stops.at(-1), [1722, 'episode', playerId]);
        assert.equal(stops.length, 4);
        JS,
    ], base_path());

    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});
