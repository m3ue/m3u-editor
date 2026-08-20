<?php

use App\Models\Episode;
use App\Models\Series;
use App\Services\NfoService;

describe('NfoService getScalarValue', function () {
    it('returns scalar values unchanged', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        expect($method->invokeArgs($service, ['test']))->toBe('test');
        expect($method->invokeArgs($service, [123]))->toBe(123);
        expect($method->invokeArgs($service, [12.34]))->toBe(12.34);
        expect($method->invokeArgs($service, [true]))->toBe(true);
    });

    it('extracts first element from arrays', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        // Array with multiple elements - should return first
        $array = ['first', 'second', 'third'];
        expect($method->invokeArgs($service, [$array]))->toBe('first');

        // Array with single element
        $singleArray = ['only'];
        expect($method->invokeArgs($service, [$singleArray]))->toBe('only');

        // Array with numeric keys
        $numericArray = [1 => 'one', 2 => 'two', 3 => 'three'];
        expect($method->invokeArgs($service, [$numericArray]))->toBe('one');
    });

    it('returns null for empty arrays', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        expect($method->invokeArgs($service, [[]]))->toBeNull();
    });

    it('returns null for objects', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        $object = (object) ['key' => 'value'];
        expect($method->invokeArgs($service, [$object]))->toBeNull();
    });

    it('handles TMDB image path arrays correctly', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        // Simulate TMDB returning array of paths
        $imagePaths = ['/path/to/image1.jpg', '/path/to/image2.jpg'];
        $result = $method->invokeArgs($service, [$imagePaths]);

        expect($result)->toBe('/path/to/image1.jpg');
    });
});

describe('NfoService series provider fields', function () {
    it('writes dedicated provider IDs and artwork before legacy metadata', function () {
        $path = sys_get_temp_dir().'/nfo-service-'.bin2hex(random_bytes(6));
        $series = (new Series)->forceFill([
            'name' => 'Current Fields',
            'release_date' => '2026-08-20',
            'tmdb_id' => 220855,
            'tvdb_id' => 428608,
            'imdb_id' => 'tt26923358',
            'cover' => 'https://images.example/current-poster.jpg',
            'backdrop_path' => ['https://images.example/current-backdrop.jpg'],
            'metadata' => [
                'tmdb_id' => 1,
                'tvdb_id' => 2,
                'imdb_id' => 'tt0000002',
                'poster_path' => 'https://images.example/legacy-poster.jpg',
                'backdrop_path' => 'https://images.example/legacy-backdrop.jpg',
            ],
        ]);

        expect((new NfoService)->generateSeriesNfo($series, $path))->toBeTrue();

        $xml = file_get_contents($path.'/tvshow.nfo');
        expect($xml)
            ->toContain('<tmdbid>220855</tmdbid>')
            ->toContain('<tvdbid>428608</tvdbid>')
            ->toContain('<imdbid>tt26923358</imdbid>')
            ->toContain('https://images.example/current-poster.jpg')
            ->toContain('https://images.example/current-backdrop.jpg')
            ->not->toContain('https://images.example/legacy-poster.jpg')
            ->not->toContain('https://images.example/legacy-backdrop.jpg');

        unlink($path.'/tvshow.nfo');
        rmdir($path);
    });

    it('keeps legacy metadata as a fallback', function () {
        $path = sys_get_temp_dir().'/nfo-service-'.bin2hex(random_bytes(6));
        $series = (new Series)->forceFill([
            'name' => 'Legacy Fields',
            'tmdb_id' => 0,
            'tvdb_id' => '',
            'imdb_id' => '',
            'cover' => '',
            'backdrop_path' => [''],
            'metadata' => [
                'tmdb_id' => [225467],
                'tvdb_id' => 436946,
                'imdb_id' => 'tt27787158',
                'poster_path' => 'https://images.example/legacy-poster.jpg',
                'backdrop_path' => 'https://images.example/legacy-backdrop.jpg',
            ],
        ]);

        expect((new NfoService)->generateSeriesNfo($series, $path))->toBeTrue();

        $xml = file_get_contents($path.'/tvshow.nfo');
        expect($xml)
            ->toContain('<tmdbid>225467</tmdbid>')
            ->toContain('<tvdbid>436946</tvdbid>')
            ->toContain('<imdbid>tt27787158</imdbid>')
            ->toContain('https://images.example/legacy-poster.jpg')
            ->toContain('https://images.example/legacy-backdrop.jpg');

        unlink($path.'/tvshow.nfo');
        rmdir($path);
    });

    it('uses dedicated series provider IDs in episode NFOs', function () {
        $path = sys_get_temp_dir().'/nfo-service-'.bin2hex(random_bytes(6));
        mkdir($path);
        $strmPath = $path.'/episode.strm';
        touch($strmPath);

        $series = (new Series)->forceFill([
            'name' => 'Current Fields',
            'tmdb_id' => 220855,
            'tvdb_id' => 428608,
            'imdb_id' => 'tt26923358',
            'metadata' => [],
        ]);
        $episode = (new Episode)->forceFill([
            'title' => 'Pilot',
            'season' => 1,
            'episode_num' => 1,
            'info' => [],
        ]);

        expect((new NfoService)->generateEpisodeNfo($episode, $series, $strmPath))->toBeTrue();

        $xml = file_get_contents($path.'/episode.nfo');
        expect($xml)
            ->toContain('<uniqueid type="tmdb" default="true">220855</uniqueid>')
            ->toContain('<uniqueid type="tvdb">428608</uniqueid>')
            ->toContain('<uniqueid type="imdb">tt26923358</uniqueid>');

        unlink($path.'/episode.nfo');
        unlink($strmPath);
        rmdir($path);
    });

    it('falls back to usable legacy provider IDs in episode NFOs', function () {
        $path = sys_get_temp_dir().'/nfo-service-'.bin2hex(random_bytes(6));
        mkdir($path);
        $strmPath = $path.'/episode.strm';
        touch($strmPath);

        $series = (new Series)->forceFill([
            'name' => 'Legacy Fields',
            'tmdb_id' => 0,
            'tvdb_id' => '',
            'imdb_id' => '',
            'metadata' => [
                'tmdb_id' => [225467],
                'tvdb_id' => 436946,
                'imdb_id' => 'tt27787158',
            ],
        ]);
        $episode = (new Episode)->forceFill([
            'title' => 'Pilot',
            'season' => 1,
            'episode_num' => 1,
            'info' => [],
        ]);

        expect((new NfoService)->generateEpisodeNfo($episode, $series, $strmPath))->toBeTrue();

        $xml = file_get_contents($path.'/episode.nfo');
        expect($xml)
            ->toContain('<uniqueid type="tmdb" default="true">225467</uniqueid>')
            ->toContain('<uniqueid type="tvdb">436946</uniqueid>')
            ->toContain('<uniqueid type="imdb">tt27787158</uniqueid>');

        unlink($path.'/episode.nfo');
        unlink($strmPath);
        rmdir($path);
    });
});

describe('NfoService applyNameFilter', function () {
    it('returns name unchanged when filtering disabled', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K]';
        $result = $method->invokeArgs($service, [$name, false, ['[4K]']]);

        expect($result)->toBe($name);
    });

    it('returns name unchanged when patterns array is empty', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K]';
        $result = $method->invokeArgs($service, [$name, true, []]);

        expect($result)->toBe($name);
    });

    it('removes single pattern from name', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K]';
        $result = $method->invokeArgs($service, [$name, true, ['[4K]']]);

        expect($result)->toBe('Test Movie');
    });

    it('removes multiple patterns from name', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K] (2024) [HDR]';
        $patterns = ['[4K]', '(2024)', '[HDR]'];
        $result = $method->invokeArgs($service, [$name, true, $patterns]);

        expect($result)->toBe('Test Movie');
    });

    it('trims whitespace after pattern removal', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = '  Test Movie [TAG]  ';
        $result = $method->invokeArgs($service, [$name, true, ['[TAG]']]);

        expect($result)->toBe('Test Movie');
    });

    it('handles non-string patterns gracefully', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K]';
        // Mix of valid string patterns and invalid non-string patterns
        $patterns = ['[4K]', null, '', 123, ['nested']];

        // Should only process the valid string pattern
        $result = $method->invokeArgs($service, [$name, true, $patterns]);

        expect($result)->toBe('Test Movie');
    });

    it('ignores empty string patterns', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie';
        $patterns = ['', '  ', 'NonExistent'];
        $result = $method->invokeArgs($service, [$name, true, $patterns]);

        // Empty patterns should be skipped, 'NonExistent' won't match
        expect($result)->toBe('Test Movie');
    });

    it('handles multiple occurrences of same pattern', function () {
        $service = new NfoService;
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test [4K] Movie [4K] Name [4K]';
        $result = $method->invokeArgs($service, [$name, true, ['[4K]']]);

        expect($result)->toBe('Test  Movie  Name');
    });
});
