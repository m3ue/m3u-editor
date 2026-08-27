<?php

declare(strict_types=1);

use App\Enums\SyncRunStatus;
use App\Filament\Pages\CustomDashboard;
use App\Filament\Widgets\ActiveStreamsWidget;
use App\Filament\Widgets\ContentBreakdownChart;
use App\Filament\Widgets\DvrStorageOverviewWidget;
use App\Filament\Widgets\HelpLinksWidget;
use App\Filament\Widgets\LibraryGrowthChart;
use App\Filament\Widgets\NeedsAttentionWidget;
use App\Filament\Widgets\PluginsOverviewWidget;
use App\Filament\Widgets\RecentViewerActivityWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\SystemHealthWidget;
use App\Filament\Widgets\UpcomingRecordingsWidget;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config()->set('proxy.proxy_integration_enabled', true);
});

it('renders the dashboard page for an admin without errors', function () {
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->for($admin)->create();
    Channel::factory()->count(3)->for($admin)->for($playlist)->create();

    $this->actingAs($admin);

    Livewire::test(CustomDashboard::class)->assertOk();
});

it('exposes quick actions in the dashboard header, gated by role', function () {
    // Non-admin, no proxy/DVR: only the always-on shortcuts.
    $this->actingAs(User::factory()->create());

    Livewire::test(CustomDashboard::class)
        ->assertActionExists('new_playlist')
        ->assertActionExists('playlists')
        ->assertActionExists('epgs')
        ->assertActionDoesNotExist('backups')
        ->assertActionDoesNotExist('settings');

    // Admin: the "More" group adds the admin-only shortcuts.
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CustomDashboard::class)
        ->assertActionExists('new_playlist')
        ->assertActionExists('backups')
        ->assertActionExists('logs')
        ->assertActionExists('settings');
});

it('gates admin-only widgets', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(NeedsAttentionWidget::canView())->toBeFalse()
        ->and(RecentViewerActivityWidget::canView())->toBeFalse()
        ->and(SystemHealthWidget::canView())->toBeFalse();

    $this->actingAs(User::factory()->admin()->create());

    expect(NeedsAttentionWidget::canView())->toBeTrue()
        ->and(SystemHealthWidget::canView())->toBeTrue();
});

it('builds KPI stats scoped to the current user', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['synced' => now()->subHour()]);
    Channel::factory()->count(4)->for($user)->for($playlist)->create(['enabled' => true]);
    Channel::factory()->count(2)->for($user)->for($playlist)->create(['enabled' => false]);
    Epg::factory()->for($user)->create();

    $other = User::factory()->create();
    Channel::factory()->count(9)->for($other)->for(Playlist::factory()->for($other))->create();

    $this->actingAs($user);

    // 4 cards for a non-admin user (no queue/job cards).
    expect(invade(app(StatsOverview::class))->getStats())->toHaveCount(4);

    // 6 cards for an admin: the 4 shared plus Failed Jobs and Queue Throughput.
    $this->actingAs(User::factory()->admin()->create());
    Cache::flush();

    expect(invade(app(StatsOverview::class))->getStats())->toHaveCount(6);
});

it('surfaces a failed sync in the needs-attention widget', function () {
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->for($admin)->create();
    SyncRun::factory()->for($playlist)->create([
        'user_id' => $admin->id,
        'status' => SyncRunStatus::Failed->value,
        'created_at' => now()->subHour(),
    ]);

    $this->actingAs($admin);

    $items = invade(app(NeedsAttentionWidget::class))->getViewData()['items'];

    expect($items)->not->toBeEmpty()
        ->and(collect($items)->pluck('label')->implode(' '))->toContain('sync failed');
});

it('returns chart-shaped data from every chart widget', function () {
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->for($admin)->create();
    Channel::factory()->count(2)->for($admin)->for($playlist)->create();

    $this->actingAs($admin);

    foreach ([ContentBreakdownChart::class, LibraryGrowthChart::class] as $chartClass) {
        $data = invade(app($chartClass))->getData();

        expect($data)->toHaveKeys(['datasets', 'labels'])
            ->and($data['datasets'])->not->toBeEmpty();
    }
});

it('breaks library growth into four series with a bounded query count', function () {
    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->for($admin)->create();
    Channel::factory()->count(3)->for($admin)->for($playlist)->create(['is_vod' => false]);
    Channel::factory()->count(2)->for($admin)->for($playlist)->create(['is_vod' => true]);

    // 4 series created well outside the 90-day window, 1 inside it. The chart must
    // still end on a running total of 5.
    foreach (range(1, 5) as $i) {
        DB::table('series')->insert([
            'user_id' => $admin->id,
            'playlist_id' => $playlist->id,
            'name' => "Series {$i}",
            'import_batch_no' => 1,
            'created_at' => $i === 5 ? now()->subDays(3) : now()->subYear(),
            'updated_at' => now(),
        ]);
    }

    $this->actingAs($admin);

    DB::enableQueryLog();
    $data = invade(app(LibraryGrowthChart::class))->getData();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $final = collect($data['datasets'])->keyBy('label')->map(fn ($d) => end($d['data']));

    expect($data['datasets'])->toHaveCount(4)
        ->and($final->keys()->all())->toBe(['Live channels', 'VOD channels', 'Series', 'Episodes'])
        ->and($queryCount)->toBeLessThanOrEqual(3)
        // Cumulative totals must land on the true row counts, including rows created
        // outside the 90-day window.
        ->and($final['Live channels'])->toBe(3)
        ->and($final['VOD channels'])->toBe(2)
        ->and($final['Series'])->toBe(5);
});

it('degrades gracefully when the proxy is unreachable', function () {
    Http::fake(fn () => Http::response(null, 500));

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $data = invade(app(ActiveStreamsWidget::class))->getViewData();

    expect($data['connected'])->toBeFalse()
        ->and($data['streams'])->toBeEmpty();
});

it('lists upcoming recordings only for the current user', function () {
    $user = User::factory()->create(['permissions' => ['use_dvr']]);
    $this->actingAs($user);

    expect(UpcomingRecordingsWidget::canView())->toBeTrue();

    Livewire::test(UpcomingRecordingsWidget::class)->assertOk();
});

it('renders every custom dashboard widget view for an admin', function () {
    Http::fake(fn () => Http::response(['streams' => []], 200));

    $admin = User::factory()->admin()->create();
    $playlist = Playlist::factory()->for($admin)->create();
    Channel::factory()->count(2)->for($admin)->for($playlist)->create();
    $this->actingAs($admin);

    foreach ([
        NeedsAttentionWidget::class,
        ActiveStreamsWidget::class,
        RecentViewerActivityWidget::class,
        PluginsOverviewWidget::class,
        DvrStorageOverviewWidget::class,
        SystemHealthWidget::class,
        HelpLinksWidget::class,
    ] as $widgetClass) {
        Livewire::test($widgetClass)->assertOk();
    }
});

it('renders system health rows each with a non-empty detail', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $checks = collect(invade(app(SystemHealthWidget::class))->getViewData()['checks'])->keyBy('label');

    expect($checks->keys())
        ->toContain('Database')
        ->toContain('Disk free')
        ->toContain('Version');

    // The info column must never render blank (a falsy string like "0" would have
    // been hidden by the old `@if ($check['detail'])` check).
    $checks->each(fn (array $check) => expect((string) $check['detail'])->not->toBe(''));
});

it('flags debug mode only when it is on in production', function () {
    $this->actingAs(User::factory()->admin()->create());

    $row = fn () => collect(invade(app(SystemHealthWidget::class))->getViewData()['checks'])
        ->firstWhere('label', 'Debug mode');

    config(['app.debug' => false]);
    app()->detectEnvironment(fn () => 'production');
    Cache::forget('dashboard_system_health');
    expect($row()['ok'])->toBeTrue()
        ->and($row()['detail'])->toBe('Disabled');

    config(['app.debug' => true]);
    app()->detectEnvironment(fn () => 'production');
    Cache::forget('dashboard_system_health');
    expect($row()['ok'])->toBeFalse()
        ->and($row()['detail'])->toBe('Enabled');

    config(['app.debug' => true]);
    app()->detectEnvironment(fn () => 'local');
    Cache::forget('dashboard_system_health');
    expect($row()['ok'])->toBeTrue();
});
