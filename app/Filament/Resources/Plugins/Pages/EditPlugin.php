<?php

namespace App\Filament\Resources\Plugins\Pages;

use App\Filament\Resources\Plugins\PluginResource;
use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlugin extends EditRecord
{
    protected static string $resource = PluginResource::class;

    public function mount(int|string $record): void
    {
        app(PluginManager::class)->recoverStaleRuns();

        parent::mount($record);
    }

    public function getSubheading(): ?string
    {
        return 'Monitor this plugin, queue one-off jobs, and tune the defaults that automation will reuse.';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(auth()->user()?->canManagePlugins(), 403);

        /** @var Plugin $record */
        app(PluginManager::class)->updateSettings($record, $data['settings'] ?? []);

        return $record->fresh();
    }

    protected function getHeaderActions(): array
    {
        $record = $this->record;
        $canManagePlugins = auth()->user()?->canManagePlugins() ?? false;

        // Plugin-defined actions (e.g. health_check) — shown as primary buttons
        $pluginActions = [];
        foreach ($record->actions ?? [] as $pluginAction) {
            $actionId = $pluginAction['id'] ?? null;
            if (! $actionId || ($pluginAction['hidden'] ?? false)) {
                continue;
            }

            $pluginActions[] = Action::make('plugin_action_'.$actionId)
                ->label($pluginAction['label'] ?? ucfirst($actionId))
                ->icon($pluginAction['icon'] ?? 'heroicon-o-play')
                ->color(($pluginAction['destructive'] ?? false) ? 'danger' : 'primary')
                ->disabled(fn () => ! $this->record->enabled || ! $this->record->isInstalled() || $this->record->validation_status !== 'valid' || ! $this->record->isTrusted() || ! $this->record->hasVerifiedIntegrity())
                ->requiresConfirmation((bool) ($pluginAction['requires_confirmation'] ?? false))
                ->schema(app(PluginSchemaMapper::class)->actionComponents($record, $actionId))
                ->action(function (array $data) use ($record, $pluginAction, $actionId): void {
                    dispatch(new ExecutePluginInvocation(
                        pluginId: $record->id,
                        invocationType: 'action',
                        name: $actionId,
                        payload: $data,
                        options: [
                            'trigger' => 'manual',
                            'dry_run' => (bool) ($pluginAction['dry_run'] ?? false),
                            'user_id' => auth()->id(),
                        ],
                    ));

                    Notification::make()
                        ->success()
                        ->title(($pluginAction['label'] ?? ucfirst($actionId)).' queued')
                        ->body('The plugin action is running in the background. Watch the Live Activity and Run History tabs for progress and results.')
                        ->send();
                });
        }

        return [
            // Enable / Disable toggle — primary lifecycle action, always visible
            Action::make('enable')
                ->label('Enable')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $canManagePlugins)
                ->hidden(fn () => $this->record->enabled || ! $this->record->isInstalled())
                ->disabled(fn () => $this->record->validation_status !== 'valid' || ! $this->record->available || ! $this->record->isTrusted() || ! $this->record->hasVerifiedIntegrity())
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['enabled' => true]);
                    Notification::make()->success()->title('Plugin enabled')->send();
                    $this->refreshFormData(['enabled']);
                }),
            Action::make('disable')
                ->label('Disable')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->visible(fn () => $canManagePlugins)
                ->hidden(fn () => ! $this->record->enabled || ! $this->record->isInstalled())
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['enabled' => false]);
                    Notification::make()->success()->title('Plugin disabled')->send();
                    $this->refreshFormData(['enabled']);
                }),

            // Plugin-defined actions
            ActionGroup::make([...$pluginActions])->label('Actions')->icon('heroicon-o-rocket-launch')->button(),

            // Security & trust group
            ActionGroup::make([
                Action::make('validate')
                    ->label('Validate')
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn () => $canManagePlugins)
                    ->action(function () use ($record): void {
                        $plugin = app(PluginManager::class)->validate($record);

                        Notification::make()
                            ->title('Validation completed')
                            ->body($plugin->validation_status === 'valid'
                                ? 'Plugin manifest and class contract are valid.'
                                : implode("\n", $plugin->validation_errors ?? ['Plugin validation failed.']))
                            ->color($plugin->validation_status === 'valid' ? 'success' : 'danger')
                            ->send();

                        $this->refreshFormData(['validation_status', 'validation_errors_json']);
                    }),
                Action::make('verify_integrity')
                    ->label('Verify Integrity')
                    ->icon('heroicon-o-finger-print')
                    ->visible(fn () => $canManagePlugins)
                    ->action(function () use ($record): void {
                        $plugin = app(PluginManager::class)->verifyIntegrity($record);

                        Notification::make()
                            ->title('Integrity refreshed')
                            ->body("Integrity is now [{$plugin->integrity_status}] and trust is [{$plugin->trust_state}].")
                            ->color($plugin->hasVerifiedIntegrity() ? 'success' : 'warning')
                            ->send();

                        $this->refreshFormData(['integrity_status', 'trust_state', 'enabled']);
                    }),
                Action::make('trust')
                    ->label('Trust Plugin')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => $this->record->isTrusted() && $this->record->hasVerifiedIntegrity())
                    ->disabled(fn () => $this->record->validation_status !== 'valid' || ! $this->record->available || ! $this->record->isInstalled())
                    ->requiresConfirmation()
                    ->modalDescription('Trust pins the current plugin hashes, verifies integrity, and applies any declared plugin-owned schema through the host.')
                    ->action(function () use ($record): void {
                        try {
                            app(PluginManager::class)->trust($record, auth()->id());

                            Notification::make()
                                ->success()
                                ->title('Plugin trusted')
                                ->body('The plugin is now trusted. You can enable it when you are ready.')
                                ->send();

                            $this->refreshFormData(['trust_state', 'integrity_status', 'trusted_at']);
                        } catch (\RuntimeException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Trust blocked')
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
                Action::make('block')
                    ->label('Block Plugin')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => $this->record->isBlocked())
                    ->requiresConfirmation()
                    ->modalDescription('Blocking disables the plugin immediately and prevents execution until an administrator trusts it again.')
                    ->action(function () use ($record): void {
                        app(PluginManager::class)->block($record, userId: auth()->id());

                        Notification::make()
                            ->success()
                            ->title('Plugin blocked')
                            ->body('Execution is now disabled until an administrator reviews and trusts this plugin again.')
                            ->send();

                        $this->refreshFormData(['enabled', 'trust_state', 'integrity_status']);
                    }),
            ])->label('Security')->icon('heroicon-o-lock-closed')->button(),

            // Lifecycle management group
            ActionGroup::make([
                Action::make('stage_review')
                    ->label('Stage Current Files For Review')
                    ->icon('heroicon-o-archive-box')
                    ->visible(fn () => $canManagePlugins && filled($this->record->path) && $this->record->available)
                    ->action(function () use ($record): void {
                        $review = app(PluginManager::class)->stageDirectoryReview(
                            (string) $record->path,
                            auth()->id(),
                            $record->source_type === 'local_dev',
                        );

                        Notification::make()
                            ->success()
                            ->title('Plugin install staged')
                            ->body("Plugin install #{$review->id} is ready for ClamAV scan and approval in Plugin Installs.")
                            ->send();
                    }),
                Action::make('reinstall')
                    ->label('Reinstall')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => $this->record->isInstalled())
                    ->disabled(fn () => ! $this->record->available)
                    ->requiresConfirmation()
                    ->modalDescription('Reinstalling makes this plugin eligible to run again. Saved settings stay in place unless you previously purged plugin-owned data.')
                    ->action(function () use ($record): void {
                        $plugin = app(PluginManager::class)->reinstall($record);

                        Notification::make()
                            ->success()
                            ->title('Plugin reinstalled')
                            ->body($plugin->validation_status === 'valid'
                                ? 'The plugin can be enabled again when you are ready.'
                                : 'The plugin was reinstalled, but validation still needs attention before it can run.')
                            ->send();

                        $this->refreshFormData(['installation_status', 'validation_status', 'validation_errors_json', 'uninstalled_at']);
                    }),
                Action::make('uninstall')
                    ->label('Uninstall Plugin')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => ! $this->record->isInstalled())
                    ->requiresConfirmation()
                    ->modalHeading('Uninstall plugin')
                    ->modalDescription('Uninstalling disables the plugin immediately. Preserve keeps plugin-owned data for later reinstall. Purge deletes only the plugin-owned tables and storage paths declared in the manifest. If a run is still active, the system will request cancellation before any purge is allowed.')
                    ->schema([
                        Select::make('cleanup_mode')
                            ->label('Data cleanup')
                            ->options([
                                'preserve' => 'Preserve plugin-owned data',
                                'purge' => 'Purge plugin-owned data',
                            ])
                            ->default(fn () => $record->defaultCleanupMode())
                            ->required()
                            ->helperText('Disable is reversible. Uninstall changes the lifecycle state and optionally purges plugin-owned tables, files, and report directories.'),
                    ])
                    ->action(function (array $data) use ($record): void {
                        try {
                            $plugin = app(PluginManager::class)->uninstall(
                                $record,
                                $data['cleanup_mode'] ?? 'preserve',
                                auth()->id(),
                            );

                            Notification::make()
                                ->success()
                                ->title('Plugin uninstalled')
                                ->body(($data['cleanup_mode'] ?? 'preserve') === 'purge'
                                    ? 'The plugin was disabled and its declared plugin-owned data was purged.'
                                    : 'The plugin was disabled and marked uninstalled. Plugin-owned data was preserved for a possible reinstall.')
                                ->send();

                            $this->refreshFormData(['enabled', 'installation_status', 'last_cleanup_mode', 'uninstalled_at']);
                        } catch (\RuntimeException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Uninstall blocked')
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
                DeleteAction::make()
                    ->label('Forget Registry Record')
                    ->visible(fn () => $canManagePlugins)
                    ->disabled(fn () => $this->record->hasActiveRuns())
                    ->modalDescription('This deletes the registry row, saved plugin settings, and recorded run history. It does not uninstall the local plugin files and does not clean plugin-owned data. Discovery will register the plugin again if its folder still exists.')
                    ->successRedirectUrl(PluginResource::getUrl()),
            ])->label('Manage')->icon('heroicon-o-cog-6-tooth')->button(),
        ];
    }
}
