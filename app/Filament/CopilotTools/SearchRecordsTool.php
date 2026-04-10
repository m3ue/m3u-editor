<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchRecordsTool extends AbstractResourceTool
{
    public function description(): Stringable|string
    {
        return 'Search '.$this->getPluralLabel().' by a keyword.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description(__('The search term to look for'))->required(),
            'limit' => $schema->integer()->description(__('Maximum results to return (default: 10, max: 50)')),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = (string) $request['query'];
        $limit = min(50, max(1, (int) ($request['limit'] ?? 10)));

        $searchColumns = $this->searchableAttributes();

        $q = $this->getBaseQuery()->where(function ($q) use ($searchColumns, $query) {
            foreach ($searchColumns as $col) {
                $q->orWhere($col, 'LIKE', "%{$query}%");
            }
        });

        $records = $q->limit($limit)->get();

        if ($records->isEmpty()) {
            return "No {$this->getPluralLabel()} found matching '{$query}'.";
        }

        $lines = [
            "Search results for '{$query}' in {$this->getPluralLabel()} ({$records->count()} found):",
            '',
        ];

        foreach ($records as $record) {
            $lines[] = '- #'.$record->getKey().': '.$this->formatRecord($record);
        }

        return implode("\n", $lines);
    }
}
