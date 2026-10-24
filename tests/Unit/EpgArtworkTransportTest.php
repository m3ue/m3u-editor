<?php

namespace Tests\Unit;

use App\Http\Controllers\EpgGenerateController;
use App\Http\Controllers\XtreamApiController;
use App\Models\Epg;
use App\Services\EpgCacheService;
use App\Support\EpgProgrammeArtwork;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Serialization tests deliberately do not boot Laravel or refresh a database. */
class EpgArtworkTransportTest extends TestCase
{
    public function test_preserved_primary_is_exported_only_when_bound_to_the_scalar(): void
    {
        $url = 'https://example.com/still.jpg';
        $programme = ['icon' => $url, 'preserved_primary_url' => $url, 'images' => [
            ['url' => $url, 'type' => 'still', 'width' => 1920, 'height' => 1080],
        ]];
        $controller = new XtreamApiController;
        $export = new ReflectionMethod($controller, 'epgProgrammeArtwork');
        $payload = $export->invoke($controller, $programme, (object) ['enable_logo_proxy' => false]);
        self::assertSame($url, $payload['preserved_primary_url'] ?? null);
        self::assertArrayNotHasKey('poster_url', $payload);
        self::assertSame($url, $payload['still_url']);
        self::assertStringContainsString('<?m3ue-preserved-primary?>'.PHP_EOL.'    <icon src="'.$url.'"/>', $this->exportArtwork($programme));
        self::assertSame(1, substr_count($this->exportArtwork($programme), '<?m3ue-preserved-primary?>'));
        foreach (['', 'https://example.com/other.jpg', 'file:///private/image.jpg'] as $binding) {
            $programme['preserved_primary_url'] = $binding;
            self::assertArrayNotHasKey('preserved_primary_url', $export->invoke($controller, $programme, (object) ['enable_logo_proxy' => false]));
            self::assertStringNotContainsString('<?m3ue-preserved-primary?>', $this->exportArtwork($programme));
        }
    }

    public function test_preserved_primary_does_not_export_conflicting_same_url_geometry(): void
    {
        $url = 'https://example.com/still.jpg';
        $programme = ['icon' => $url, 'preserved_primary_url' => $url, 'images' => [
            ['url' => $url, 'type' => 'still', 'width' => 1920, 'height' => 1080],
            ['url' => $url, 'type' => 'still', 'width' => 640, 'height' => 360],
        ]];
        $controller = new XtreamApiController;
        $payload = (new ReflectionMethod($controller, 'epgProgrammeArtwork'))->invoke($controller, $programme, (object) ['enable_logo_proxy' => false]);
        self::assertSame($url, $payload['preserved_primary_url']);
        self::assertSame(0, $payload['still_width']);
        self::assertSame(0, $payload['still_height']);
    }

    public function test_first_icon_metadata_survives_import_and_xmltv_roundtrip(): void
    {
        $programme = $this->importArtwork('<programme><icon src="https://example.com/poster.jpg" type="poster" orient="P" width="500" height="750" size="3"/></programme>');

        self::assertSame('https://example.com/poster.jpg', $programme['icon']);
        self::assertSame([
            'url' => 'https://example.com/poster.jpg', 'type' => 'poster',
            'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 3,
        ], $programme['images'][0] ?? null);

        $xml = $this->exportArtwork($programme);
        self::assertStringContainsString('width="500" height="750"', $xml);
        self::assertStringContainsString('<image type="poster" size="3" orient="P">https://example.com/poster.jpg</image>', $xml);
        $roundtrip = $this->importArtwork('<programme>'.$xml.'</programme>');
        $poster = EpgProgrammeArtwork::poster($roundtrip['images']);
        self::assertSame($programme['icon'], $poster['url']);
        self::assertSame(500, $poster['width']);
        self::assertSame(750, $poster['height']);
    }

    public function test_xmltv_keeps_larger_stills_separate_from_the_typed_backdrop(): void
    {
        $backdrop = ['url' => 'https://example.com/backdrop.jpg', 'type' => 'backdrop', 'width' => 1280, 'height' => 720];
        $still = ['url' => 'https://example.com/still.jpg', 'type' => 'still', 'width' => 1920, 'height' => 1080];
        $generic = ['url' => 'https://example.com/generic.jpg', 'type' => '', 'width' => 3840, 'height' => 2160];
        foreach ([[$backdrop, $still, $generic], [$backdrop, $generic, $still], [$generic, $still, $backdrop], [$generic, $backdrop, $still], [$still, $backdrop, $generic], [$still, $generic, $backdrop]] as $images) {
            $xml = $this->exportArtwork(['icon' => '', 'images' => $images]);
            $document = new \DOMDocument;
            self::assertTrue($document->loadXML('<programme>'.$xml.'</programme>'));
            $xpath = new \DOMXPath($document);
            self::assertSame('https://example.com/backdrop.jpg', $xpath->evaluate('string(/programme/image[@type="backdrop"])'));
            self::assertSame('https://example.com/still.jpg', $xpath->evaluate('string(/programme/image[@type="still"])'));
        }
    }

    public function test_explicit_still_or_screenshot_is_never_exported_as_a_backdrop(): void
    {
        foreach (['still', 'screenshot'] as $type) {
            $image = ['url' => 'https://example.com/frame.jpg', 'type' => $type, 'width' => 1920, 'height' => 1080];
            $xml = $this->exportArtwork(['icon' => '', 'images' => [$image]]);
            self::assertStringNotContainsString('type="backdrop"', $xml);
        }
    }

    public function test_xtream_json_transports_the_selected_posters_real_dimensions(): void
    {
        $images = [
            ['url' => 'https://example.com/small.jpg', 'type' => 'poster', 'width' => 200, 'height' => 300],
            ['url' => 'https://example.com/selected.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750],
        ];
        $controller = new XtreamApiController;
        $payload = (new ReflectionMethod($controller, 'epgProgrammeArtwork'))->invoke($controller, ['images' => $images], (object) ['enable_logo_proxy' => false]);
        $json = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://example.com/selected.jpg', $json['poster_url']);
        self::assertSame(500, $json['poster_width'] ?? null);
        self::assertSame(750, $json['poster_height'] ?? null);
    }

    public function test_xtream_json_transports_still_separately_from_poster_and_backdrop(): void
    {
        $images = [
            ['url' => 'https://example.com/poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750],
            ['url' => 'https://example.com/backdrop.jpg', 'type' => 'backdrop', 'width' => 1280, 'height' => 720],
            ['url' => 'https://example.com/still-small.jpg', 'type' => 'still', 'width' => 640, 'height' => 360],
            ['url' => 'https://example.com/still.jpg', 'type' => 'still', 'width' => 1920, 'height' => 1080],
        ];
        $controller = new XtreamApiController;
        $payload = (new ReflectionMethod($controller, 'epgProgrammeArtwork'))->invoke($controller, ['images' => $images], (object) ['enable_logo_proxy' => false]);
        $json = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://example.com/poster.jpg', $json['poster_url']);
        self::assertSame('https://example.com/backdrop.jpg', $json['backdrop_url']);
        self::assertSame('https://example.com/still.jpg', $json['still_url'] ?? null);
        self::assertSame(1920, $json['still_width'] ?? null);
        self::assertSame(1080, $json['still_height'] ?? null);
    }

    public function test_import_keeps_legacy_icons_without_inventing_metadata(): void
    {
        $legacy = $this->importArtwork('<programme><icon src="https://example.com/legacy.jpg"/><rating><icon src="https://example.com/rating.jpg" width="500" height="750"/></rating></programme>');
        self::assertSame(['icon' => 'https://example.com/legacy.jpg', 'images' => []], $legacy);

        $typed = $this->importArtwork('<programme><icon src="https://example.com/typed.jpg" type=" POSTER " orient=" p "/></programme>');
        self::assertSame('poster', $typed['images'][0]['type']);
        self::assertSame('P', $typed['images'][0]['orient']);
        self::assertSame(0, $typed['images'][0]['width']);
        self::assertSame(0, $typed['images'][0]['height']);

        $standard = $this->importArtwork('<programme><icon src="https://example.com/first.jpg" width="500" height="750"/><image type="poster" orient="P">https://example.com/first.jpg</image></programme>');
        $poster = EpgProgrammeArtwork::poster($standard['images']);
        self::assertSame('https://example.com/first.jpg', $poster['url']);
        self::assertSame(500, $poster['width']);
        self::assertSame(750, $poster['height']);
    }

    public function test_legacy_landscape_selection_and_geometry_controls_remain_intact(): void
    {
        $wide = ['url' => 'https://example.com/wide.jpg', 'type' => 'poster', 'width' => 1280, 'height' => 720];
        $square = ['url' => 'https://example.com/square.jpg', 'type' => '', 'width' => 2000, 'height' => 2000];
        $portrait = ['url' => 'https://example.com/portrait.jpg', 'type' => 'backdrop', 'width' => 500, 'height' => 750, 'orient' => 'L'];
        self::assertSame($wide, EpgProgrammeArtwork::backdrop([$square, $portrait, $wide]));
        self::assertNull(EpgProgrammeArtwork::poster([$wide]));
        self::assertNull(EpgProgrammeArtwork::backdrop([$portrait, $square]));
        self::assertNull(EpgProgrammeArtwork::backdrop([
            ['url' => 'javascript:alert(1)', 'type' => 'backdrop', 'width' => 1920, 'height' => 1080],
            ['url' => 'not a url', 'type' => 'backdrop'],
            ['url' => 'https://example.com/actor.jpg', 'type' => 'person', 'width' => 1920, 'height' => 1080],
        ]));
        $dimensionless = ['url' => 'https://example.com/declared.jpg', 'type' => 'backdrop', 'orient' => 'L'];
        self::assertSame($dimensionless, EpgProgrammeArtwork::backdrop([$wide, $dimensionless]));
    }

    public function test_exports_preserve_the_selected_valid_poster_instead_of_reranking_by_area(): void
    {
        foreach ([200, 2000] as $alternativeWidth) {
            $selected = ['url' => 'https://example.com/selected.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750];
            $alternative = ['url' => 'https://example.com/alternative.jpg', 'width' => $alternativeWidth, 'height' => $alternativeWidth * 3 / 2];
            foreach ([[$selected, $alternative], [$alternative, $selected]] as $images) {
                $programme = ['icon' => $selected['url'], 'images' => $images];
                $controller = new XtreamApiController;
                $json = (new ReflectionMethod($controller, 'epgProgrammeArtwork'))->invoke($controller, $programme, (object) ['enable_logo_proxy' => false]);
                self::assertSame($selected['url'], $json['poster_url']);
                self::assertSame(500, $json['poster_width']);
                self::assertSame(750, $json['poster_height']);
                $xml = $this->exportArtwork($programme);
                self::assertStringContainsString('<image type="poster" size="3" orient="P">'.$selected['url'].'</image>', $xml);
                self::assertStringNotContainsString('orient="P">'.$alternative['url'].'</image>', $xml);
            }
        }
    }

    public function test_invalid_scalar_selection_does_not_override_a_valid_poster(): void
    {
        $fallback = ['url' => 'https://example.com/fallback.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750];
        foreach ([
            ['url' => 'not a url', 'type' => 'poster', 'width' => 2000, 'height' => 3000],
            ['url' => 'file:///private/poster.jpg', 'type' => 'poster', 'width' => 2000, 'height' => 3000],
            ['url' => 'ftp://example.com/poster.jpg', 'type' => 'poster', 'width' => 2000, 'height' => 3000],
            ['url' => 'https://example.com/still.jpg', 'type' => 'still', 'width' => 2000, 'height' => 3000],
            ['url' => 'https://example.com/backdrop.jpg', 'type' => 'backdrop', 'width' => 2000, 'height' => 3000],
            ['url' => 'https://example.com/wide.jpg', 'type' => 'poster', 'width' => 1920, 'height' => 1080, 'orient' => 'P'],
            ['url' => 'https://example.com/square.jpg', 'type' => 'poster', 'width' => 2000, 'height' => 2000],
        ] as $invalid) {
            $programme = ['icon' => $invalid['url'], 'images' => [$invalid, $fallback]];
            $controller = new XtreamApiController;
            $json = (new ReflectionMethod($controller, 'epgProgrammeArtwork'))->invoke($controller, $programme, (object) ['enable_logo_proxy' => false]);
            self::assertSame($fallback['url'], $json['poster_url']);
            self::assertStringContainsString('<image type="poster" size="3" orient="P">'.$fallback['url'].'</image>', $this->exportArtwork($programme));
        }
    }

    public function test_json_does_not_invent_dimensions_for_declared_roles(): void
    {
        $controller = new XtreamApiController;
        $method = new ReflectionMethod($controller, 'epgProgrammeArtwork');
        $playlist = (object) ['enable_logo_proxy' => false];
        $json = $method->invoke($controller, ['images' => [
            ['url' => 'https://example.com/poster.jpg', 'type' => 'poster', 'orient' => 'P'],
            ['url' => 'https://example.com/still.jpg', 'type' => 'still', 'orient' => 'L'],
        ]], $playlist);
        self::assertSame(0, $json['poster_width']);
        self::assertSame(0, $json['poster_height']);
        self::assertSame(0, $json['still_width']);
        self::assertSame(0, $json['still_height']);
        self::assertArrayNotHasKey('backdrop_url', $json);
        self::assertSame([], $method->invoke($controller, ['images' => null], $playlist));
        self::assertSame([], $method->invoke($controller, ['images' => [
            ['url' => 'javascript:alert(1)', 'type' => 'still', 'width' => 1920, 'height' => 1080],
            ['url' => 'https://example.com/portrait-still.jpg', 'type' => 'still', 'width' => 500, 'height' => 750, 'orient' => 'L'],
        ]], $playlist));
    }

    /** @return array{icon: string, images: list<array<string, mixed>>} */
    private function importArtwork(string $xml): array
    {
        $path = tempnam(getenv('TMPDIR') ?: sys_get_temp_dir(), 'epg-artwork-');
        $xml = str_replace('<programme>', '<programme channel="synthetic" start="20261023100000 +0000" stop="20261023110000 +0000"><title>Synthetic Artwork</title>', $xml);
        file_put_contents($path, gzencode('<tv>'.$xml.'</tv>'));
        try {
            $programmes = iterator_to_array((new ReflectionMethod(EpgCacheService::class, 'parseProgrammesStream'))->invoke(new EpgCacheService, $path), false);
            self::assertCount(1, $programmes);

            return ['icon' => $programmes[0]['icon'], 'images' => $programmes[0]['images']];
        } finally {
            unlink($path);
        }
    }

    /** @param array<string, mixed> $programme */
    private function exportArtwork(array $programme): string
    {
        $controller = new EpgGenerateController;
        $result = (new ReflectionMethod($controller, 'programmeArtworkXml'))->invoke($controller, $programme, new Epg, false);

        return $result['icons'].$result['images'];
    }
}
