<?php

use App\Services\NfoService;

describe('NfoService getScalarValue', function () {
    it('returns scalar values unchanged', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        expect($method->invokeArgs($service, ['test']))->toBe('test');
        expect($method->invokeArgs($service, [123]))->toBe(123);
        expect($method->invokeArgs($service, [12.34]))->toBe(12.34);
        expect($method->invokeArgs($service, [true]))->toBe(true);
    });

    it('extracts first element from arrays', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
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
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        expect($method->invokeArgs($service, [[]]))->toBeNull();
    });

    it('returns null for objects', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        $object = (object) ['key' => 'value'];
        expect($method->invokeArgs($service, [$object]))->toBeNull();
    });

    it('handles TMDB image path arrays correctly', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('getScalarValue');
        $method->setAccessible(true);

        // Simulate TMDB returning array of paths
        $imagePaths = ['/path/to/image1.jpg', '/path/to/image2.jpg'];
        $result = $method->invokeArgs($service, [$imagePaths]);

        expect($result)->toBe('/path/to/image1.jpg');
    });
});

describe('NfoService applyNameFilter', function () {
    it('returns name unchanged when filtering disabled', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K]';
        $result = $method->invokeArgs($service, [$name, false, ['[4K]']]);

        expect($result)->toBe($name);
    });

    it('returns name unchanged when patterns array is empty', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K]';
        $result = $method->invokeArgs($service, [$name, true, []]);

        expect($result)->toBe($name);
    });

    it('removes single pattern from name', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K]';
        $result = $method->invokeArgs($service, [$name, true, ['[4K]']]);

        expect($result)->toBe('Test Movie');
    });

    it('removes multiple patterns from name', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test Movie [4K] (2024) [HDR]';
        $patterns = ['[4K]', '(2024)', '[HDR]'];
        $result = $method->invokeArgs($service, [$name, true, $patterns]);

        expect($result)->toBe('Test Movie');
    });

    it('trims whitespace after pattern removal', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = '  Test Movie [TAG]  ';
        $result = $method->invokeArgs($service, [$name, true, ['[TAG]']]);

        expect($result)->toBe('Test Movie');
    });

    it('handles non-string patterns gracefully', function () {
        $service = new NfoService;
        $reflection = new \ReflectionClass($service);
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
        $reflection = new \ReflectionClass($service);
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
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('applyNameFilter');
        $method->setAccessible(true);

        $name = 'Test [4K] Movie [4K] Name [4K]';
        $result = $method->invokeArgs($service, [$name, true, ['[4K]']]);

        expect($result)->toBe('Test  Movie  Name');
    });
});
