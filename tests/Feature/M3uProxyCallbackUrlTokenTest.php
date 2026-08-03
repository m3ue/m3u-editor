<?php

namespace Tests\Feature;

use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M3uProxyCallbackUrlTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'proxy.m3u_resolver_url' => 'https://resolver.example.com',
            'proxy.m3u_proxy_token' => 'super-secret-token',
        ]);
    }

    public function test_failover_resolver_url_embeds_the_shared_token()
    {
        $url = (new M3uProxyService)->getFailoverResolverUrl();

        $this->assertStringContainsString('/api/m3u-proxy/failover-resolver', $url);
        $this->assertStringContainsString('api_token=super-secret-token', $url);
    }

    public function test_broadcast_callback_url_embeds_the_shared_token()
    {
        $url = (new M3uProxyService)->getBroadcastCallbackUrl();

        $this->assertStringContainsString('/api/m3u-proxy/broadcast/callback', $url);
        $this->assertStringContainsString('api_token=super-secret-token', $url);
    }

    public function test_webhook_url_embeds_the_shared_token()
    {
        $url = (new M3uProxyService)->getWebhookUrl();

        $this->assertStringContainsString('/api/m3u-proxy/webhooks', $url);
        $this->assertStringContainsString('api_token=super-secret-token', $url);
    }

    public function test_dvr_callback_url_embeds_the_shared_token()
    {
        $url = (new M3uProxyService)->getDvrCallbackUrl();

        $this->assertStringContainsString('/api/dvr/callback', $url);
        $this->assertStringContainsString('api_token=super-secret-token', $url);
    }

    public function test_callback_urls_omit_token_param_when_none_configured()
    {
        config(['proxy.m3u_proxy_token' => null]);

        $url = (new M3uProxyService)->getFailoverResolverUrl();

        $this->assertStringNotContainsString('api_token=', $url);
    }
}
