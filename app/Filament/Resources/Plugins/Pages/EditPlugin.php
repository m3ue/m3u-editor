<?php

namespace App\Filament\Resources\Plugins\Pages;

use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Filament\Resources\Plugins\PluginResource;
use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUpdateChecker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                        ->body(__('The plugin action is running in the background. Watch the Live Activity and Run History tabs for progress and results.'))
                        ->send();
                });
        }

        return [
            // Enable / Disable toggle — primary lifecycle action, always visible
            Action::make('enable')
                ->label(__('Enable'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $canManagePlugins)
                ->hidden(fn () => $this->record->enabled || ! $this->record->isInstalled())
                ->disabled(fn () => $this->record->validation_status !== 'valid' || ! $this->record->available || ! $this->record->isTrusted() || ! $this->record->hasVerifiedIntegrity())
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['enabled' => true]);
                    Notification::make()->success()->title(__('Plugin enabled'))->send();
                    $this->refreshFormData(['enabled']);
                }),
            Action::make('disable')
                ->label(__('Disable'))
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->visible(fn () => $canManagePlugins)
                ->hidden(fn () => ! $this->record->enabled || ! $this->record->isInstalled())
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['enabled' => false]);
                    Notification::make()->success()->title(__('Plugin disabled'))->send();
                    $this->refreshFormData(['enabled']);
                }),

            // Plugin-defined actions
            ActionGroup::make([...$pluginActions])->label(__('Actions'))->icon('heroicon-o-rocket-launch')->button(),

            // Security & trust group
            ActionGroup::make([
                Action::make('validate')
                    ->label(__('Validate'))
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn () => $canManagePlugins)
                    ->action(function () use ($record): void {
                        $plugin = app(PluginManager::class)->validate($record);

                        Notification::make()
                            ->title(__('Validation completed'))
                            ->body($plugin->validation_status === 'valid'
                                ? 'Plugin manifest and class contract are valid.'
                                : implode("\n", $plugin->validation_errors ?? ['Plugin validation failed.']))
                            ->color($plugin->validation_status === 'valid' ? 'success' : 'danger')
                            ->send();

                        $this->refreshFormData(['validation_status', 'validation_errors_json']);
                    }),
                Action::make('verify_integrity')
                    ->label(__('Check for File Changes'))
                    ->icon('heroicon-o-finger-print')
                    ->visible(fn () => $canManagePlugins)
                    ->action(function () use ($record): void {
                        $plugin = app(PluginManager::class)->verifyIntegrity($record);

                        Notification::make()
                            ->title(__('File check complete'))
                            ->body($plugin->hasVerifiedIntegrity()
                                ? 'No changes detected — plugin files match the trusted version.'
                                : "Files have been modified. Trust status is now [{$plugin->trust_state}].")
                            ->color($plugin->hasVerifiedIntegrity() ? 'success' : 'warning')
                            ->send();

                        $this->refreshFormData(['integrity_status', 'trust_state', 'enabled']);
                    }),
                Action::make('trust')
                    ->label(__('Trust Plugin'))
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => $this->record->isTrusted() && $this->record->hasVerifiedIntegrity())
                    ->disabled(fn () => $this->record->validation_status !== 'valid' || ! $this->record->available || ! $this->record->isInstalled())
                    ->requiresConfirmation()
                    ->modalDescription(__('Trusting a plugin locks its current files as the approved version and sets up any database tables or storage the plugin needs.'))
                    ->action(function () use ($record): void {
                        try {
                            app(PluginManager::class)->trust($record, auth()->id());

                            Notification::make()
                                ->success()
                                ->title(__('Plugin trusted'))
                                ->body(__('The plugin is now trusted. You can enable it when you are ready.'))
                                ->send();

                            $this->refreshFormData(['trust_state', 'integrity_status', 'trusted_at']);
                        } catch (\RuntimeException $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Trust blocked'))
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
                Action::make('block')
                    ->label(__('Block Plugin'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => $this->record->isBlocked())
                    ->requiresConfirmation()
                    ->modalDescription(__('Blocking disables the plugin immediately and prevents execution until an administrator trusts it again.'))
                    ->action(function () use ($record): void {
                        app(PluginManager::class)->block($record, userId: auth()->id());

                        Notification::make()
                            ->success()
                            ->title(__('Plugin blocked'))
                            ->body(__('Execution is now disabled until an administrator reviews and trusts this plugin again.'))
                            ->send();

                        $this->refreshFormData(['enabled', 'trust_state', 'integrity_status']);
                    }),
            ])->label(__('Security'))->icon('heroicon-o-lock-closed')->button(),

            // Lifecycle management group
            ActionGroup::make([
                Action::make('check_for_updates')
                    ->label(__('Check for Updates'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn () => $canManagePlugins && filled($this->record->repository))
                    ->action(function () use ($record): void {
                        $result = app(PluginUpdateChecker::class)->check($record);

                        if ($result['error']) {
                            Notification::make()
                                ->warning()
                                ->title(__('Update check failed'))
                                ->body($result['error'])
                                ->send();
                        } elseif ($result['update_available']) {
                            Notification::make()
                                ->info()
                                ->title(__('Update available'))
                                ->body(__('Version :version is available (current: :current).', [
                                    'version' => $result['latest'],
                                    'current' => $result['current'],
                                ]))
                                ->send();
                        } else {
                            Notification::make()
                                ->success()
                                ->title(__('Up to date'))
                                ->body(__('This plugin is already on the latest version.'))
                                ->send();
                        }

                        $this->refreshFormData(['latest_version', 'last_update_check_at']);
                    }),
                Action::make('stage_update')
                    ->label(fn () => __('Update to :version', ['version' => $this->record->latest_version]))
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('success')
                    ->visible(fn () => $canManagePlugins && $this->record->hasUpdateAvailable() && filled($this->record->latest_release_url))
                    ->requiresConfirmation()
                    ->modalHeading(__('Stage plugin update'))
                    ->modalDescription(fn () => filled($this->record->latest_release_sha256)
                        ? __('This will download the latest release and stage it for review. The checksum will be verified automatically.')
                        : __('No checksum was found for this release. Please provide the SHA-256 hash to verify the download.'))
                    ->schema(fn () => filled($this->record->latest_release_sha256)
                        ? []
                        : [
                            TextInput::make('sha256')
                                ->label(__('SHA-256 Checksum'))
                                ->placeholder('e.g. a1b2c3d4...')
                                ->required()
                                ->length(64)
                                ->helperText(__('Copy the file hash from the GitHub release page.')),
                        ])
                    ->action(function (array $data) use ($record): void {
                        try {
                            $sha256 = $data['sha256'] ?? $record->latest_release_sha256;
                            if (! $sha256) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Missing checksum'))
                                    ->body(__('A SHA-256 checksum is required to stage this update.'))
                                    ->send();

                                return;
                            }

                            $review = app(PluginManager::class)->stageGithubReleaseReview(
                                $record->latest_release_url,
                                $sha256,
                                auth()->id(),
                            );

                            Notification::make()
                                ->success()
                                ->title(__('Update staged for review'))
                                ->body(__('Review #:id is ready - check Plugin Installs to scan and approve it.', ['id' => $review->id]))
                                ->actions([
                                    Action::make('view_review')
                                        ->label(__('View Review'))
                                        ->url(PluginInstallReviewResource::getUrl('edit', ['record' => $review->id])),
                                ])
                                ->send();
                        } catch (\RuntimeException $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Update staging failed'))
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
                Action::make('stage_review')
                    ->label(__('Submit for Review'))
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->color('warning')
                    ->modalDescription(__('Creates a new security review of this plugin\\\'s current files. Use this after updating plugin files on disk or after a failed install to re-trigger the review process without re-uploading.'))
                    ->modalSubmitActionLabel(__('Submit for review'))
                    ->visible(fn () => $canManagePlugins && filled($this->record->path) && $this->record->available)
                    ->action(function () use ($record): void {
                        $review = app(PluginManager::class)->stageDirectoryReview(
                            (string) $record->path,
                            auth()->id(),
                            $record->source_type === 'local_dev',
                        );

                        Notification::make()
                            ->success()
                            ->title(__('Security review created'))
                            ->body(__('Review #:id is queued — check Plugin Installs to scan and approve it.', ['id' => $review->id]))
                            ->send();
                    }),
                Action::make('reinstall')
                    ->label(__('Reinstall'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => $this->record->isInstalled())
                    ->disabled(fn () => ! $this->record->available)
                    ->requiresConfirmation()
                    ->modalDescription(__('Reinstalling makes this plugin eligible to run again. Settings are preserved unless you deleted its data during uninstall.'))
                    ->action(function () use ($record): void {
                        $plugin = app(PluginManager::class)->reinstall($record);

                        Notification::make()
                            ->success()
                            ->title(__('Plugin reinstalled'))
                            ->body($plugin->validation_status === 'valid'
                                ? 'The plugin can be enabled again when you are ready.'
                                : 'The plugin was reinstalled, but validation still needs attention before it can run.')
                            ->send();

                        $this->refreshFormData(['installation_status', 'validation_status', 'validation_errors_json', 'uninstalled_at']);
                    }),
                Action::make('uninstall')
                    ->label(__('Uninstall Plugin'))
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->visible(fn () => $canManagePlugins)
                    ->hidden(fn () => ! $this->record->isInstalled())
                    ->requiresConfirmation()
                    ->modalHeading(__('Uninstall plugin'))
                    ->modalDescription(__('Uninstalling disables the plugin immediately. You can keep the plugin\\\'s data for a future reinstall, or delete everything it created. Active jobs will be cancelled first.'))
                    ->schema([
                        Select::make('cleanup_mode')
                            ->label(__('What to do with plugin data'))
                            ->options([
                                'preserve' => 'Keep database tables and files (can reinstall later)',
                                'purge' => 'Delete database tables and files permanently',
                            ])
                            ->default(fn () => $record->defaultCleanupMode())
                            ->required()
                            ->helperText(__('Disabling is reversible. Uninstalling changes the plugin\\\'s status and optionally removes its database tables, files, and reports.')),
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
                                ->title(__('Plugin uninstalled'))
                                ->body(($data['cleanup_mode'] ?? 'preserve') === 'purge'
                                    ? 'Plugin disabled and its database tables and files have been deleted.'
                                    : 'Plugin disabled and marked as uninstalled. Data was kept for a possible reinstall.')
                                ->send();

                            $this->refreshFormData(['enabled', 'installation_status', 'last_cleanup_mode', 'uninstalled_at']);
                        } catch (\RuntimeException $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Uninstall blocked'))
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),

                Action::make('delete_from_disk')
                    ->label(__('Delete Plugin'))
                    ->icon('heroicon-s-trash')
                    ->color('danger')
                    ->visible(fn () => $canManagePlugins && ! $this->record->isBundled())
                    ->disabled(fn () => $this->record->hasActiveRuns())
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete plugin from disk'))
                    ->modalDescription(__('Permanently removes the plugin files from the server and deletes its registry record, settings, and run history. This cannot be undone.'))
                    ->modalSubmitActionLabel(__('Delete permanently'))
                    ->schema([
                        Select::make('cleanup_mode')
                            ->label(__('What to do with plugin data'))
                            ->options([
                                'preserve' => 'Keep database tables and files created by the plugin',
                                'purge' => 'Delete database tables and files created by the plugin',
                            ])
                            ->default(fn () => $record->defaultCleanupMode())
                            ->required()
                            ->helperText(__('Choose whether to retain or remove any database tables and storage files the plugin created during its lifetime.')),
                    ])
                    ->action(function (array $data) use ($record): void {
                        try {
                            app(PluginManager::class)->deleteFromDisk(
                                $record,
                                $data['cleanup_mode'] ?? 'preserve',
                                auth()->id(),
                            );

                            Notification::make()
                                ->success()
                                ->title(__('Plugin deleted'))
                                ->body(__('The plugin files have been removed from disk and its registry record has been deleted.'))
                                ->send();

                            $this->redirect(PluginResource::getUrl());
                        } catch (\RuntimeException $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Delete blocked'))
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
            ])->label(__('Manage'))->icon('heroicon-o-cog-6-tooth')->button(),
        ];
    }
}
