<?php

namespace Tests\Feature;

use App\Http\Controllers\MediaServerProxyController;
use App\Models\MediaServerIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaServerProxySignedUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_proxy_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $response = $this->get("/media-server/{$integration->id}/stream/abc123.ts");

        $response->assertForbidden();
    }

    public function test_stream_proxy_route_rejects_tampered_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $url = MediaServerProxyController::generateStreamProxyUrl($integration->id, 'abc123', 'ts');
        // Swap the item id after signing so the signature no longer matches.
        $tampered = str_replace('abc123', 'other-item', $url);

        $response = $this->get($tampered);

        $response->assertForbidden();
    }

    public function test_stream_proxy_route_accepts_valid_generated_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $url = MediaServerProxyController::generateStreamProxyUrl($integration->id, 'abc123', 'ts');

        $response = $this->get($url);

        // The signature must pass (not a 403 Invalid Signature middleware rejection).
        // The controller then tries to reach the (nonexistent, in tests) upstream media
        // server, whose failure mode isn't what this test verifies - just that the
        // signature middleware let the request through to the controller.
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_image_proxy_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $response = $this->get("/media-server/{$integration->id}/image/abc123/Primary");

        $response->assertForbidden();
    }

    public function test_local_media_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'local']);

        $response = $this->get("/local-media/{$integration->id}/stream/".base64_encode('/media/foo.mp4'));

        $response->assertForbidden();
    }

    public function test_webdav_media_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'webdav']);

        $response = $this->get("/webdav-media/{$integration->id}/stream/".base64_encode('/foo.mp4'));

        $response->assertForbidden();
    }
}
