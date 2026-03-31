<?php

namespace App\Filament\Resources\Plugins\Pages;

use App\Filament\Resources\Plugins\PluginResource;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ViewPluginRun extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PluginResource::class;

    protected string $view = 'filament.resources.extension-plugins.pages.view-plugin-run';

    public PluginRun $runRecord;

    public Collection $logs;

    public function mount(int|string $record, int|string $run): void
    {
        app(PluginManager::class)->recoverStaleRuns();

        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        /** @var Plugin $plugin */
        $plugin = $this->getRecord();
        $runRecord = $plugin->runs()
            ->with(['plugin', 'user'])
            ->find($run);

        if (! $runRecord) {
            throw (new ModelNotFoundException)->setModel(PluginRun::class, [$run]);
        }

        abort_unless($runRecord->canBeViewedBy(auth()->user()), 403);

        $this->runRecord = $runRecord;
        $this->logs = $runRecord->logs()->latest()->limit(150)->get()->reverse()->values();
    }

    public function getTitle(): string
    {
        $label = $this->runRecord->action ?: $this->runRecord->hook ?: 'Plugin Run';

        return Str::headline($label).' #'.$this->runRecord->id;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_report')
                ->label(__('Download Report'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => filled($this->reportPath()) && Storage::disk('local')->exists($this->reportPath()))
                ->url(fn (): string => route('extension-plugins.runs.report', [
                    'plugin' => $this->getRecord(),
                    'run' => $this->runRecord,
                ])),
            Action::make('stop_run')
                ->label(__('Stop Run'))
                ->icon('heroicon-o-stop-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->runRecord->status === 'running')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(PluginManager::class)->requestCancellation($this->runRecord, auth()->id());
                    $this->refreshRunState();

                    Notification::make()
                        ->success()
                        ->title(__('Cancellation requested'))
                        ->body(__('The worker will stop the run at the next safe checkpoint.'))
                        ->send();
                }),
            Action::make('resume_run')
                ->label(__('Resume Run'))
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->runRecord->status, ['cancelled', 'stale', 'failed'], true))
                ->action(function (): void {
                    app(PluginManager::class)->resumeRun($this->runRecord, auth()->id());
                    $this->refreshRunState();

                    Notification::make()
                        ->success()
                        ->title(__('Run resumed'))
                        ->body(__('The run was queued again and will continue from the last saved checkpoint when possible.'))
                        ->send();
                }),
            Action::make('back_to_plugin')
                ->label(__('Back to Plugin'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => PluginResource::getUrl('edit', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    public function reportPath(): ?string
    {
        $path = data_get($this->runRecord->result, 'data.report.path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function reportFilename(): string
    {
        $filename = data_get($this->runRecord->result, 'data.report.filename');

        return is_string($filename) && $filename !== ''
            ? $filename
            : "plugin-run-{$this->runRecord->id}.dat";
    }

    private function refreshRunState(): void
    {
        $this->runRecord = $this->runRecord->fresh(['plugin', 'user']);
        $this->logs = $this->runRecord->logs()->latest()->limit(150)->get()->reverse()->values();
    }
}
