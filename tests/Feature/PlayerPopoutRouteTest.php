<?php

namespace Tests\Feature;

use Tests\TestCase;

class PlayerPopoutRouteTest extends TestCase
{
    public function test_returns_404_for_player_popout_without_stream_url(): void
    {
        $this->get('/player/popout')
            ->assertNotFound();
    }

    public function test_renders_player_popout_with_provided_stream_data(): void
    {
        $this->get('/player/popout?url=http://example.test/stream.ts&format=ts&title=Test+Channel')
            ->assertOk()
            ->assertSee('Test Channel - Player', false)
            ->assertSee('id="popout-player"', false)
            ->assertSee('data-url="http://example.test/stream.ts"', false)
            ->assertSee('data-format="ts"', false);
    }

    public function test_rejects_unsupported_stream_url_schemes(): void
    {
        $this->get('/player/popout?url=javascript:alert(1)')
            ->assertNotFound();
    }

    public function test_accepts_relative_stream_url_path(): void
    {
        $this->get('/player/popout?url=/api/m3u-proxy/channel/1/player&format=hls')
            ->assertOk()
            ->assertSee('data-url="/api/m3u-proxy/channel/1/player"', false)
            ->assertSee('data-format="hls"', false);
    }

    public function test_falls_back_to_ts_for_unsupported_stream_format(): void
    {
        $this->get('/player/popout?url=http://example.test/stream.ts&format=avi')
            ->assertOk()
            ->assertSee('data-format="ts"', false);
    }
}
