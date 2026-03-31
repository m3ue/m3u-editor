<?php

namespace App\Filament\Actions;

use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Models\PluginInstallReview;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Throwable;

class PluginInstallActions
{
    /**
     * Build the shared action for refreshing the plugin registry from disk.
     */
    public static function discover(): Action
    {
        return Action::make('discover')
            ->label(__('Discover Plugins'))
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
            ->action(function (): void {
                $plugins = app(PluginManager::class)->discover();

                Notification::make()
                    ->success()
                    ->title(__('Plugin discovery completed'))
                    ->body('Found and loaded '.count($plugins).' plugin(s).')
                    ->send();
            });
    }

    /**
     * Build the shared navigation action for the plugin install queue.
     */
    public static function pluginInstallsLink(): Action
    {
        return Action::make('plugin_installs')
            ->label(__('Plugin Installs'))
            ->icon('heroicon-o-archive-box')
            ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
            ->url(PluginInstallReviewResource::getUrl());
    }

    /**
     * Build the shared staging actions used by the install queue and dashboard.
     *
     * @return array<int, Action>
     */
    public static function staging(): array
    {
        return [
            ActionGroup::make([
                Action::make('stage_directory')
                    ->label(__('Stage Local Plugin'))
                    ->icon('heroicon-o-folder-open')
                    ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                    ->schema([
                        TextInput::make('path')
                            ->label(__('Plugin Directory Path'))
                            ->required()
                            ->helperText(__('Enter a path on your server. This reads files from the server directly, not from your browser.')),
                        Toggle::make('dev_source')
                            ->label(__('This is a development/testing plugin'))
                            ->default(false)
                            ->helperText(__('Only check this for plugins you\\\'re actively developing locally. Don\\\'t use for production installs.')),
                    ])
                    ->action(function (array $data): void {
                        self::runStagingAction(
                            callback: fn () => app(PluginManager::class)->stageDirectoryReview(
                                (string) $data['path'],
                                auth()->id(),
                                (bool) ($data['dev_source'] ?? false),
                            ),
                            successTitle: 'Plugin install staged',
                            failureTitle: 'Plugin staging failed',
                        );
                    }),
                Action::make('upload_archive')
                    ->label(__('Upload Plugin Archive'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                    ->schema([
                        FileUpload::make('archive_upload')
                            ->label(__('Plugin Archive'))
                            ->required()
                            ->disk('local')
                            ->visibility('private')
                            ->directory((string) config('plugins.upload_directory', 'plugin-review-uploads'))
                            ->moveFiles()
                            ->preserveFilenames()
                            ->acceptedFileTypes([
                                'application/zip',
                                'application/x-zip-compressed',
                                'application/x-compressed',
                                'multipart/x-zip',
                                'application/x-tar',
                                'application/gzip',
                                'application/x-gzip',
                                'application/x-gtar',
                                'application/tar+gzip',
                                'application/octet-stream',
                            ])
                            ->maxSize((int) ceil(((int) config('plugins.archive_limits.max_archive_bytes', 50 * 1024 * 1024)) / 1024))
                            ->helperText(config('plugins.clamav.driver', 'fake') === 'fake'
                                ? 'Upload a plugin zip, tar, or tar.gz archive. The server will stage and validate it through plugin installs.'
                                : 'Upload a plugin zip, tar, or tar.gz archive. The server will stage, validate, and scan it through plugin installs.'),
                    ])
                    ->action(function (array $data): void {
                        self::runStagingAction(
                            callback: fn () => app(PluginManager::class)->stageUploadedArchiveReview(
                                (string) $data['archive_upload'],
                                auth()->id(),
                            ),
                            successTitle: 'Uploaded plugin archive staged',
                            failureTitle: 'Plugin upload failed',
                        );
                    }),
                Action::make('stage_archive')
                    ->label(__('Stage Plugin Archive'))
                    ->icon('heroicon-o-archive-box')
                    ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                    ->schema([
                        TextInput::make('archive')
                            ->label(__('Archive Path'))
                            ->required()
                            ->helperText(__('Enter the archive path on your server. This reads the file directly, not from your browser.')),
                    ])
                    ->action(function (array $data): void {
                        self::runStagingAction(
                            callback: fn () => app(PluginManager::class)->stageArchiveReview(
                                (string) $data['archive'],
                                auth()->id(),
                            ),
                            successTitle: 'Plugin archive staged',
                            failureTitle: 'Plugin archive staging failed',
                        );
                    }),
                Action::make('stage_github_release')
                    ->label(__('Stage GitHub Release'))
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                    ->schema([
                        TextInput::make('url')
                            ->label(__('Release Asset URL'))
                            ->required()
                            ->helperText(__('Use the GitHub release asset URL from the published release.')),
                        TextInput::make('sha256')
                            ->label(__('Security Hash (SHA-256)'))
                            ->required()
                            ->helperText(__('Copy the file hash from the GitHub release page to verify the download hasn\\\'t been tampered with.')),
                    ])
                    ->action(function (array $data): void {
                        self::runStagingAction(
                            callback: fn () => app(PluginManager::class)->stageGithubReleaseReview(
                                (string) $data['url'],
                                (string) $data['sha256'],
                                auth()->id(),
                            ),
                            successTitle: 'GitHub release staged',
                            failureTitle: 'GitHub release staging failed',
                        );
                    }),
            ])->label(__('Actions'))->button(),
        ];
    }

    /**
     * Execute a plugin staging action and convert staging failures into notifications.
     *
     * @param  callable(): PluginInstallReview  $callback
     */
    private static function runStagingAction(callable $callback, string $successTitle, string $failureTitle): void
    {
        try {
            $review = $callback();

            Notification::make()
                ->success()
                ->title($successTitle)
                ->body(config('plugins.clamav.driver', 'fake') === 'fake'
                    ? "Review #{$review->id} is queued for approval."
                    : "Review #{$review->id} is queued for security scan and approval.")
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title($failureTitle)
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        }
    }
}
