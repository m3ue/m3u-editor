<?php

it('returns 404 for player popout without stream url', function () {
    $this->get('/player/popout')
        ->assertNotFound();
});

it('renders player popout with provided stream data', function () {
    $this->get('/player/popout?url=http://example.test/stream.ts&format=ts&title=Test+Channel')
        ->assertOk()
        ->assertSee('Test Channel - Player', false)
        ->assertSee('id="popout-player"', false)
        ->assertSee('data-url="http://example.test/stream.ts"', false)
        ->assertSee('data-format="ts"', false);
});

it('rejects unsupported stream url schemes', function () {
    $this->get('/player/popout?url=javascript:alert(1)')
        ->assertNotFound();
});

it('accepts relative stream url path', function () {
    $this->get('/player/popout?url=/api/m3u-proxy/channel/1/player&format=hls')
        ->assertOk()
        ->assertSee('data-url="/api/m3u-proxy/channel/1/player"', false)
        ->assertSee('data-format="hls"', false);
});

it('falls back to ts for unsupported stream format', function () {
    $this->get('/player/popout?url=http://example.test/stream.ts&format=avi')
        ->assertOk()
        ->assertSee('data-format="ts"', false);
});
