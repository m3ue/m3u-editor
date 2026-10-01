<?php

use App\Filament\CopilotTools\DvrScheduleTool;
use App\Filament\CopilotTools\EpgMappingApplyTool;
use App\Filament\CopilotTools\ExecuteDatabaseQueryTool;
use App\Filament\CopilotTools\NetworkContentBulkAddTool;
use App\Filament\CopilotTools\NetworkContentPinTool;
use App\Models\User;
use EslamRedaDiv\FilamentCopilot\Agent\CopilotAgent;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use Filament\Facades\Filament;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;

it('lets database select queries run without approval', function (string $action): void {
    $approval = (new ExecuteDatabaseQueryTool)->shouldRequestApproval(new Request(['table' => 'channels', 'action' => $action]));

    expect($approval)->toBeNull();
})->with(['select', 'SELECT']);

it('asks for approval before a database query changes records', function (string $action): void {
    $approval = (new ExecuteDatabaseQueryTool)->shouldRequestApproval(new Request(['table' => 'channels', 'action' => $action]));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBe(__('This query changes records in your database.'));
})->with(['update', 'delete', 'truncate']);

it('asks for approval before a write tool runs', function (string $toolClass, string $reason): void {
    $approval = app($toolClass)->shouldRequestApproval(new Request([]));

    expect($approval)->toBeInstanceOf(Approval::class)
        ->and($approval->reason)->toBe(__($reason));
})->with([
    [EpgMappingApplyTool::class, 'This applies EPG mappings to your channels.'],
    [NetworkContentBulkAddTool::class, 'This adds content to a network playlist.'],
    [NetworkContentPinTool::class, 'This changes when content airs in a network schedule.'],
]);

it('leaves read-only tools ungated', function (): void {
    expect(app(DvrScheduleTool::class))->not->toBeInstanceOf(Approvable::class);
});

it('pauses the copilot agent for approval instead of applying EPG mappings', function (): void {
    // AdminPanelProvider skips the Copilot plugin under test, but the agent's
    // context builder reads the panel's plugin.
    Filament::getPanel('admin')->plugin(FilamentCopilotPlugin::make()->provider('openai'));

    CopilotAgent::fake([
        new ToolCall('call_apply', 'EpgMappingApplyTool', ['epg_id' => 1, 'mappings' => '[]']),
    ]);

    $response = app(CopilotAgent::class)
        ->forPanel('admin')
        ->forUser(User::factory()->create())
        ->withTools([app(EpgMappingApplyTool::class)])
        ->prompt('Apply the exact matches.');

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals->first()->tool)->toBe('EpgMappingApplyTool')
        ->and($response->pendingApprovals->first()->reason)->toBe(__('This applies EPG mappings to your channels.'))
        ->and($response->toolResults)->toBeEmpty();
});
