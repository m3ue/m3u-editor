<?php

namespace App\Services;

use App\Support\PrivateNetworkGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Library artwork for DVR recordings that no metadata provider matched.
 *
 * Instead of using the raw channel logo as the poster (cropped and stretched by
 * every client), the logo becomes the title logo (clearlogo) and the poster and
 * backdrop are rendered from it: a soft "ambient" wash of the logo's own colors,
 * with the logo itself centered on the poster. The art is text-free, so it works
 * for any title in any script; clients already print the title beside it.
 *
 * Art depends only on the logo, so it is rendered once per logo, cached with the
 * other cached logos, and shared by every recording from that channel.
 */
class DvrArtworkService
{
    public const POSTER = 'poster';

    public const BACKDROP = 'backdrop';

    /**
     * Bumped when the rendering changes, so cached art is re-rendered.
     */
    private const RENDER_VERSION = 1;

    /**
     * How long art rendered without the logo is served before the logo is
     * fetched again.
     */
    private const LOGO_RETRY_HOURS = 1;

    private const BASE_COLOR = '#14151b';

    /**
     * @var array<string, array{int, int}>
     */
    private const SIZES = [
        self::POSTER => [600, 900],
        self::BACKDROP => [1280, 720],
    ];

    /**
     * @var array<string, bool>
     */
    private array $usableLogos = [];

    /**
     * Signed URL of the [$kind] art for [$logoUrl]. The signature is relative
     * (like the signed DVR stream URL), so it survives a host or port change,
     * and stops the route from being used to render arbitrary images.
     */
    public function url(string $kind, string $logoUrl): string
    {
        return url(URL::signedRoute('dvr.artwork', [
            'kind' => $kind,
            'logo' => rtrim(strtr(base64_encode($logoUrl), '+/', '-_'), '='),
        ], absolute: false));
    }

    public static function decodeLogoParameter(string $encoded): ?string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return $decoded !== false && filter_var($decoded, FILTER_VALIDATE_URL) ? $decoded : null;
    }

    /**
     * The cached file (on the local disk) holding the [$kind] art for
     * [$logoUrl], rendering it first when needed.
     */
    public function cachedFile(string $kind, string $logoUrl): string
    {
        $sourceKey = 'dvr-art://v'.self::RENDER_VERSION."/{$kind}/{$logoUrl}";
        $withoutLogoKey = 'dvr-art://v'.self::RENDER_VERSION."/{$kind}/no-logo/{$logoUrl}";

        // Art rendered without the logo (it could not be fetched) is only
        // reused for a while, so a logo host that was briefly down gets
        // another chance, without refetching on every request meanwhile.
        $cached = LogoCacheService::findImage($sourceKey)
            ?? LogoCacheService::findImage($withoutLogoKey, maxAgeHours: self::LOGO_RETRY_HOURS);
        if ($cached) {
            return $cached;
        }

        $logoBytes = $this->logoBytes($logoUrl);

        return LogoCacheService::storeImage($logoBytes !== null ? $sourceKey : $withoutLogoKey, $this->render($kind, $logoBytes), 'image/jpeg');
    }

    /**
     * Whether the logo at [$logoUrl] can be fetched (or is already cached),
     * so it is worth offering as a title logo. Fetching it also warms the
     * cache the generated art is rendered from.
     */
    public function hasUsableLogo(string $logoUrl): bool
    {
        // Remembered for this instance (one integration job), so picking a
        // logo and then deciding on the title logo fetch it only once.
        return $this->usableLogos[$logoUrl] ??= $this->logoBytes($logoUrl) !== null;
    }

    /**
     * Renders the [$kind] art as JPEG bytes. Without a usable logo the result
     * is the plain base gradient, so a broken logo never breaks the card.
     */
    public function render(string $kind, ?string $logoBytes): string
    {
        [$width, $height] = self::SIZES[$kind] ?? throw new InvalidArgumentException("Unknown DVR artwork kind [{$kind}]");

        $manager = ImageManager::usingDriver(GdDriver::class);

        $logo = null;
        if ($logoBytes !== null && self::rasterMimeType($logoBytes) !== null) {
            try {
                $logo = $manager->decodeBinary($logoBytes);
            } catch (Throwable) {
                $logo = null;
            }
        }

        $native = $logo
            ? $this->ambientWash($manager, $logo, $width, $height)
            : $manager->createImage($width, $height)->fill(self::BASE_COLOR)->core()->native();

        // Darken toward the bottom (and the left on backdrops) where clients
        // draw the title, rating chips and clearlogo over the art.
        $this->shade($native, $width, $height, vertical: true, from: 0.35, strength: 0.85);
        if ($kind === self::BACKDROP) {
            $this->shade($native, $width, $height, vertical: false, from: 0.45, strength: 0.6);
        }

        $canvas = $manager->decode($native);

        if ($logo && $kind === self::POSTER) {
            $fitted = (clone $logo)->scaleDown(width: (int) ($width * 0.7), height: (int) ($height * 0.32));
            $canvas->insert($fitted, 0, (int) (-$height * 0.06), 'center');
        }

        return $canvas->encode(new JpegEncoder(quality: 85))->toString();
    }

    /**
     * The logo shrunk to a few pixels, blurred, then scaled back up: a smooth
     * wash of its colors, dimmed toward the base color. GD's own resampling
     * leaves a visible grid when upscaling this far, so the final scale uses
     * bilinear interpolation.
     *
     * @return \GdImage
     */
    private function ambientWash(ImageManager $manager, ImageInterface $logo, int $width, int $height): mixed
    {
        [$tinyWidth, $tinyHeight] = $width >= $height ? [6, 4] : [4, 6];
        $tiny = (clone $logo)->cover($tinyWidth, $tinyHeight);

        $sample = $manager->createImage($tinyWidth, $tinyHeight)->fill(self::BASE_COLOR);
        $sample->insert($tiny);
        $blurred = $sample->resize($tinyWidth * 8, $tinyHeight * 8)->blur(40)->core()->native();

        $wash = imagescale($blurred, $width, $height, IMG_BILINEAR_FIXED);
        imagealphablending($wash, true);
        [$red, $green, $blue] = sscanf(self::BASE_COLOR, '#%02x%02x%02x');
        imagefilledrectangle($wash, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($wash, $red, $green, $blue, 51));

        return $wash;
    }

    /**
     * Lays a dark gradient over [$image], one line at a time: transparent up
     * to [$from] (a fraction of the height, or of the width from the right
     * when not [$vertical]), reaching [$strength] opacity at the far edge.
     *
     * @param  \GdImage  $image
     */
    private function shade(mixed $image, int $width, int $height, bool $vertical, float $from, float $strength): void
    {
        imagealphablending($image, true);
        $length = $vertical ? $height : $width;
        $start = (int) ($length * $from);

        for ($offset = $start; $offset < $length; $offset++) {
            // Eased so the darkening starts gently.
            $opacity = $strength * (($offset - $start + 1) / ($length - $start)) ** 1.6;
            $color = imagecolorallocatealpha($image, 10, 10, 14, (int) round(127 * (1 - $opacity)));

            $vertical
                ? imageline($image, 0, $offset, $width - 1, $offset, $color)
                : imageline($image, $length - 1 - $offset, 0, $length - 1 - $offset, $height - 1, $color);
        }
    }

    /**
     * The logo's bytes: from the logo cache when the logo proxy already has a
     * copy, otherwise fetched (behind the private-network guard, as the logo
     * proxy does) and added to that cache. Null when it can't be had.
     */
    private function logoBytes(string $logoUrl): ?string
    {
        $sourceKey = LogoCacheService::sourceKeyForUrl($logoUrl);
        $cached = LogoCacheService::findAnyCopy($sourceKey);
        if ($cached) {
            return Storage::disk('local')->get($cached);
        }

        try {
            $response = PrivateNetworkGuard::get($logoUrl, fn (): PendingRequest => Http::timeout(10)
                ->withOptions(LogoCacheService::downloadLimitOptions()));
        } catch (InvalidArgumentException) {
            return null;
        } catch (Throwable $e) {
            Log::warning('DVR artwork: could not fetch channel logo', ['url' => $logoUrl, 'error' => $e->getMessage()]);

            return null;
        }

        $bytes = $response->successful() ? $response->body() : '';
        $mimeType = strlen($bytes) <= LogoCacheService::MAX_IMAGE_BYTES ? self::rasterMimeType($bytes) : null;
        if ($mimeType === null) {
            return null;
        }

        // Cached under the type sniffed from the bytes, never the upstream's
        // own Content-Type: the logo proxy serves this copy too.
        LogoCacheService::storeImage($sourceKey, $bytes, $mimeType);

        return $bytes;
    }

    /**
     * The image type of [$bytes] when they are a raster image small enough to
     * decode safely, otherwise null.
     */
    private static function rasterMimeType(string $bytes): ?string
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (! is_array($info) || ! str_starts_with((string) ($info['mime'] ?? ''), 'image/')) {
            return null;
        }

        return LogoCacheService::withinPixelLimit($info) ? $info['mime'] : null;
    }
}
