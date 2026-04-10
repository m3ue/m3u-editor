<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListRecordsTool extends AbstractResourceTool
{
    public function description(): Stringable|string
    {
        return 'List '.$this->getPluralLabel().' with pagination.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description(__('Page number (default: 1)')),
            'per_page' => $schema->integer()->description(__('Items per page (default: 15, max: 50)')),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 15)));

        $records = $this->getBaseQuery()->paginate($perPage, ['*'], 'page', $page);

        if ($records->isEmpty()) {
            return 'No '.$this->getPluralLabel().' found.';
        }

        $lines = [
            $this->getPluralLabel().' — Page '.$records->currentPage().' of '.$records->lastPage().' ('.$records->total().' total)',
            '',
        ];

        foreach ($records as $record) {
            $lines[] = '- #'.$record->getKey().': '.$this->formatRecord($record);
        }

        return implode("\n", $lines);
    }
}
