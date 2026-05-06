<?php

use App\Models\Playlist;
use App\Models\User;
use App\Sync\Importers\InclusionPolicy;

it('returns true when group is in the selected list', function () {
    $policy = new InclusionPolicy(
        useRegex: false,
        selectedGroups: ['News', 'Sports'],
        includedGroupPrefixes: [],
        selectedVodGroups: [],
        includedVodGroupPrefixes: [],
        selectedCategories: [],
        includedCategoryPrefixes: [],
    );

    expect($policy->shouldIncludeChannel('News'))->toBeTrue();
    expect($policy->shouldIncludeChannel('Sports'))->toBeTrue();
    expect($policy->shouldIncludeChannel('Movies'))->toBeFalse();
});

it('matches a prefix using str_starts_with when useRegex is false', function () {
    $policy = new InclusionPolicy(
        useRegex: false,
        selectedGroups: [],
        includedGroupPrefixes: ['US|', 'UK|'],
        selectedVodGroups: [],
        includedVodGroupPrefixes: [],
        selectedCategories: [],
        includedCategoryPrefixes: [],
    );

    expect($policy->shouldIncludeChannel('US| News HD'))->toBeTrue();
    expect($policy->shouldIncludeChannel('UK| Sport'))->toBeTrue();
    expect($policy->shouldIncludeChannel('CA| News'))->toBeFalse();
    // Substring (not prefix) should NOT match in non-regex mode.
    expect($policy->shouldIncludeChannel('News US|'))->toBeFalse();
});

it('matches via regex when useRegex is true', function () {
    $policy = new InclusionPolicy(
        useRegex: true,
        selectedGroups: [],
        includedGroupPrefixes: ['^(US|UK)\|', 'Sports$'],
        selectedVodGroups: [],
        includedVodGroupPrefixes: [],
        selectedCategories: [],
        includedCategoryPrefixes: [],
    );

    expect($policy->shouldIncludeChannel('US| News'))->toBeTrue();
    expect($policy->shouldIncludeChannel('UK| Movies'))->toBeTrue();
    expect($policy->shouldIncludeChannel('Live Sports'))->toBeTrue();
    expect($policy->shouldIncludeChannel('CA| Anything'))->toBeFalse();
});

it('escapes forward-slash delimiters in regex patterns', function () {
    $policy = new InclusionPolicy(
        useRegex: true,
        selectedGroups: [],
        includedGroupPrefixes: ['News/Sports'],
        selectedVodGroups: [],
        includedVodGroupPrefixes: [],
        selectedCategories: [],
        includedCategoryPrefixes: [],
    );

    // The literal "/" inside the user pattern must be auto-escaped so the
    // composite /News\/Sports/u still compiles and matches.
    expect($policy->shouldIncludeChannel('News/Sports HD'))->toBeTrue();
    expect($policy->shouldIncludeChannel('NewsSports HD'))->toBeFalse();
});

it('routes vod and series checks to their own lists', function () {
    $policy = new InclusionPolicy(
        useRegex: false,
        selectedGroups: ['LiveOnly'],
        includedGroupPrefixes: [],
        selectedVodGroups: ['VodOnly'],
        includedVodGroupPrefixes: ['Movies|'],
        selectedCategories: ['SeriesOnly'],
        includedCategoryPrefixes: ['Drama|'],
    );

    expect($policy->shouldIncludeChannel('LiveOnly'))->toBeTrue();
    expect($policy->shouldIncludeChannel('VodOnly'))->toBeFalse();
    expect($policy->shouldIncludeChannel('SeriesOnly'))->toBeFalse();

    expect($policy->shouldIncludeVod('VodOnly'))->toBeTrue();
    expect($policy->shouldIncludeVod('Movies| Action'))->toBeTrue();
    expect($policy->shouldIncludeVod('LiveOnly'))->toBeFalse();

    expect($policy->shouldIncludeSeries('SeriesOnly'))->toBeTrue();
    expect($policy->shouldIncludeSeries('Drama| Mystery'))->toBeTrue();
    expect($policy->shouldIncludeSeries('LiveOnly'))->toBeFalse();
});

it('builds from a Playlist using import_prefs', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'import_prefs' => [
            'use_regex' => true,
            'selected_groups' => ['News'],
            'included_group_prefixes' => ['^US\|'],
            'selected_vod_groups' => ['Vod'],
            'included_vod_group_prefixes' => [],
            'selected_categories' => ['Cat'],
            'included_category_prefixes' => [],
        ],
    ]);

    $policy = InclusionPolicy::fromPlaylist($playlist);

    expect($policy->useRegex)->toBeTrue();
    expect($policy->shouldIncludeChannel('News'))->toBeTrue();
    expect($policy->shouldIncludeChannel('US| Sports'))->toBeTrue();
    expect($policy->shouldIncludeVod('Vod'))->toBeTrue();
    expect($policy->shouldIncludeSeries('Cat'))->toBeTrue();
});

it('defaults to safe empty values when import_prefs is missing', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['import_prefs' => null]);

    $policy = InclusionPolicy::fromPlaylist($playlist);

    expect($policy->useRegex)->toBeFalse();
    expect($policy->shouldIncludeChannel('Anything'))->toBeFalse();
    expect($policy->shouldIncludeVod('Anything'))->toBeFalse();
    expect($policy->shouldIncludeSeries('Anything'))->toBeFalse();
});
