// Run after composer install: node tests/Browser/epg-tooltip-server.mjs
// Open the printed URL. The fixture reports pass/fail without browser dependencies.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';

const root = new URL('../../', import.meta.url);
const files = new Map([
    ['/', ['tests/Browser/epg-tooltip.html', 'text/html; charset=utf-8']],
    ['/epg-viewer.js', ['resources/js/vendor/epg-viewer.js', 'text/javascript']],
    ['/filament-support.js', ['vendor/filament/support/dist/index.js', 'text/javascript']],
    ['/filament-support.css', ['vendor/filament/support/dist/index.css', 'text/css']],
    ['/livewire.js', ['vendor/livewire/livewire/dist/livewire.js', 'text/javascript']],
]);
const port = Number(process.env.PORT || 8767);
const host = process.env.HOST || '127.0.0.1';
createServer(async (request, response) => {
    const entry = files.get(request.url);
    if (!entry) {
        response.writeHead(404).end();
        return;
    }
    try {
        const content = await readFile(new URL(entry[0], root));
        response.writeHead(200, { 'Content-Type': entry[1], 'Cache-Control': 'no-store' }).end(content);
    } catch (error) {
        response.writeHead(500).end(error.message);
    }
}).listen(port, host, () => console.log(`EPG tooltip regression: http://${host}:${port}/`));
