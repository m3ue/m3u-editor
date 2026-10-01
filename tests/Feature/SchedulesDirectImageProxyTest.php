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
