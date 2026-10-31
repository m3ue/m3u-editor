<?php

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'offline-synthetic';
    $settings->tmdb_language = 'de-DE';
    $settings->tmdb_rate_limit = 40;
    $settings->tmdb_confidence_threshold = 80;
    $this->tmdb = new TmdbService($settings);
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
});

function identityPlugin(): object
{
    $source = getenv('EPG_ENRICHER_SOURCE');
    if (! is_string($source) || ! is_file($source)) {
        test()->markTestSkipped('Set EPG_ENRICHER_SOURCE to exercise the optional native plugin contract.');
    }
    require_once $source;

    return new Plugin;
}

function identityEnrich(object $plugin, TmdbService $tmdb, array $programme, array &$cache): array
{
    $season = [];
    $images = [];
    $method = new ReflectionMethod($plugin, 'enrichProgrammeFromTmdb');

    return $method->invokeArgs($plugin, [&$programme, $tmdb, &$cache, true, false, false, false, false, false, false, false, false, &$season, &$images, ['tmdb_language' => 'de-DE']]);
}

function identityPage(int $page, int $pages = 2): array
{
    return ['page' => $page, 'total_pages' => $pages, 'total_results' => 20 * $pages,
        'results' => array_map(fn ($id) => ['id' => $id, 'name' => 'Synthetic '.$id, 'original_name' => 'Synthetic '.$id, 'overview' => '', 'first_air_date' => '2020-01-01'], range(($page - 1) * 20 + 1, $page * 20))];
}

it('binds the native full film subtitle and counts HTTP requests and replay', function (int $emptyPages) {
    $plugin = identityPlugin();
    $full = 'Synthetic Ridge - Broken Compass';
    Http::fake(function ($request) use ($full, $emptyPages) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(match (true) {
            str_ends_with($path, '/search/tv') => ['page' => 1, 'total_pages' => $emptyPages, 'total_results' => 0, 'results' => []],
            str_ends_with($path, '/search/movie') => ['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'results' => [['id' => 997001, 'title' => $full, 'original_title' => $full, 'release_date' => '2022-01-01', 'overview' => '']]],
            str_ends_with($path, '/translations') => ['id' => 997001, 'translations' => []],
            str_ends_with($path, '/alternative_titles') => ['id' => 997001, 'titles' => []],
            str_ends_with($path, '/movie/997001') => ['id' => 997001, 'title' => $full, 'original_title' => $full, 'release_date' => '2022-01-01', 'overview' => '', 'genres' => []],
            default => throw new RuntimeException('Unexpected synthetic endpoint '.$path),
        });
    });
    $programme = ['title' => 'Synthetic Ridge', 'subtitle' => 'Broken Compass', 'episode_num' => 'MV123', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'production_year' => 2022, 'tmdb_id' => 997999];
    $cache = [];
    expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['provider_match'])->toBeTrue();
    expect(array_values($cache)[0]['tmdb_id'])->toBe(997001)->and(array_values($cache)[0]['_media_type'])->toBe('movie');
    Http::assertSentCount(5);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/search/movie') && $r['query'] === mb_strtolower($full) && $r['year'] === 2022);
    expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['cache_hit'])->toBeTrue();
    Http::assertSentCount(5);
})->with([0, 1]);

it('keeps native film fallback closed on faulty TV evidence with measured HTTP budget', function ($fault) {
    $plugin = identityPlugin();
    $attempts = 0;
    Http::fake(function ($request) use ($fault, &$attempts) {
        $attempts++;
        expect(parse_url($request->url(), PHP_URL_PATH))->toBe('/3/search/tv');

        return match ($fault) {
            'timeout' => throw new RuntimeException('Synthetic timeout'),
            '429' => Http::response([], 429),
            'malformed' => Http::response(['results' => 'invalid']),
            'partial' => Http::response(identityPage($request['page'], 5)),
        };
    });
    $cache = [];
    expect(identityEnrich($plugin, $this->tmdb, ['title' => 'Synthetic Ridge', 'subtitle' => 'Broken Compass', 'episode_num' => 'MV123', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'production_year' => 2022], $cache)['provider_match'])->toBeFalse();
    expect($cache)->toBe([])->and($attempts)->toBe($fault === 'partial' ? 4 : 1);
    Http::assertSentCount($fault === 'timeout' ? 0 : ($fault === 'partial' ? 4 : 1));
})->with(['timeout', '429', 'malformed', 'partial']);

it('takes late native candidates through real details without hiding late rivals', function ($rival, $aliasFault) {
    $plugin = identityPlugin();
    $title = 'Synthetic Distant Harbor';
    Http::fake(function ($request) use ($title, $rival, $aliasFault) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/search/tv')) {
            $page = identityPage($request['page']);
            foreach ($page['results'] as &$row) {
                if ($row['id'] === 33 || ($rival && $row['id'] === 39)) {
                    $row['name'] = $title;
                    $row['original_name'] = $title;
                }
            }

            return Http::response($page);
        }
        preg_match('~/tv/(\d+)~', $path, $m);
        $id = (int) ($m[1] ?? 0);
        if ($aliasFault && $id === 33 && str_ends_with($path, '/alternative_titles')) {
            return Http::response([], 429);
        }

        return Http::response(match (true) {
            str_ends_with($path, '/translations') => ['id' => $id, 'translations' => []],
            str_ends_with($path, '/alternative_titles') => ['id' => $id, 'results' => []],
            $id === 33 => ['id' => 33, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'genres' => []],
            default => throw new RuntimeException('Unexpected endpoint '.$path),
        });
    });
    $cache = [];
    $programme = ['title' => $title, 'episode_num' => 'EP123'];
    expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['provider_match'])->toBe(! $rival && ! $aliasFault);
    Http::assertSentCount($aliasFault ? 67 : ($rival ? 82 : 83));
    if (! $rival && ! $aliasFault) {
        expect(array_values($cache)[0]['tmdb_id'])->toBe(33);
        expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['cache_hit'])->toBeTrue();
        Http::assertSentCount(83);
    }
})->with([[false, false], [true, false], [false, true]]);

it('uses only real names bound to the same EPG channel in the native settings path', function () {
    $plugin = identityPlugin();
    $epg = Epg::factory()->create();
    $other = Epg::factory()->create();
    EpgChannel::factory()->create(['epg_id' => $epg->id, 'channel_id' => 'synthetic-north', 'display_name' => 'Synthetic North', 'additional_display_names' => ['Synthetic North Alias']]);
    EpgChannel::factory()->create(['epg_id' => $other->id, 'channel_id' => 'synthetic-north', 'display_name' => 'Synthetic South']);
    $names = (new ReflectionMethod($plugin, 'resolveIdentityChannelNames'))->invoke($plugin, $epg->id, ['synthetic-north']);
    expect($names)->toBe(['synthetic-north' => ['Synthetic North Alias', 'Synthetic North']]);
    $title = 'Synthetic Observatory';
    Http::fake(function ($request) use ($title) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/search/tv')) {
            return Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 2, 'results' => array_map(fn ($id) => ['id' => $id, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'overview' => ''], [995001, 995002])]);
        }
        if (str_ends_with($path, '/search/movie')) {
            return Http::response(['page' => 1, 'total_pages' => 0, 'total_results' => 0, 'results' => []]);
        }
        preg_match('~/tv/(\d+)~', $path, $m);
        $id = (int) ($m[1] ?? 0);

        return Http::response(match (true) {
            str_ends_with($path, '/translations') => ['id' => $id, 'translations' => []],
            str_ends_with($path, '/alternative_titles') => ['id' => $id, 'results' => []],
            default => ['id' => $id, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'genres' => [], 'networks' => [['id' => $id, 'name' => $id === 995001 ? 'Synthetic North' : 'Synthetic South', 'logo_path' => null]]],
        });
    });
    $cache = [];
    $season = [];
    $images = [];
    $programme = ['title' => $title, 'channel' => 'synthetic-north'];
    $settings = ['overwrite_existing' => true, 'enrich_categories' => false, 'enrich_descriptions' => false, 'enrich_posters' => false, 'enrich_backdrops' => false, 'enrich_episode_details' => false, '_identity_channel_names' => $names];
    $method = new ReflectionMethod($plugin, 'enrichProgrammeWithSettings');
    $result = $method->invokeArgs($plugin, [&$programme, $this->tmdb, &$cache, &$season, &$images, $settings, $epg->id, 'de-DE']);
    expect($result['provider_match'])->toBeTrue()->and(array_values($cache)[0]['tmdb_id'])->toBe(995001);
    Http::assertSentCount(8);
});

it('budgets TV network evidence independently from movie rivals without hiding TV ties', function (int $movies, int $tvs, bool $sharedNetwork, bool $expected, int $requests) {
    $plugin = identityPlugin();
    $title = 'Synthetic Observatory';
    $detailIds = [];
    Http::fake(function ($request) use ($title, $movies, $tvs, $sharedNetwork, &$detailIds) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        foreach (['tv' => $tvs, 'movie' => $movies] as $media => $count) {
            if (str_ends_with($path, '/search/'.$media)) {
                return Http::response(['page' => 1, 'total_pages' => $count > 0 ? 1 : 0, 'total_results' => $count, 'results' => $count === 0 ? [] : array_map(fn ($id) => ['id' => $id, $media === 'tv' ? 'name' : 'title' => $title, $media === 'tv' ? 'original_name' : 'original_title' => $title, $media === 'tv' ? 'first_air_date' : 'release_date' => '2020-01-01', 'overview' => ''], range($media === 'tv' ? 986001 : 986101, ($media === 'tv' ? 986000 : 986100) + $count))]);
            }
        }
        preg_match('~/(tv|movie)/(\d+)~', $path, $match);
        $media = $match[1];
        $id = (int) $match[2];
        if (str_ends_with($path, '/translations')) {
            return Http::response(['id' => $id, 'translations' => []]);
        }
        if (str_ends_with($path, '/alternative_titles')) {
            return Http::response(['id' => $id, $media === 'tv' ? 'results' : 'titles' => []]);
        }
        $detailIds[] = $media.':'.$id;

        return Http::response($media === 'tv'
            ? ['id' => $id, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'genres' => [], 'networks' => [['id' => $id, 'name' => $sharedNetwork || $id === 986001 ? 'Synthetic North' : 'Synthetic South', 'logo_path' => null]]]
            : ['id' => $id, 'title' => $title, 'original_title' => $title, 'release_date' => '2020-01-01', 'genres' => []]);
    });
    $programme = ['title' => $title, 'channel' => 'synthetic-north'];
    $settings = ['overwrite_existing' => true, 'enrich_categories' => false, 'enrich_descriptions' => false, 'enrich_posters' => false, 'enrich_backdrops' => false, 'enrich_episode_details' => false, '_identity_channel_names' => ['synthetic-north' => ['Synthetic North']]];
    $cache = [];
    $season = [];
    $images = [];
    $result = (new ReflectionMethod($plugin, 'enrichProgrammeWithSettings'))->invokeArgs($plugin, [&$programme, $this->tmdb, &$cache, &$season, &$images, $settings, 1, 'de-DE']);
    expect($result['provider_match'])->toBe($expected);
    Http::assertSentCount($requests);
    if ($expected) {
        expect(array_values($cache)[0]['tmdb_id'])->toBe(986001);
    }
    if ($tvs <= 2) {
        expect($detailIds)->toContain('tv:986001', 'tv:986002');
    } else {
        expect($detailIds)->toBe([]);
    }
})->with([
    'two TV control' => [0, 2, false, true, 8],
    'one movie rival' => [1, 2, false, true, 11],
    'two movie rivals' => [2, 2, false, true, 14],
    'equal network rivals' => [1, 2, true, false, 11],
    'third TV rival exceeds evidence budget' => [1, 3, false, false, 10],
]);

it('requires unique full work evidence for markerless film rows after complete TV absence', function (string $case, bool $expected) {
    $plugin = identityPlugin();
    $full = 'Synthetic Ridge - Broken Compass';
    $synopsis = 'Cartographers uncover vanished treasure beneath ancient lighthouse';
    $attempts = 0;
    Http::fake(function ($request) use ($case, $full, $synopsis, &$attempts) {
        $attempts++;
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/search/tv')) {
            return match ($case) {
                'timeout' => throw new RuntimeException('Synthetic timeout'),
                '429' => Http::response([], 429),
                'malformed' => Http::response(['results' => []]),
                'partial' => Http::response(identityPage($request['page'], 5)),
                'tv-identity', 'tv-ambiguity' => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => $case === 'tv-identity' ? 1 : 2, 'results' => array_map(fn ($id) => ['id' => $id, 'name' => 'Synthetic Ridge', 'original_name' => 'Synthetic Ridge', 'first_air_date' => '2020-01-01', 'overview' => ''], $case === 'tv-identity' ? [987011] : [987011, 987012])]),
                default => Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []]),
            };
        }
        if (str_ends_with($path, '/search/movie')) {
            $ids = $case === 'ambiguous' ? [987001, 987002] : [987001];

            return Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => count($ids), 'results' => array_map(fn ($id) => ['id' => $id, 'title' => $full, 'original_title' => $full, 'release_date' => '2022-01-01', 'overview' => $synopsis], $ids)]);
        }
        preg_match('~/(tv|movie)/(\d+)~', $path, $match);
        $media = $match[1];
        $id = (int) $match[2];

        return Http::response(match (true) {
            str_ends_with($path, '/translations') => ['id' => $id, 'translations' => []],
            str_ends_with($path, '/alternative_titles') => ['id' => $id, $media === 'tv' ? 'results' : 'titles' => []],
            $media === 'tv' => ['id' => $id, 'name' => 'Synthetic Ridge', 'original_name' => 'Synthetic Ridge', 'first_air_date' => '2020-01-01', 'genres' => []],
            default => ['id' => $id, 'title' => $full, 'original_title' => $full, 'release_date' => '2022-01-01', 'overview' => $synopsis, 'genres' => []],
        });
    });
    $programme = ['title' => 'Synthetic Ridge', 'subtitle' => 'Broken Compass', 'episode_num' => $case === 'ep' ? 'EP123' : '0.0.', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'desc' => $case === 'no-description' ? '' : $synopsis];
    if ($case === 'no-subtitle') {
        unset($programme['subtitle']);
    }
    if ($case === 'untyped-subtitle') {
        $programme['subtitle'] = ['Broken Compass'];
    }
    $cache = [];
    expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['provider_match'])->toBe($expected || $case === 'tv-identity');
    if ($expected) {
        expect(array_values($cache)[0]['tmdb_id'])->toBe(987001)->and(array_values($cache)[0]['_media_type'])->toBe('movie');
        Http::assertSentCount(5);
        expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['cache_hit'])->toBeTrue();
        Http::assertSentCount(5);
        // An explicit EP signal must not reuse this markerless movie identity.
        $programme['episode_num'] = 'EP123';
        expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['provider_match'])->toBeFalse();
        Http::assertSentCount(5);
    } elseif (in_array($case, ['ep', 'no-subtitle', 'untyped-subtitle', 'tv-identity', 'tv-ambiguity', 'timeout', '429', 'malformed', 'partial'], true)) {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/search/movie'));
        if (in_array($case, ['timeout', '429', 'malformed', 'partial'], true)) {
            expect($attempts)->toBe($case === 'partial' ? 4 : 1);
        }
    }
})->with([
    ['markerless', true], ['ep', false], ['no-subtitle', false], ['untyped-subtitle', false],
    ['no-description', false], ['ambiguous', false], ['tv-identity', false], ['tv-ambiguity', false],
    ['timeout', false], ['429', false], ['malformed', false], ['partial', false],
]);

it('measures both media types across full base and year fallback searches', function () {
    $plugin = identityPlugin();
    $queries = [];
    Http::fake(function ($request) use (&$queries) {
        $queries[] = [basename(parse_url($request->url(), PHP_URL_PATH)), $request['query'], $request['year'] ?? $request['first_air_date_year'] ?? null];

        return Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 0, 'results' => []]);
    });
    $programme = ['title' => 'Synthetic Atlas - Missing Harbor (2022)'];
    $cache = [];
    expect(identityEnrich($plugin, $this->tmdb, $programme, $cache)['provider_match'])->toBeFalse();
    expect($queries)->toBe([
        ['tv', 'synthetic atlas - missing harbor', 2022], ['movie', 'synthetic atlas - missing harbor', 2022],
        ['tv', 'synthetic atlas - missing harbor', null], ['movie', 'synthetic atlas - missing harbor', null],
        ['tv', 'synthetic atlas', 2022], ['movie', 'synthetic atlas', 2022],
        ['tv', 'synthetic atlas', null], ['movie', 'synthetic atlas', null],
    ]);
    Http::assertSentCount(8);
});

it('keeps late cross-media rivals in the complete comparison with measured HTTP costs', function () {
    $plugin = identityPlugin();
    $title = 'Synthetic Distant Harbor';
    Http::fake(function ($request) use ($title) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (preg_match('~/search/(tv|movie)$~', $path, $match)) {
            $media = $match[1];
            $page = $request['page'];
            $rows = array_map(fn ($id) => ['id' => $id, $media === 'tv' ? 'name' : 'title' => $id === 40 ? $title : 'Unrelated Fiction '.$id, $media === 'tv' ? 'original_name' : 'original_title' => $id === 40 ? $title : 'Unrelated Fiction '.$id, $media === 'tv' ? 'first_air_date' : 'release_date' => '2020-01-01', 'overview' => ''], range(($page - 1) * 20 + 1, $page * 20));

            return Http::response(['page' => $page, 'total_pages' => 2, 'total_results' => 40, 'results' => $rows]);
        }
        preg_match('~/(tv|movie)/(\d+)~', $path, $match);
        $media = $match[1];
        $id = (int) $match[2];

        return Http::response(match (true) {
            str_ends_with($path, '/translations') => ['id' => $id, 'translations' => []],
            str_ends_with($path, '/alternative_titles') => ['id' => $id, $media === 'tv' ? 'results' : 'titles' => []],
            default => throw new RuntimeException('No identity winner may request details'),
        });
    });
    $cache = [];
    expect(identityEnrich($plugin, $this->tmdb, ['title' => $title], $cache)['provider_match'])->toBeFalse();
    expect($cache)->toBe([]);
    Http::assertSentCount(164); // Four pages plus aliases/translations for all 80 candidates.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/movie/40/translations'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/tv/40/translations'));
});

it('accepts both valid empty native first page conventions', function (int $pages) {
    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => $pages, 'total_results' => 0, 'results' => []])]);
    expect($this->tmdb->searchIdentityCandidates('tv', 'Synthetic Absent Observatory'))
        ->toBe(['status' => 'complete', 'candidates' => []]);
    Http::assertSentCount(1);
})->with([0, 1]);

it('rejects inconsistent empty native envelopes', function (array $payload) {
    Http::fake(['*' => Http::response($payload)]);
    expect($this->tmdb->searchIdentityCandidates('tv', 'Synthetic Absent Observatory'))
        ->toBe(['status' => 'error', 'candidates' => []]);
    Http::assertSentCount(1);
})->with([
    [['page' => 1, 'total_pages' => 2, 'total_results' => 0, 'results' => []]],
    [['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'results' => []]],
    [['page' => 1, 'total_pages' => 0, 'total_results' => 1, 'results' => []]],
    [['page' => 2, 'total_pages' => 1, 'total_results' => 0, 'results' => []]],
    [['page' => 1, 'total_results' => 0, 'results' => []]],
    [['page' => 1, 'total_pages' => 1, 'results' => []]],
]);

it('collects complete native pages with a hard budget and cached replay', function () {
    Http::fake(fn ($request) => Http::response(identityPage($request['page'])));
    $result = $this->tmdb->searchIdentityCandidates('tv', 'Synthetic 1984', null);
    expect($result['status'])->toBe('complete')->and($result['candidates'])->toHaveCount(40)
        ->and($result['candidates'][32]['tmdb_id'])->toBe(33);
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r['query'] === 'synthetic 1984' && ! isset($r['first_air_date_year']));
    expect($this->tmdb->searchIdentityCandidates('tv', 'SYNTHETIC 1984', null))->toBe($result);
    Http::assertSentCount(2);
});

it('distinguishes an incomplete budget from complete evidence and deduplicates IDs', function () {
    Http::fake(function ($request) {
        $page = identityPage($request['page'], 5);
        if ($request['page'] === 2) {
            $page['results'][0] = identityPage(1)['results'][0];
        }

        return Http::response($page);
    });
    $result = $this->tmdb->searchIdentityCandidates('tv', 'Synthetic', null);
    expect($result['status'])->toBe('incomplete')->and($result['candidates'])->toHaveCount(79);
    Http::assertSentCount(4);
});

it('never treats faulty pagination as a unique complete candidate set', function ($fault) {
    Http::fake(function ($request) use ($fault) {
        if ($request['page'] === 1) {
            return Http::response(identityPage(1));
        }

        return match ($fault) {
            '429' => Http::response([], 429),
            'timeout' => throw new RuntimeException('synthetic timeout'),
            'malformed' => Http::response(['results' => 'invalid']),
            'partial' => Http::response(array_replace(identityPage(2), ['total_pages' => 3])),
            'wrong-page' => Http::response(identityPage(1)),
            'short' => Http::response(array_replace(identityPage(2), ['results' => []])),
        };
    });
    expect($this->tmdb->searchIdentityCandidates('tv', 'Synthetic', null))->toBe(['status' => 'error', 'candidates' => []]);
    Http::assertSentCount($fault === 'timeout' ? 1 : 2);
})->with(['429', 'timeout', 'malformed', 'partial', 'wrong-page', 'short']);

it('reads bound native translated work names not language labels and caches the request', function () {
    Http::fake(['*/tv/992001/translations*' => Http::response(['id' => 992001, 'translations' => [
        ['iso_3166_1' => 'JP', 'iso_639_1' => 'ja', 'name' => 'Japanese', 'data' => ['name' => '合成作品']],
        ['iso_3166_1' => 'DE', 'iso_639_1' => 'de', 'name' => 'German', 'data' => ['name' => 'Synthetisches Werk']],
    ]])]);
    $result = $this->tmdb->getIdentityTranslations('tv', 992001);
    expect($result)->toBe(['status' => 'complete', 'titles' => ['合成作品', 'Synthetisches Werk']]);
    expect($this->tmdb->getIdentityTranslations('tv', 992001))->toBe($result);
    Http::assertSentCount(1);
});

it('preserves numeric translated work titles as strings and deduplicates them', function () {
    Http::fake(['*' => Http::response(['id' => 992003, 'translations' => [
        ['iso_3166_1' => 'DE', 'iso_639_1' => 'de', 'data' => ['title' => '1984']],
        ['iso_3166_1' => 'US', 'iso_639_1' => 'en', 'data' => ['title' => '1984']],
    ]])]);
    expect($this->tmdb->getIdentityTranslations('movie', 992003))->toBe(['status' => 'complete', 'titles' => ['1984']]);
    Http::assertSentCount(1);
});

it('keeps native aliases ID-bound and distinguishes errors from empty lists', function ($payload, $status) {
    Http::fake(['*' => Http::response($payload, $status)]);
    expect($this->tmdb->getIdentityAlternativeTitles('tv', 992004))->toBe(['status' => 'error', 'titles' => []]);
    Http::assertSentCount(1);
})->with([
    'wrong id' => [['id' => 992005, 'results' => []], 200],
    'malformed' => [['id' => 992004, 'results' => 'bad'], 200],
    'bad title' => [['id' => 992004, 'results' => [['title' => [], 'iso_3166_1' => 'DE']]], 200],
    '429' => [[], 429],
]);

it('uses native translated work names but not the language label in the plugin', function ($workName, $matched) {
    $plugin = identityPlugin();
    $title = 'Synthetic Atlas';
    Http::fake(function ($request) use ($title, $workName) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return Http::response(match (true) {
            str_ends_with($path, '/search/tv') => ['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'results' => [['id' => 992006, 'name' => '合成作品', 'original_name' => '合成作品', 'first_air_date' => '2020-01-01', 'overview' => '']]],
            str_ends_with($path, '/alternative_titles') => ['id' => 992006, 'results' => []],
            str_ends_with($path, '/translations') => ['id' => 992006, 'translations' => [['iso_3166_1' => 'DE', 'iso_639_1' => 'de', 'name' => $title, 'data' => ['name' => $workName]]]],
            default => ['id' => 992006, 'name' => '合成作品', 'original_name' => '合成作品', 'first_air_date' => '2020-01-01', 'genres' => []],
        });
    });
    $cache = [];
    expect(identityEnrich($plugin, $this->tmdb, ['title' => $title, 'episode_num' => 'EP123'], $cache)['provider_match'])->toBe($matched);
    Http::assertSentCount($matched ? 4 : 3);
})->with([['Synthetic Atlas', true], ['Unrelated Work', false]]);

it('rejects translations bound to the wrong ID or malformed work data', function ($payload) {
    Http::fake(['*' => Http::response($payload)]);
    expect($this->tmdb->getIdentityTranslations('tv', 992001))->toBe(['status' => 'error', 'titles' => []]);
    Http::assertSentCount(1);
})->with([
    'wrong ID' => [['id' => 992002, 'translations' => []]],
    'language label only' => [['id' => 992001, 'translations' => [['name' => 'German']]]],
    'wrong title type' => [['id' => 992001, 'translations' => [['iso_3166_1' => 'DE', 'iso_639_1' => 'de', 'data' => ['name' => []]]]]],
]);
