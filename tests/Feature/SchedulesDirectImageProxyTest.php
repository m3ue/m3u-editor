<?php

use App\Enums\EpgSourceType;
use App\Models\Epg;
use App\Models\User;
use App\Services\LogoCacheService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Bus::fake();
    Http::preventStrayRequests();

    $this->epg = Epg::factory()->create([
        'user_id' => User::factory()->create()->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'person@example.com',
        'sd_password' => 'super-secret-password',
        'sd_lineup_ids' => ['USA-NY12345-X'],
        'sd_token' => 'still-good',
        'sd_token_expires_at' => now()->addHours(6),
    ]);
});

function sdImageProxyJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    for ($i = 0; $i < 400; $i++) {
        $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefilledrectangle($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $colour);
    }
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

it('caches programme art on disk sized by its orientation', function (int $width, int $height, int $expectedWidth) {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response(sdImageProxyJpeg($width, $height), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->get("/schedules-direct/{$this->epg->uuid}/image/hash123.jpg");

    $response->assertOk();
    expect(getimagesizefromstring($response->streamedContent())[0])->toBe($expectedWidth)
        ->and($response->headers->get('X-Proxied-From'))->toBe('SchedulesDirect')
        ->and(Cache::get("sd_image_{$this->epg->uuid}_hash123.jpg"))->toBeNull();

    $sourceKey = LogoCacheService::schedulesDirectSourceKey($this->epg->uuid, 'hash123.jpg');
    expect(Storage::disk('local')->exists(LogoCacheService::variantFileFor($sourceKey, 'jpg', $expectedWidth)))->toBeTrue();
})->with([
    'landscape as backdrop' => [1920, 1080, 1280],
    'portrait as poster' => [1000, 1500, 600],
]);

it('serves cached programme art even after the daily download limit is reached', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response(sdImageProxyJpeg(1920, 1080), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $path = "/schedules-direct/{$this->epg->uuid}/image/hash123.jpg";
    $cached = $this->get($path)->streamedContent();

    Cache::put("sd_download_limit_{$this->epg->uuid}", true, now()->endOfDay());

    $response = $this->get($path);

    $response->assertOk();
    expect($response->streamedContent())->toBe($cached);
    Http::assertSentCount(1);

    // An uncached image still respects the limit.
    $this->get("/schedules-direct/{$this->epg->uuid}/image/other.jpg")->assertStatus(429);
});

it('serves the previously sized copy past the download limit after the width setting changes', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response(sdImageProxyJpeg(1920, 1080), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $path = "/schedules-direct/{$this->epg->uuid}/image/hash123.jpg";
    $this->get($path)->assertOk();

    $settings = app(GeneralSettings::class);
    $settings->image_backdrop_width = 1000;
    $settings->save();
    Cache::put("sd_download_limit_{$this->epg->uuid}", true, now()->endOfDay());

    $response = $this->get($path);

    $response->assertOk();
    expect(getimagesizefromstring($response->streamedContent())[0])->toBe(1280);
    Http::assertSentCount(1);
});

it('serves the cached copy past the download limit once optimization is turned off', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response(sdImageProxyJpeg(1920, 1080), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $path = "/schedules-direct/{$this->epg->uuid}/image/hash123.jpg";
    $cached = $this->get($path)->streamedContent();

    config()->set('proxy.image_resize_enabled', false);
    Cache::put("sd_download_limit_{$this->epg->uuid}", true, now()->endOfDay());

    $response = $this->get($path);

    $response->assertOk();
    expect($response->streamedContent())->toBe($cached);
    Http::assertSentCount(1);
});

it('never logs in from the image proxy while the account login is blocked', function () {
    $this->epg->forceFill([
        'sd_token' => null,
        'sd_token_expires_at' => null,
        'sd_auth_blocked_code' => 4001,
        'sd_auth_blocked_message' => 'expired',
    ])->saveQuietly();

    $this->get("/schedules-direct/{$this->epg->uuid}/image/blocked.jpg")->assertForbidden();
    $this->get("/schedules-direct/{$this->epg->uuid}/image/blocked-2.jpg")->assertForbidden();

    Http::assertNothingSent();
});

it('treats a daily download limit sent as HTTP 200 JSON as an error, not an image', function (int $code) {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response(['code' => $code, 'response' => 'MAX_IMAGE_DOWNLOADS', 'message' => 'Daily image download limit reached.'], 200, ['Content-Type' => 'application/json']),
    ]);

    $this->get("/schedules-direct/{$this->epg->uuid}/image/limited.jpg")->assertStatus(429);

    // The JSON body was never stored as the image.
    expect(LogoCacheService::findAnyCopy(LogoCacheService::schedulesDirectSourceKey($this->epg->uuid, 'limited.jpg')))->toBeNull();

    // Every other uncached image waits for the limit to reset at 00:00 UTC.
    $this->get("/schedules-direct/{$this->epg->uuid}/image/other.jpg")->assertStatus(429);
    Http::assertSentCount(1);

    $this->travelTo(now('UTC')->addDay()->startOfDay()->addSecond());
    expect(Cache::has("sd_download_limit_{$this->epg->uuid}"))->toBeFalse();
})->with(['subscriber' => [5002], 'trial' => [5003]]);

it('remembers a not-found image (5000) for weeks instead of re-requesting it', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response(['code' => 5000, 'response' => 'IMAGE_NOT_FOUND', 'message' => 'Image not found.'], 404),
    ]);
    $path = "/schedules-direct/{$this->epg->uuid}/image/missing.jpg";

    $this->get($path)->assertNotFound();
    $this->travel(29)->days();
    $this->get($path)->assertNotFound();

    Http::assertSentCount(1);
});

it('blocks the account when /image reports it expired (4001)', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response(['code' => 4001, 'response' => 'ACCOUNT_EXPIRED', 'message' => 'Account expired on 2026-05-04T16:52:36Z', 'accountExpiration' => 1777913556], 403),
    ]);

    $this->get("/schedules-direct/{$this->epg->uuid}/image/first.jpg")->assertForbidden();

    $fresh = $this->epg->fresh();
    expect($fresh->sd_auth_blocked_code)->toBe(4001)
        ->and($fresh->sd_token)->toBeNull();

    // Later images neither download nor log in.
    $this->get("/schedules-direct/{$this->epg->uuid}/image/second.jpg")->assertForbidden();
    Http::assertSentCount(1);
});

it('logs in once and retries when /image reports the token expired or missing', function (int $code) {
    $this->epg->forceFill(['sd_token' => 'stale-token'])->saveQuietly();

    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::sequence()
            ->push(['code' => $code, 'message' => 'Token problem.'], 403)
            ->push(sdImageProxyJpeg(1000, 1500), 200, ['Content-Type' => 'image/jpeg']),
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 0, 'token' => 'fresh-token', 'tokenExpires' => now()->addDay()->timestamp]),
    ]);

    $this->get("/schedules-direct/{$this->epg->uuid}/image/retry.jpg")->assertOk();

    expect($this->epg->fresh()->sd_token)->toBe('fresh-token');
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/image/retry.jpg') && $request->header('token') === ['fresh-token']);
})->with(['token expired' => [4006], 'no token' => [1004]]);

it('stops all image downloads for a day when the address is blocked (5004)', function (int $status, array $body) {
    Http::fake([
        'json.schedulesdirect.org/20141201/image/*' => Http::response($body, $status),
    ]);

    $other = Epg::factory()->create([
        'user_id' => User::factory()->create()->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'someone-else@example.com',
        'sd_password' => 'another-password',
        'sd_lineup_ids' => ['USA-NY12345-X'],
        'sd_token' => 'other-token',
        'sd_token_expires_at' => now()->addHours(6),
    ]);

    $this->get("/schedules-direct/{$this->epg->uuid}/image/blocked.jpg")->assertStatus(429);

    // The block is per address, so other EPGs stop too.
    $this->get("/schedules-direct/{$other->uuid}/image/also-blocked.jpg")->assertStatus(429);
    Http::assertSentCount(1);
})->with([
    'coded body' => [403, ['code' => 5004, 'message' => 'Too many not-found requests.']],
    'plain load balancer 403' => [403, []],
]);

describe('image versions', function () {
    beforeEach(function () {
        $this->fetches = 0;
        $this->imageWidth = 300;
        $this->imageFails = false;
        Http::fake([
            'json.schedulesdirect.org/20141201/image/*' => function () {
                $this->fetches++;

                return $this->imageFails
                    ? Http::response('Bad Gateway', 502)
                    : Http::response(sdImageProxyJpeg($this->imageWidth, 200), 200, ['Content-Type' => 'image/jpeg']);
            },
        ]);
        $this->imageUrl = fn (int $version) => "/schedules-direct/{$this->epg->uuid}/image/versioned.jpg?v={$version}";
        $this->servedWidth = fn ($response) => getimagesizefromstring($response->streamedContent())[0];
    });

    it('serves the cached copy while the image lastUpdate is unchanged', function () {
        $this->get(($this->imageUrl)(1000))->assertOk();
        $this->get(($this->imageUrl)(1000))->assertOk();

        expect($this->fetches)->toBe(1);
    });

    it('downloads the image again when its lastUpdate is newer than the cached copy', function () {
        $this->get(($this->imageUrl)(1000))->assertOk();

        $this->imageWidth = 320;
        $response = $this->get(($this->imageUrl)(2000));

        expect($this->fetches)->toBe(2)
            ->and(($this->servedWidth)($response))->toBe(320);
        // And the refreshed copy is now current
        $this->get(($this->imageUrl)(2000))->assertOk();
        expect($this->fetches)->toBe(2);
    });

    it('keeps serving the out-of-date copy when the refresh fails', function () {
        $this->get(($this->imageUrl)(1000))->assertOk();

        $this->imageFails = true;
        $response = $this->get(($this->imageUrl)(2000));

        $response->assertOk();
        expect(($this->servedWidth)($response))->toBe(300);
    });

    it('treats a copy cached before versions existed as current until the image changes after it was cached', function () {
        $this->get("/schedules-direct/{$this->epg->uuid}/image/versioned.jpg")->assertOk();

        $this->get(($this->imageUrl)(now()->subDay()->timestamp))->assertOk();
        expect($this->fetches)->toBe(1);

        $this->get(($this->imageUrl)(now()->addMinute()->timestamp))->assertOk();
        expect($this->fetches)->toBe(2);
    });
});
