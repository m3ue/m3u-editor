<?php

use App\Models\EmbyLibraryMapping;
use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createSyntheticStatusMapping(): EmbyLibraryMapping
{
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby', 'enabled' => true,
    ]);

    return EmbyLibraryMapping::create([
        'media_server_integration_id' => $integration->id,
        'user_id' => $user->id,
        'source_kind' => 'all',
        'source_identifier' => 'all',
        'source_label' => 'Synthetic movies',
        'target_library_id' => 'synthetic-library',
        'target_library_name' => 'Synthetic library',
        'collection_type' => 'movies',
        'output_path' => '/srv/synthetic/managed/movies',
        'is_managed' => true,
        'enabled' => true,
        'options' => ['nfo' => true],
    ]);
}

it('keeps an applied unchanged revision synced across repeated catalog reads', function () {
    $mapping = createSyntheticStatusMapping();
    $service = app(EmbyPublicationCatalogService::class);
    $revision = $service->buildMapping($mapping)['revision'];
    $mapping->updateQuietly([
        'status' => 'synced',
        'status_summary' => 'Synthetic successful publication',
        'last_planned_revision' => $revision,
        'last_applied_revision' => $revision,
        'last_success_at' => now(),
    ]);

    $first = $service->buildForUser($mapping->user);
    $second = $service->buildForUser($mapping->user);

    expect($mapping->fresh()->status)->toBe('synced')
        ->and($mapping->fresh()->status_summary)->toBe('Synthetic successful publication')
        ->and($first)->toBe($second);
});

it('recovers a misleading planned label only when the revision really succeeded', function (bool $succeeded) {
    $mapping = createSyntheticStatusMapping();
    $service = app(EmbyPublicationCatalogService::class);
    $revision = $service->buildMapping($mapping)['revision'];
    $mapping->updateQuietly([
        'status' => 'planned',
        'last_planned_revision' => $revision,
        'last_applied_revision' => $revision,
        'last_success_at' => $succeeded ? now() : null,
    ]);

    $service->buildForUser($mapping->user);

    expect($mapping->fresh()->status)->toBe($succeeded ? 'synced' : 'planned');
})->with([true, false]);

it('marks a genuinely changed revision planned without changing the applied revision', function () {
    $mapping = createSyntheticStatusMapping();
    $service = app(EmbyPublicationCatalogService::class);
    $revision = $service->buildMapping($mapping)['revision'];
    $mapping->updateQuietly([
        'status' => 'synced',
        'last_applied_revision' => $revision,
        'last_success_at' => now(),
        'options' => ['nfo' => false],
    ]);

    $service->buildForUser($mapping->user);

    expect($mapping->fresh()->status)->toBe('planned')
        ->and($mapping->fresh()->last_applied_revision)->toBe($revision)
        ->and($mapping->fresh()->last_planned_revision)->not->toBe($revision);
});

it('does not erase failure or drift diagnostics by reading a catalog', function (string $status) {
    $mapping = createSyntheticStatusMapping();
    $mapping->updateQuietly([
        'status' => $status,
        'status_summary' => 'Synthetic action required',
        'error_summary' => 'Synthetic preserved diagnostic',
    ]);

    app(EmbyPublicationCatalogService::class)->buildForUser($mapping->user);

    expect($mapping->fresh()->status)->toBe($status)
        ->and($mapping->fresh()->status_summary)->toBe('Synthetic action required')
        ->and($mapping->fresh()->error_summary)->toBe('Synthetic preserved diagnostic')
        ->and($mapping->fresh()->last_planned_revision)->toMatch('/^[a-f0-9]{64}$/');
})->with(['failed', 'drifted']);

it('does not add a per-mapping state reload to catalog construction', function () {
    $mapping = createSyntheticStatusMapping();
    $user = $mapping->user;
    $mappingSelects = 0;
    DB::listen(function ($query) use (&$mappingSelects): void {
        if (preg_match('/^select .* from ["`]?emby_library_mappings["`]? /i', $query->sql)) {
            $mappingSelects++;
        }
    });

    app(EmbyPublicationCatalogService::class)->buildForUser($user);

    expect($mappingSelects)->toBe(1);
});

it('does not overwrite a successful callback received while building the catalog', function () {
    $mapping = createSyntheticStatusMapping();
    $service = Mockery::mock(EmbyPublicationCatalogService::class)->makePartial();
    $service->shouldReceive('buildMapping')->once()->andReturnUsing(function (EmbyLibraryMapping $snapshot) {
        $catalog = (new EmbyPublicationCatalogService)->buildMapping($snapshot);
        EmbyLibraryMapping::whereKey($snapshot->id)->update([
            'status' => 'synced',
            'status_summary' => 'Synthetic concurrent success',
            'last_applied_revision' => $catalog['revision'],
            'last_success_at' => now(),
        ]);

        return $catalog;
    });

    $service->buildForUser($mapping->user);

    expect($mapping->fresh()->status)->toBe('synced')
        ->and($mapping->fresh()->status_summary)->toBe('Synthetic concurrent success');
});
