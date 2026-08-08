<?php

use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'proxy.m3u_resolver_url' => 'https://resolver.example.com',
        'proxy.m3u_proxy_token' => 'super-secret-token',
    ]);
});

it('embeds the shared token in the failover resolver url', function () {
    $url = (new M3uProxyService)->getFailoverResolverUrl();

    $this->assertStringContainsString('/api/m3u-proxy/failover-resolver', $url);
    $this->assertStringContainsString('api_token=super-secret-token', $url);
});

it('embeds the shared token in the broadcast callback url', function () {
    $url = (new M3uProxyService)->getBroadcastCallbackUrl();

    $this->assertStringContainsString('/api/m3u-proxy/broadcast/callback', $url);
    $this->assertStringContainsString('api_token=super-secret-token', $url);
});

it('embeds the shared token in the webhook url', function () {
    $url = (new M3uProxyService)->getWebhookUrl();

    $this->assertStringContainsString('/api/m3u-proxy/webhooks', $url);
    $this->assertStringContainsString('api_token=super-secret-token', $url);
});

it('embeds the shared token in the dvr callback url', function () {
    $url = (new M3uProxyService)->getDvrCallbackUrl();

    $this->assertStringContainsString('/api/dvr/callback', $url);
    $this->assertStringContainsString('api_token=super-secret-token', $url);
});

it('omits the token param in callback urls when none configured', function () {
    config(['proxy.m3u_proxy_token' => null]);

    $url = (new M3uProxyService)->getFailoverResolverUrl();

    $this->assertStringNotContainsString('api_token=', $url);
});
