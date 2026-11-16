<?php

use App\Livewire\AioStreamsCatalogRow;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);
});

function mountRow(int $integrationId): Testable
{
    return Livewire::test(AioStreamsCatalogRow::class, [
        'integrationId' => $integrationId,
        'catalogId' => 'top',
        'catalogType' => 'movie',
        'catalogName' => 'Top Movies',
    ]);
}

it('does not fetch the catalog on initial mount', function () {
    mountRow($this->integration->id)
        ->assertOk()
        ->assertSet('loaded', false)
        ->assertSet('items', []);

    Http::assertNothingSent();
});

it('fetches the catalog only once loadIfVisible is called', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response([
            'metas' => [
                ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
            ],
        ]),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->assertSet('loaded', true)
        ->assertSet('loadFailed', false)
        ->assertSet('items', [
            ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
        ]);

    Http::assertSentCount(1);
});

it('does not refetch on a repeated loadIfVisible call', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->call('loadIfVisible');

    Http::assertSentCount(1);
});

it('serves a second component instance from cache without re-hitting the network', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
    ]);

    mountRow($this->integration->id)->call('loadIfVisible');
    Http::assertSentCount(1);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->assertSet('items', [
            ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
        ]);
    Http::assertSentCount(1);
});

it('keeps the whole first page instead of cutting it to 20 items', function () {
    $metas = collect(range(1, 30))
        ->map(fn (int $n) => ['id' => "tt{$n}", 'type' => 'movie', 'name' => "Movie {$n}"])
        ->all();

    Http::fake(['*/catalog/movie/top.json' => Http::response(['metas' => $metas])]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->assertCount('items', 30)
        ->assertSet('skip', 30)
        ->assertSet('hasMore', true)
        ->assertSee(__('Load more'));
});

it('appends the next page using the Stremio skip extra', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [
            ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
            ['id' => 'tt2', 'type' => 'movie', 'name' => 'Movie Two'],
        ]]),
        '*/catalog/movie/top/skip=2.json' => Http::response(['metas' => [
            ['id' => 'tt2', 'type' => 'movie', 'name' => 'Movie Two'],
            ['id' => 'tt3', 'type' => 'movie', 'name' => 'Movie Three'],
        ]]),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->call('loadMore')
        ->assertSet('items', [
            ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
            ['id' => 'tt2', 'type' => 'movie', 'name' => 'Movie Two'],
            ['id' => 'tt3', 'type' => 'movie', 'name' => 'Movie Three'],
        ])
        ->assertSet('skip', 4)
        ->assertSet('hasMore', true);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/catalog/movie/top/skip=2.json'));
});

it('stops paging once a page is empty', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
        '*/catalog/movie/top/skip=1.json' => Http::response(['metas' => []]),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->call('loadMore')
        ->assertSet('hasMore', false)
        ->assertDontSee(__('Load more'))
        ->call('loadMore');

    Http::assertSentCount(2);
});

it('stops paging when a catalog ignores skip and repeats its first page', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
        '*/catalog/movie/top/skip=1.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->call('loadMore')
        ->assertCount('items', 1)
        ->assertSet('hasMore', false);
});

it('keeps loaded items and notifies when the next page fails', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
        '*/catalog/movie/top/skip=1.json' => fn () => throw new ConnectionException('timeout'),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->call('loadMore')
        ->assertNotified(__('Failed to load more items'))
        ->assertCount('items', 1)
        ->assertSet('hasMore', true);
});
