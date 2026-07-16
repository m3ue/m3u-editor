<?php

use App\Models\Epg;
use App\Models\Playlist;
use App\Models\User;
use App\Services\XtreamHealthService;
use App\Services\XtreamService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

// ── Playlist Model: getOrderedXtreamUrls ─────────────────────────────────────

describe('Playlist::getOrderedXtreamUrls', function () {
    it('returns only primary URL when no fallbacks exist', function () {
        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => null,
        ]);

        $urls = $playlist->getOrderedXtreamUrls();

        expect($urls)->toBe(['http://primary.example.com:8080']);
    });

    it('returns primary URL followed by fallbacks in order', function () {
        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
                'http://fallback2.example.com:8080',
            ],
        ]);

        $urls = $playlist->getOrderedXtreamUrls();

        expect($urls)->toBe([
            'http://primary.example.com:8080',
            'http://fallback1.example.com:8080',
            'http://fallback2.example.com:8080',
        ]);
    });

    it('deduplicates URLs and normalizes trailing slashes', function () {
        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080/',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://primary.example.com:8080', // duplicate of primary
                'http://fallback1.example.com:8080/',
            ],
        ]);

        $urls = $playlist->getOrderedXtreamUrls();

        expect($urls)->toBe([
            'http://primary.example.com:8080',
            'http://fallback1.example.com:8080',
        ]);
    });

    it('returns empty array when no xtream config exists', function () {
        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => false,
            'xtream_config' => null,
        ]);

        $urls = $playlist->getOrderedXtreamUrls();

        expect($urls)->toBe([]);
    });
});

// ── Playlist Model: promoteXtreamUrl ─────────────────────────────────────────

describe('Playlist::promoteXtreamUrl', function () {
    it('promotes the specified URL to primary regardless of its position', function () {
        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
                'http://fallback2.example.com:8080',
            ],
        ]);

        $playlist->promoteXtreamUrl('http://fallback2.example.com:8080');
        $playlist->refresh();

        expect($playlist->xtream_config['url'])->toBe('http://fallback2.example.com:8080')
            ->and($playlist->xtream_fallback_urls)->toContain('http://primary.example.com:8080')
            ->and($playlist->xtream_fallback_urls)->toContain('http://fallback1.example.com:8080')
            ->and($playlist->xtream_fallback_urls)->not->toContain('http://fallback2.example.com:8080');
    });

    it('updates all EPGs pointing to any old Xtream URL, not just the old primary', function () {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
                'http://fallback2.example.com:8080',
            ],
        ]);

        $newEpgUrl = 'http://fallback2.example.com:8080/xmltv.php?username=user&password=pass';

        // One EPG at the old primary URL, one at a fallback (simulating a prior partial promotion)
        Epg::factory()->for($user)->create(['url' => 'http://primary.example.com:8080/xmltv.php?username=user&password=pass']);
        Epg::factory()->for($user)->create(['url' => 'http://fallback1.example.com:8080/xmltv.php?username=user&password=pass']);

        $playlist->promoteXtreamUrl('http://fallback2.example.com:8080');

        expect(Epg::where('url', $newEpgUrl)->count())->toBe(2)
            ->and(Epg::whereIn('url', [
                'http://primary.example.com:8080/xmltv.php?username=user&password=pass',
                'http://fallback1.example.com:8080/xmltv.php?username=user&password=pass',
            ])->count())->toBe(0);
    });

    it('does nothing when the URL is not in the list', function () {
        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => ['http://fallback1.example.com:8080'],
        ]);

        $playlist->promoteXtreamUrl('http://unknown.example.com:8080');
        $playlist->refresh();

        expect($playlist->xtream_config['url'])->toBe('http://primary.example.com:8080');
    });
});

// ── XtreamHealthService ──────────────────────────────────────────────────────

describe('XtreamHealthService::checkUrl', function () {
    it('returns reachable when API responds successfully', function () {
        Http::fake([
            '*/player_api.php*' => Http::response(['user_info' => []], 200),
        ]);

        $result = XtreamHealthService::checkUrl(
            'http://test.example.com:8080',
            'user',
            'pass'
        );

        expect($result['reachable'])->toBeTrue()
            ->and($result['error'])->toBeNull()
            ->and($result['response_time_ms'])->toBeInt();
    });

    it('returns unreachable on HTTP error', function () {
        Http::fake([
            '*/player_api.php*' => Http::response('Server Error', 500),
        ]);

        $result = XtreamHealthService::checkUrl(
            'http://test.example.com:8080',
            'user',
            'pass'
        );

        expect($result['reachable'])->toBeFalse()
            ->and($result['error'])->toBe('HTTP 500');
    });

    it('returns unreachable on connection exception', function () {
        Http::fake([
            '*/player_api.php*' => fn () => throw new Exception('Connection refused'),
        ]);

        $result = XtreamHealthService::checkUrl(
            'http://test.example.com:8080',
            'user',
            'pass'
        );

        expect($result['reachable'])->toBeFalse()
            ->and($result['error'])->toContain('Connection refused');
    });

    it('respects the verify parameter', function () {
        Http::fake([
            '*/player_api.php*' => Http::response(['user_info' => []], 200),
        ]);

        XtreamHealthService::checkUrl('http://test.example.com:8080', 'user', 'pass', verify: false);

        Http::assertSent(fn ($request) => true); // just confirming no exception was thrown
    });
});

describe('XtreamHealthService::checkAllUrls', function () {
    it('checks primary and all fallback URLs', function () {
        Http::fake([
            'primary.example.com:8080/*' => Http::response(['user_info' => []], 200),
            'fallback1.example.com:8080/*' => Http::response('Error', 500),
        ]);

        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
            ],
        ]);

        $results = XtreamHealthService::checkAllUrls($playlist);

        expect($results)->toHaveCount(2)
            ->and($results[0]['url'])->toBe('http://primary.example.com:8080')
            ->and($results[0]['reachable'])->toBeTrue()
            ->and($results[0]['is_primary'])->toBeTrue()
            ->and($results[1]['url'])->toBe('http://fallback1.example.com:8080')
            ->and($results[1]['reachable'])->toBeFalse()
            ->and($results[1]['is_primary'])->toBeFalse();
    });
});

describe('XtreamHealthService::findWorkingUrl', function () {
    it('returns primary when it is reachable', function () {
        Http::fake([
            'primary.example.com:8080/*' => Http::response(['user_info' => []], 200),
        ]);

        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
            ],
        ]);

        $result = XtreamHealthService::findWorkingUrl($playlist);

        expect($result)->toBe('http://primary.example.com:8080');
    });

    it('returns first reachable fallback when primary is down', function () {
        Http::fake([
            'primary.example.com:8080/*' => Http::response('Error', 500),
            'fallback1.example.com:8080/*' => Http::response('Error', 500),
            'fallback2.example.com:8080/*' => Http::response(['user_info' => []], 200),
        ]);

        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
                'http://fallback2.example.com:8080',
            ],
        ]);

        $result = XtreamHealthService::findWorkingUrl($playlist);

        expect($result)->toBe('http://fallback2.example.com:8080');
    });

    it('returns null when all URLs are down', function () {
        Http::fake([
            '*' => Http::response('Error', 500),
        ]);

        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
            ],
        ]);

        $result = XtreamHealthService::findWorkingUrl($playlist);

        expect($result)->toBeNull();
    });
});

// ── XtreamService failover ───────────────────────────────────────────────────

describe('XtreamService failover', function () {
    it('falls back to alternative URL when primary fails', function () {
        Http::fake([
            'primary.example.com:8080/*' => Http::response('Error', 500),
            'fallback1.example.com:8080/*' => Http::response(['user_info' => ['status' => 'Active']], 200),
        ]);

        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'testuser',
                'password' => 'testpass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
            ],
        ]);

        $service = XtreamService::make(playlist: $playlist, retryLimit: 1);

        $result = $service->userInfo();
        $playlist->refresh();

        expect($result['user_info']['status'])->toBe('Active')
            ->and($playlist->xtream_config['url'])->toBe('http://fallback1.example.com:8080');
    });

    it('promotes the working fallback when multiple fallbacks exist and the first also fails', function () {
        Http::fake([
            'primary.example.com:8080/*' => Http::response('Error', 500),
            'fallback1.example.com:8080/*' => Http::response('Error', 500),
            'fallback2.example.com:8080/*' => Http::response(['user_info' => ['status' => 'Active']], 200),
        ]);

        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'testuser',
                'password' => 'testpass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
                'http://fallback2.example.com:8080',
            ],
        ]);

        $service = XtreamService::make(playlist: $playlist, retryLimit: 1);

        $result = $service->userInfo();
        $playlist->refresh();

        expect($result['user_info']['status'])->toBe('Active')
            ->and($playlist->xtream_config['url'])->toBe('http://fallback2.example.com:8080')
            ->and($playlist->xtream_fallback_urls)->toContain('http://primary.example.com:8080')
            ->and($playlist->xtream_fallback_urls)->toContain('http://fallback1.example.com:8080')
            ->and($playlist->xtream_fallback_urls)->not->toContain('http://fallback2.example.com:8080');
    });

    it('throws when all URLs fail', function () {
        Http::fake([
            '*' => Http::response('Error', 500),
        ]);

        $playlist = Playlist::factory()->for(User::factory())->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://primary.example.com:8080',
                'username' => 'testuser',
                'password' => 'testpass',
            ],
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
            ],
        ]);

        $service = XtreamService::make(playlist: $playlist, retryLimit: 1);

        expect(fn () => $service->userInfo())->toThrow(Exception::class);
    });
});
