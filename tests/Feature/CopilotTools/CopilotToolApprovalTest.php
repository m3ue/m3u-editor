<?php

use App\Filament\CopilotTools\DvrScheduleTool;
use App\Filament\CopilotTools\EpgMappingApplyTool;
use App\Filament\CopilotTools\ExecuteDatabaseQueryTool;
use App\Filament\CopilotTools\NetworkContentBulkAddTool;
use App\Filament\CopilotTools\NetworkContentPinTool;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
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
