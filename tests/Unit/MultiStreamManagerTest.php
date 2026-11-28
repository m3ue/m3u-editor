<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Run a Node script against multi-stream-manager.js and fail the test on any
 * assertion error. Skips when Node is not installed (CI installs it for the
 * frontend build), since the project has no dedicated JS test runner.
 */
function runMultiStreamManagerScript(string $script): void
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('Node.js is not available on PATH.');
    }

    $process = new Process([$node, '--input-type=module', '-e', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
}

const MULTI_STREAM_MANAGER_HARNESS = <<<'JS'
    import assert from 'node:assert/strict';
    import { readFileSync } from 'node:fs';
    import { runInNewContext } from 'node:vm';

    const stops = [];
    const opened = [];
    const context = {
        URLSearchParams,
        window: {
            notifyProxyStreamStop: (...args) => stops.push(args),
            open: (url) => opened.push(url),
        },
        document: {
            documentElement: { clientWidth: 1280, clientHeight: 720 },
            getElementById: () => null,
        },
    };
    runInNewContext(
        readFileSync('resources/js/vendor/multi-stream-manager.js', 'utf8'),
        context,
    );
    const openPlayer = (payload) => {
        const manager = context.window.multiStreamManager();
        manager.openStream({ ...payload, url: 'https://example.test/stream' });

        return { manager, player: manager.players[0] };
    };
    JS;

it('stops floating players using their model ids and preserves the player client id', function () {
    runMultiStreamManagerScript(MULTI_STREAM_MANAGER_HARNESS.<<<'JS'

        for (const payload of [
            { id: 'episode-1722', stream_id: 1722, type: 'episode' },
            { id: 42, stream_id: 42, type: 'channel' },
            { id: 43, type: 'channel' },
        ]) {
            const { manager, player } = openPlayer(payload);
            manager.closeStream(player.id);

            assert.deepEqual(stops.at(-1), [
                payload.stream_id ?? payload.id,
                payload.type,
                player.id,
            ]);
            assert.equal(manager.players.length, 0);
        }

        const { manager, player } = openPlayer({ id: 'episode-1722', stream_id: 1722, type: 'episode' });
        manager.cleanupAllStreams();
        manager.cleanupAllStreams();

        assert.deepEqual(stops.at(-1), [1722, 'episode', player.id]);
        assert.equal(stops.length, 4);
        JS);
});

it('does not send a proxy stop id for DVR recording players', function () {
    runMultiStreamManagerScript(MULTI_STREAM_MANAGER_HARNESS.<<<'JS'

        const dvrPayload = { id: 'dvr-recording-5', stream_id: 5, type: 'channel', content_type: 'dvr_recording' };

        // notifyProxyStreamStop() bails on a falsy id, so a null id means no beacon.
        const { manager, player } = openPlayer(dvrPayload);
        manager.closeStream(player.id);
        assert.deepEqual(stops.at(-1), [null, 'channel', player.id]);

        // Handing off to the pop-out must not give it the recording id either.
        const dvr = openPlayer(dvrPayload);
        dvr.manager.openInNewTab(dvr.player, '/player/popout');
        assert.equal(new URLSearchParams(opened.at(-1).split('?')[1]).get('stream_id'), '');

        const episode = openPlayer({ id: 'episode-1722', stream_id: 1722, type: 'episode', content_type: 'episode' });
        episode.manager.openInNewTab(episode.player, '/player/popout');
        assert.equal(new URLSearchParams(opened.at(-1).split('?')[1]).get('stream_id'), '1722');

        // The pop-out handoff keeps the proxy stream alive, so it sends no stop itself.
        assert.equal(stops.length, 1);
        JS);
});
