<?php

namespace App\Http\Controllers;

use App\Models\Plugin;
use App\Models\PluginRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PluginRunReportController extends Controller
{
    public function __invoke(Request $request, Plugin $plugin, PluginRun $run)
    {
        abort_unless($request->user()?->canUseTools(), 403);
        abort_unless($run->extension_plugin_id === $plugin->id, 404);
        abort_unless($run->canBeViewedBy($request->user()), 403);

        $reportPath = data_get($run->result, 'data.report.path');
        $reportFilename = data_get($run->result, 'data.report.filename')
            ?? "plugin-run-{$run->id}.csv";

        if (! $reportPath || ! Storage::disk('local')->exists($reportPath)) {
            abort(404);
        }

        $stream = fopen(Storage::disk('local')->path($reportPath), 'r');

        return response()->stream(
            function () use ($stream): void {
                while (! feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }

                fclose($stream);
            },
            200,
            [
                'Content-Disposition' => 'attachment; filename="'.$reportFilename.'"',
                'Content-Type' => 'text/csv',
            ]
        );
    }
}
