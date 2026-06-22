<?php

namespace App\Http\Controllers;

use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\PluginTableRecord;
use App\Plugins\PluginUiTableRegistry;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PluginTableExportController extends Controller
{
    public function __invoke(Request $request, Plugin $plugin, string $table, string $format): StreamedResponse
    {
        $format = strtolower($format);
        abort_unless(in_array($format, ['csv', 'json'], true), 404);

        $registry = app(PluginUiTableRegistry::class);
        $definition = $registry->tableFor($plugin, $table);
        abort_unless($definition !== null, 404);
        abort_unless(in_array($format, $registry->exportFormatsFor($definition), true), 404);

        $tableName = (string) ($definition['table'] ?? '');
        abort_unless($tableName !== '' && Schema::hasTable($tableName), 404);

        $run = $this->runFromRequest($request, $plugin, $tableName);
        $playlistId = $this->playlistIdFromRequest($request, $tableName);
        $this->authorizeExport($request, $run);

        $query = $registry->applyTableScope(
            $registry->newModel($plugin, $tableName)->newQuery(),
            $plugin,
            $tableName,
            $run?->id,
            $playlistId,
        );

        $columns = $this->declaredColumns($definition, $tableName);
        $filename = $this->filename($plugin, $table, $format, $run?->id, $playlistId);

        return $format === 'json'
            ? $this->jsonResponse($query, $tableName, $columns, $filename)
            : $this->csvResponse($query, $tableName, $columns, $filename);
    }

    private function runFromRequest(Request $request, Plugin $plugin, string $tableName): ?PluginRun
    {
        $runId = $request->integer('run') ?: null;
        if ($runId === null) {
            return null;
        }

        abort_unless(Schema::hasColumn($tableName, 'extension_plugin_run_id'), 404);

        /** @var PluginRun|null $run */
        $run = $plugin->runs()->find($runId);
        abort_unless($run !== null, 404);

        return $run;
    }

    private function playlistIdFromRequest(Request $request, string $tableName): ?int
    {
        $playlistId = $request->integer('playlist') ?: null;
        if ($playlistId !== null) {
            abort_unless(Schema::hasColumn($tableName, 'playlist_id'), 404);
        }

        return $playlistId;
    }

    private function authorizeExport(Request $request, ?PluginRun $run): void
    {
        abort_unless($request->user()?->canUseTools(), 403);

        if ($run !== null) {
            abort_unless($run->canBeViewedBy($request->user()), 403);

            return;
        }

        abort_unless($request->user()?->canManagePlugins(), 403);
    }

    /**
     * @param  array<int, array{name: string, label: string}>  $columns
     */
    private function csvResponse(Builder $query, string $tableName, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $tableName, $columns): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, array_column($columns, 'label'));

            $count = 0;
            foreach ($this->orderedQuery($query, $tableName)->cursor() as $record) {
                fputcsv($stream, collect($columns)
                    ->map(fn (array $col): string => $this->csvValue(data_get($record->toArray(), $col['name'])))
                    ->all());

                if (++$count % 1000 === 0) {
                    flush();
                }
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<int, array{name: string, label: string}>  $columns
     */
    private function jsonResponse(Builder $query, string $tableName, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $tableName, $columns): void {
            $first = true;
            echo '[';

            $count = 0;
            foreach ($this->orderedQuery($query, $tableName)->cursor() as $record) {
                try {
                    $payload = json_encode($this->jsonPayload($record, $columns), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    Log::warning('Plugin table export JSON encode failed.', ['error' => $e->getMessage()]);

                    continue;
                }

                echo $first ? '' : ',';
                echo $payload;
                $first = false;

                if (++$count % 1000 === 0) {
                    flush();
                }
            }

            echo ']';
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function orderedQuery(Builder $query, string $tableName): Builder
    {
        if (Schema::hasColumn($tableName, 'id')) {
            return $query->orderBy('id');
        }

        if (Schema::hasColumn($tableName, 'created_at')) {
            return $query->orderBy('created_at');
        }

        return $query;
    }

    /**
     * @param  array<int, array{name: string, label: string}>  $columns
     * @return array<string, mixed>
     */
    private function jsonPayload(PluginTableRecord $record, array $columns): array
    {
        return collect($columns)
            ->mapWithKeys(fn (array $col): array => [$col['name'] => $this->jsonValue(data_get($record->toArray(), $col['name']))])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, array{name: string, label: string}>
     */
    private function declaredColumns(array $definition, string $tableName): array
    {
        $declared = collect($definition['columns'] ?? [])
            ->filter(fn (mixed $col): bool => is_array($col) && filled($col['name'] ?? null))
            ->map(fn (array $col): array => [
                'name' => (string) $col['name'],
                'label' => (string) ($col['label'] ?? $col['name']),
            ])
            ->values()
            ->all();

        if ($declared !== []) {
            return $declared;
        }

        return collect(Schema::getColumnListing($tableName))
            ->map(fn (string $col): array => ['name' => $col, 'label' => $col])
            ->all();
    }

    private function csvValue(mixed $value): string
    {
        $value = $this->jsonValue($value);

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }

    private function jsonValue(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DATE_ATOM)
            : $value;
    }

    private function filename(Plugin $plugin, string $table, string $format, ?int $runId, ?int $playlistId): string
    {
        $parts = [
            $plugin->plugin_id ?: "plugin-{$plugin->id}",
            $table,
            $runId ? "run-{$runId}" : null,
            $playlistId ? "playlist-{$playlistId}" : null,
        ];

        return Str::slug(collect($parts)->filter()->implode('-')).'.'.$format;
    }
}
