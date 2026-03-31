<?php

namespace App\Filament\Resources\Plugins;

use App\Filament\Resources\Plugins\Pages\EditPlugin;
use App\Filament\Resources\Plugins\Pages\ListPlugins;
use App\Filament\Resources\Plugins\Pages\ViewPluginRun;
use App\Filament\Resources\Plugins\RelationManagers\LogsRelationManager;
use App\Filament\Resources\Plugins\RelationManagers\RunsRelationManager;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use App\Services\DateFormatService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PluginResource extends Resource
{
    protected static ?string $model = Plugin::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Plugin');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Plugins');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Plugins');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseTools();
    }

    public static function getNavigationLabel(): string
    {
        return __('Plugins');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('plugin_tabs')
                ->persistTabInQueryString()
                ->contained(false)
                ->columnSpanFull()
                ->tabs([
                    Tab::make(__('Overview'))
                        ->icon('heroicon-m-puzzle-piece')
                        ->schema([
                            Section::make(__('Overview'))
                                ->compact()
                                ->schema([
                                    Placeholder::make('hero_panel')
                                        ->hiddenLabel()
                                        ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::heroPanel($record))),
                                ]),
                            Section::make(__('Current Status'))
                                ->compact()
                                ->icon('heroicon-m-bolt')
                                ->collapsed()
                                ->columns(3)
                                ->schema([
                                    Placeholder::make('run_posture')
                                        ->hiddenLabel()
                                        ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::runPostureCard($record))),
                                    Placeholder::make('automation_snapshot')
                                        ->hiddenLabel()
                                        ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::automationCard($record))),
                                    Placeholder::make('next_step_snapshot')
                                        ->hiddenLabel()
                                        ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::nextStepCard($record))),
                                ]),
                            Section::make(__('Capability Map'))
                                ->compact()
                                ->icon('heroicon-m-chart-bar')
                                ->collapsed()
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            Placeholder::make('capabilities_display')
                                                ->hiddenLabel()
                                                ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::infoCard(__('Capabilities'), __('What this plugin can participate in inside the platform.'),
                                                    self::pillList(
                                                        collect($record?->capabilities ?? [])
                                                            ->map(fn (string $capability) => str($capability)->replace('_', __(' '))->headline())
                                                            ->all(),
                                                        'This plugin has not declared any capabilities yet.',
                                                    ),
                                                ))),
                                            Placeholder::make('actions_display')
                                                ->hiddenLabel()
                                                ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::infoCard(__('Available Actions'), __('Manual actions available from the page header.'),
                                                    self::availableActions($record),
                                                ))),
                                        ]),
                                    Grid::make(2)
                                        ->schema([
                                            Placeholder::make('hooks_display')
                                                ->hiddenLabel()
                                                ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::infoCard(__('Event Triggers'), __('Events that automatically run this plugin in the background.'),
                                                    self::pillList(
                                                        collect($record?->hooks ?? [])->all(),
                                                        'This plugin only runs when you trigger one of its header actions.',
                                                    ),
                                                ))),
                                            Placeholder::make('plugin_identity')
                                                ->hiddenLabel()
                                                ->content(fn (?Plugin $record): HtmlString => new HtmlString(self::infoCard(__('Plugin Info'), __('Version, source, and type of this plugin.'),
                                                    self::pluginIdentity($record),
                                                ))),
                                        ]),
                                ]),
                            Section::make(__('Technical Details'))
                                ->compact()
                                ->icon('heroicon-m-magnifying-glass')
                                ->collapsible()
                                ->collapsed()
                                ->schema([
                                    Grid::make(2)
                                        ->schema([
                                            TextInput::make('validation_status')
                                                ->disabled(),
                                            TextInput::make('source_type')
                                                ->disabled(),
                                            TextInput::make('path')
                                                ->disabled()
                                                ->columnSpanFull(),
                                            TextInput::make('class_name')
                                                ->disabled()
                                                ->columnSpanFull(),
                                        ]),
                                    Textarea::make('validation_errors_json')
                                        ->label(__('Validation Errors'))
                                        ->disabled()
                                        ->rows(6)
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn (?Plugin $record) => json_encode($record?->validation_errors ?? [], JSON_PRETTY_PRINT)),
                                ]),
                        ]),
                    Tab::make(__('Settings'))
                        ->icon('heroicon-m-cog-6-tooth')
                        ->visible(fn (): bool => auth()->user()?->canManagePlugins() ?? false)
                        ->schema([
                            Section::make(__('Settings'))
                                ->description(__('These settings are used by hook-triggered runs, scheduled runs, and as defaults for manual actions.'))
                                ->visible(fn (?Plugin $record) => filled($record?->settings_schema))
                                ->schema(fn (?Plugin $record) => app(PluginSchemaMapper::class)->settingsComponents($record)),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('plugin_id')
                    ->label(__('ID'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->sortable(),
                TextColumn::make('validation_status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'valid' => 'success',
                        'invalid' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('trust_state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline($state ?: 'pending_review'))
                    ->color(fn (?string $state) => match ($state) {
                        'trusted' => 'success',
                        'blocked' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('integrity_status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Str::headline($state ?: 'unknown'))
                    ->color(fn (?string $state) => match ($state) {
                        'verified' => 'success',
                        'changed' => 'danger',
                        'missing' => 'danger',
                        default => 'warning',
                    }),
                IconColumn::make('available')
                    ->boolean(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('installation_status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'installed' ? 'success' : 'gray'),
                TextColumn::make('last_validated_at')
                    ->since()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->size('sm'),
                Action::make('toggle_enabled')
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->tooltip(fn (Plugin $record) => $record->enabled ? 'Disable this plugin' : 'Enable this plugin')
                    ->label(fn (Plugin $record) => $record->enabled ? 'Disable' : 'Enable')
                    ->color(fn (Plugin $record) => $record->enabled ? 'warning' : 'success')
                    ->icon(fn (Plugin $record) => $record->enabled ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->modalIcon(fn (Plugin $record) => $record->enabled ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                    ->visible(fn (Plugin $record) => $record->isTrusted() && $record->isInstalled() && ! $record->isBlocked())
                    ->requiresConfirmation(fn (Plugin $record) => $record->enabled)
                    ->modalHeading(fn (Plugin $record) => $record->enabled ? "Disable {$record->name}?" : "Enable {$record->name}?")
                    ->modalDescription(fn (Plugin $record) => $record->enabled ? 'This will stop the plugin from responding to hooks and running actions.' : 'This will allow the plugin to respond to hooks and run actions.')
                    ->modalSubmitActionLabel(fn (Plugin $record) => $record->enabled ? 'Disable' : 'Enable')
                    ->modalWidth('sm')
                    ->action(fn (Plugin $record) => $record->update(['enabled' => ! $record->enabled])),
                Action::make('delete')
                    ->button()
                    ->size('sm')
                    ->hiddenLabel()
                    ->tooltip(fn (Plugin $record) => $record->isBundled() ? 'Cannot delete bundled plugin' : 'Delete this plugin')
                    ->label(__('Delete'))
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->visible(fn (Plugin $record) => auth()->user()?->canManagePlugins() ?? false)
                    ->disabled(fn (Plugin $record) => $record->hasActiveRuns() || $record->isBundled())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Plugin $record) => "Delete {$record->name}?")
                    ->modalDescription(__('Permanently removes the plugin files from the server and deletes its registry record, settings, and run history. This cannot be undone.'))
                    ->modalSubmitActionLabel(__('Delete permanently'))
                    ->schema([
                        Select::make('cleanup_mode')
                            ->label(__('What to do with plugin data'))
                            ->options([
                                'preserve' => 'Keep database tables and files created by the plugin',
                                'purge' => 'Delete database tables and files created by the plugin',
                            ])
                            ->default(fn (Plugin $record) => $record->defaultCleanupMode())
                            ->required(),
                    ])
                    ->action(function (array $data, Plugin $record): void {
                        if ($record->isBundled()) {
                            Notification::make()
                                ->danger()
                                ->title(__('Delete blocked'))
                                ->body(__('Bundled plugins cannot be deleted.'))
                                ->send();

                            return;
                        }
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
                        } catch (\RuntimeException $exception) {
                            Notification::make()
                                ->danger()
                                ->title(__('Delete blocked'))
                                ->body($exception->getMessage())
                                ->send();
                        }
                    }),
            ], RecordActionsPosition::BeforeCells);
    }

    public static function getRelations(): array
    {
        return [
            LogsRelationManager::class,
            RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlugins::route('/'),
            'edit' => EditPlugin::route('/{record}/edit'),
            'run' => ViewPluginRun::route('/{record}/runs/{run}'),
        ];
    }

    protected static function pluginStatusSnapshot(?Plugin $record): string
    {
        $enabled = $record?->enabled
            ? '<span class="text-success-600 dark:text-success-400 font-medium">Enabled</span>'
            : '<span class="text-gray-600 dark:text-gray-300 font-medium">Disabled</span>';
        $validation = match ($record?->validation_status) {
            'valid' => '<span class="text-success-600 dark:text-success-400 font-medium">Validated and ready</span>',
            'invalid' => '<span class="text-danger-600 dark:text-danger-400 font-medium">Validation failed</span>',
            default => '<span class="text-warning-600 dark:text-warning-400 font-medium">Not validated yet</span>',
        };

        return self::stackedLines([
            $enabled,
            $validation,
            'Trust: <span class="font-medium">'.e(Str::headline($record?->trust_state ?? 'pending_review')).'</span>',
            'Integrity: <span class="font-medium">'.e(Str::headline($record?->integrity_status ?? 'unknown')).'</span>',
            'Plugin ID: <span class="font-mono text-xs">'.e($record?->plugin_id ?? 'unknown').'</span>',
            'API: <span class="font-medium">'.e($record?->api_version ?? 'unknown').'</span>',
        ]);
    }

    protected static function heroPanel(?Plugin $record): string
    {
        if (! $record) {
            return self::mutedMessage(__('No plugin record loaded.'));
        }

        $focusRun = self::focusRun($record);
        $statusBadge = self::statusBadge($record);
        $runBadge = $focusRun ? self::runStatusBadge($focusRun) : self::mutedBadge(__('No runs yet'));
        $summary = $focusRun?->summary ?: __('Use the header actions to run this plugin once, then track the job from Live Activity and Run History.');
        $runLink = $focusRun
            ? '<a href="'.e(self::getUrl('run', ['record' => $record, 'run' => $focusRun])).'" class="inline-flex items-center rounded-full border border-primary-200 bg-white/90 px-3 py-1.5 text-xs font-semibold text-primary-700 shadow-sm hover:bg-primary-50 dark:border-primary-800 dark:bg-gray-900/80 dark:text-primary-300 dark:hover:bg-primary-950/60">Inspect this run</a>'
            : null;

        return '
            <div class="overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-gradient-to-br from-white via-primary-50/30 to-white shadow-sm dark:border-gray-800 dark:from-gray-950 dark:via-primary-950/20 dark:to-gray-950">
                <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(320px,0.85fr)] lg:px-8">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            '.$statusBadge.'
                            '.$runBadge.'
                            '.self::mutedBadge('API '.e($record->api_version ?? 'unknown')).'
                        </div>
                        <div class="space-y-2">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.22em] text-primary-600 dark:text-primary-300">Plugin control center</div>
                            <div class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">'.e($record->name).'</div>
                            <div class="max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">'.e($record->description ?: __('No description provided.')).'</div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            '.self::statPill(__('Implementation'), e($record->class_name ? class_basename($record->class_name) : 'Unknown class'), __('The plugin class discovered from the manifest.')).'
                            '.self::statPill(__('Availability'), $record->available ? 'Available on disk' : 'Missing from disk', __('Whether the plugin files are currently present.')).'
                            '.self::statPill(__('Trust posture'), e(Str::headline($record->trust_state ?? 'pending_review')).' · '.e(Str::headline($record->integrity_status ?? 'unknown')), __('Execution requires both admin trust and verified file integrity.')).'
                            '.self::statPill(__('Defaults'), e(self::targetSummary($record)), __('Saved targets used for manual defaults and automation.')).'
                        </div>
                    </div>
                    <div class="rounded-[1.5rem] border border-white/60 bg-white/80 p-5 shadow-sm backdrop-blur dark:border-gray-800 dark:bg-gray-900/85">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Trust & Security</div>
                        <div class="mt-3 text-sm leading-6 text-gray-700 dark:text-gray-200">'.e($summary).'</div>
                        <div class="mt-5 space-y-3">
                            '.self::stackedStat(__('Last validation'), app(DateFormatService::class)->format($record->last_validated_at, 'Not validated yet')).'
                            '.self::stackedStat(__('Focus run'), app(DateFormatService::class)->format($focusRun?->created_at, 'No runs queued yet')).'
                            '.self::stackedStat(__('Source'), e(Str::headline($record->source_type ?? 'local'))).'
                            '.self::stackedStat(__('Trusted by'), e(app(DateFormatService::class)->format($record->trusted_at, 'Awaiting admin review'))).'
                        </div>
                        <div class="mt-5 flex flex-wrap gap-2">
                            '.$runLink.'
                        </div>
                    </div>
                </div>
            </div>
        ';
    }

    protected static function latestRunSnapshot(?Plugin $record): string
    {
        $latestRun = self::latestRun($record);

        if (! $latestRun) {
            return self::mutedMessage(__('No plugin runs recorded yet. Use the header actions to run the plugin once.'));
        }

        return self::stackedLines([
            '<span class="font-medium">'.e(Str::headline($latestRun->status)).'</span>',
            $latestRun->started_at ? 'Started: '.e(app(DateFormatService::class)->format($latestRun->started_at)) : null,
            $latestRun->finished_at ? 'Finished: '.e(app(DateFormatService::class)->format($latestRun->finished_at)) : null,
            $latestRun->summary ? '<span class="text-sm">'.e($latestRun->summary).'</span>' : null,
            '<a href="'.e(self::getUrl('run', ['record' => $record, 'run' => $latestRun])).'" class="inline-flex items-center rounded-md border border-primary-200 bg-primary-50 px-2.5 py-1.5 text-xs font-medium text-primary-700 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300 dark:hover:bg-primary-900/60">Open run details</a>',
        ]);
    }

    protected static function runPostureCard(?Plugin $record): string
    {
        $latestRun = self::focusRun($record);

        if (! $latestRun) {
            return self::infoCard(__('Current Run'), __('The most recent plugin job and where to inspect it.'),
                self::mutedMessage(__('No runs recorded yet. Trigger a scan or apply action from the header to create the first job.')),
            );
        }

        $totals = collect(data_get($latestRun->result, 'data.totals', []))
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value, $key) => '<div class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-800 dark:bg-gray-950"><div class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e(Str::headline((string) $key)).'</div><div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">'.e((string) $value).'</div></div>')
            ->take(4)
            ->implode('');

        $body = '
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    '.self::runStatusBadge($latestRun).'
                    '.($latestRun->dry_run ? self::mutedBadge(__('Dry run')) : '').'
                </div>
                <div class="space-y-1">
                    <div class="text-sm font-semibold text-gray-950 dark:text-white">'.e(Str::headline($latestRun->action ?: $latestRun->hook ?: 'Run')).'</div>
                    <div class="text-sm text-gray-600 dark:text-gray-300">'.e($latestRun->summary ?: __('No summary has been written yet.')).'</div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    '.self::stackedStat(__('Queued'), app(DateFormatService::class)->format($latestRun->created_at, 'Unknown time')).'
                    '.self::stackedStat(__('Invocation'), e(Str::headline($latestRun->invocation_type))).'
                </div>
                '.($totals !== '' ? '<div class="grid gap-2 sm:grid-cols-2">'.$totals.'</div>' : '').'
                <div>
                    <a href="'.e(self::getUrl('run', ['record' => $record, 'run' => $latestRun])).'" class="inline-flex items-center rounded-full border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300 dark:hover:bg-primary-900/60">Inspect this run</a>
                </div>
            </div>
        ';

        return self::infoCard(__('Current Run'), __('The latest execution and its immediate outcome.'), $body);
    }

    protected static function automationCard(?Plugin $record): string
    {
        if (! $record) {
            return self::infoCard(__('Automation'), __('Defaults and schedules used by the plugin.'), self::mutedMessage(__('No plugin record loaded.')));
        }

        $autoScan = $record?->getSetting('auto_scan_on_epg_ready') ? __('Auto scan on EPG cache: enabled') : __('Auto scan on EPG cache: disabled');
        $scheduled = $record?->getSetting('schedule_enabled')
            ? 'Scheduled scans: '.(string) $record->getSetting('schedule_cron', 'enabled')
            : __('Scheduled scans: disabled');

        $body = '
            <div class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    '.self::stackedStat(__('Auto trigger'), e($autoScan)).'
                    '.self::stackedStat(__('Schedule'), e($scheduled)).'
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    '.self::stackedStat(__('Default playlist'), e(self::playlistLabel($record->getSetting('default_playlist_id')))).'
                    '.self::stackedStat(__('Default EPG'), e(self::epgLabel($record->getSetting('default_epg_id')))).'
                </div>
                <div class="text-xs leading-5 text-gray-500 dark:text-gray-400">These values prefill manual actions and are reused when hooks or schedules queue work automatically.</div>
            </div>
        ';

        return self::infoCard(__('Automation'), __('Defaults, schedules, and automatic entry points.'), $body);
    }

    protected static function nextStepCard(?Plugin $record): string
    {
        if (! $record) {
            return self::infoCard(__('Recommended Next Step'), __('What to do next.'), self::mutedMessage(__('No plugin record loaded.')));
        }

        $latestRun = self::latestRun($record);

        if ($record->validation_status !== 'valid') {
            $message = __('Validate the plugin before you enable it or queue any work. The system should treat this plugin as untrusted until the contract checks pass.');
        } elseif (! $record->isTrusted()) {
            $message = __('An administrator still needs to trust this plugin. Review the declared permissions, owned schema, and file integrity before enabling it.');
        } elseif (! $record->hasVerifiedIntegrity()) {
            $message = __('Integrity is no longer verified. Re-run integrity verification and trust review before allowing this plugin to execute again.');
        } elseif (! $record->enabled) {
            $message = __('The plugin is valid but disabled. Enable it first, then run a dry scan so you can inspect the output before applying repairs.');
        } elseif (! $latestRun) {
            $message = __('Queue a scan from the header to generate the first run. That will populate Live Activity, Run History, and the run detail screen.');
        } elseif ($latestRun->status === 'running') {
            $message = __('Open the current run and watch the activity stream. If the run stalls, inspect the payload to confirm the target playlist and EPG pair.');
        } elseif ($latestRun->status === 'failed') {
            $message = __('Review the failed run, check the activity stream for the error context, and correct the target playlist, EPG, or thresholds before trying again.');
        } else {
            $message = __('Use the last completed run as your baseline. If the candidate count looks right, queue an apply run or tighten the thresholds from the Settings tab.');
        }

        $body = '
            <div class="space-y-4">
                <div class="rounded-2xl border border-primary-200 bg-primary-50/70 px-4 py-4 text-sm leading-6 text-primary-900 dark:border-primary-800 dark:bg-primary-950/30 dark:text-primary-100">'.e($message).'</div>
                <div class="grid gap-3 sm:grid-cols-2">
                    '.self::stackedStat(__('Validation'), e(Str::headline($record->validation_status ?? 'pending'))).'
                    '.self::stackedStat(__('Trust'), e(Str::headline($record->trust_state ?? 'pending_review'))).'
                    '.self::stackedStat(__('Enabled'), $record->enabled ? __('Yes') : __('No')).'
                    '.self::stackedStat(__('Integrity'), e(Str::headline($record->integrity_status ?? 'unknown'))).'
                    '.self::stackedStat(__('Lifecycle'), e(Str::headline($record->installation_status ?? 'installed'))).'
                    '.self::stackedStat(__('Cleanup default'), e(Str::headline($record->defaultCleanupMode()))).'
                </div>
            </div>
        ';

        return self::infoCard(__('Recommended Next Step'), __('A recommendation based on the current plugin state.'), $body);
    }

    protected static function pluginIdentity(?Plugin $record): string
    {
        if (! $record) {
            return self::mutedMessage(__('No plugin record loaded.'));
        }

        return self::stackedLines([
            '<div class="text-base font-semibold text-gray-950 dark:text-white">'.e($record->name).'</div>',
            '<div class="text-sm text-gray-600 dark:text-gray-300">Version '.e($record->version).' · '.e($record->description ?: __('No description provided.')).'</div>',
            '<div class="text-xs text-gray-500 dark:text-gray-400">Class: '.e($record->class_name ?: 'Unknown').'</div>',
            '<div class="text-xs text-gray-500 dark:text-gray-400">Permissions: '.e(collect($record->permissions ?? [])->map(fn (string $permission) => Str::headline($permission))->implode(', ') ?: __('None declared')).'</div>',
            '<div class="text-xs text-gray-500 dark:text-gray-400">Lifecycle: disable pauses execution, uninstall changes lifecycle state, forget registry only removes the row.</div>',
            '<div class="text-xs text-gray-500 dark:text-gray-400">Declared ownership: '.e(self::ownershipSummary($record)).'</div>',
        ]);
    }

    protected static function availableActions(?Plugin $record): string
    {
        $actions = collect($record?->actions ?? [])
            ->map(function (array $action): string {
                $label = $action['label'] ?? Str::headline((string) ($action['id'] ?? 'Action'));
                $notes = collect([
                    ($action['dry_run'] ?? false) ? 'dry run' : null,
                    ($action['requires_confirmation'] ?? false) ? 'needs confirmation' : null,
                    ($action['destructive'] ?? false) ? 'destructive' : null,
                ])->filter()->implode(' · ');

                return '<div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-800">'.
                    '<div class="font-medium text-gray-950 dark:text-white">'.e($label).'</div>'.
                    '<div class="text-xs text-gray-500 dark:text-gray-400">'.e($notes !== '' ? $notes : 'manual action').'</div>'.
                    '</div>';
            })
            ->implode('');

        if ($actions === '') {
            return self::mutedMessage(__('No actions were declared for this plugin.'));
        }

        return '<div class="grid gap-2">'.$actions.'</div>';
    }

    protected static function pillList(array $items, string $emptyMessage): string
    {
        if ($items === []) {
            return self::mutedMessage($emptyMessage);
        }

        $pills = collect($items)
            ->map(fn (string $item) => '<span class="inline-flex items-center rounded-full border border-primary-200 bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300">'.e($item).'</span>')
            ->implode(' ');

        return '<div class="flex flex-wrap gap-2">'.$pills.'</div>';
    }

    protected static function infoCard(string $title, string $description, string $content): string
    {
        return '
            <div class="h-full rounded-[1.5rem] border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">'.e($title).'</div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">'.e($description).'</div>
                <div class="mt-4">'.$content.'</div>
            </div>
        ';
    }

    protected static function stackedLines(array $lines): string
    {
        $content = collect($lines)
            ->filter()
            ->map(fn (string $line) => '<div class="text-sm text-gray-700 dark:text-gray-200">'.$line.'</div>')
            ->implode('');

        return '<div class="space-y-2">'.$content.'</div>';
    }

    protected static function stackedStat(string $label, string $value): string
    {
        return '
            <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-950">
                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">'.e($label).'</div>
                <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">'.$value.'</div>
            </div>
        ';
    }

    protected static function statPill(string $label, string $value, string $hint): string
    {
        return '
            <div class="rounded-2xl border border-gray-200 bg-white/80 px-4 py-4 dark:border-gray-800 dark:bg-gray-900/80">
                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">'.e($label).'</div>
                <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">'.$value.'</div>
                <div class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">'.e($hint).'</div>
            </div>
        ';
    }

    protected static function statusBadge(Plugin $record): string
    {
        if (! $record->isInstalled()) {
            return '<span class="inline-flex items-center rounded-full border border-gray-200 bg-white/90 px-3 py-1.5 text-xs font-semibold text-gray-600 dark:border-gray-800 dark:bg-gray-900/80 dark:text-gray-300">Uninstalled</span>';
        }

        if ($record->isBlocked()) {
            return '<span class="inline-flex items-center rounded-full border border-danger-200 bg-danger-50 px-3 py-1.5 text-xs font-semibold text-danger-700 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300">Blocked</span>';
        }

        if (! $record->enabled) {
            return '<span class="inline-flex items-center rounded-full border border-gray-200 bg-white/90 px-3 py-1.5 text-xs font-semibold text-gray-600 dark:border-gray-800 dark:bg-gray-900/80 dark:text-gray-300">Disabled</span>';
        }

        if ($record->validation_status !== 'valid') {
            return '<span class="inline-flex items-center rounded-full border border-warning-200 bg-warning-50 px-3 py-1.5 text-xs font-semibold text-warning-700 dark:border-warning-800 dark:bg-warning-950/40 dark:text-warning-300">Needs validation</span>';
        }

        if (! $record->isTrusted()) {
            return '<span class="inline-flex items-center rounded-full border border-warning-200 bg-warning-50 px-3 py-1.5 text-xs font-semibold text-warning-700 dark:border-warning-800 dark:bg-warning-950/40 dark:text-warning-300">Pending trust review</span>';
        }

        if (! $record->hasVerifiedIntegrity()) {
            return '<span class="inline-flex items-center rounded-full border border-danger-200 bg-danger-50 px-3 py-1.5 text-xs font-semibold text-danger-700 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300">Integrity changed</span>';
        }

        return '<span class="inline-flex items-center rounded-full border border-success-200 bg-success-50 px-3 py-1.5 text-xs font-semibold text-success-700 dark:border-success-800 dark:bg-success-950/40 dark:text-success-300">Enabled and ready</span>';
    }

    protected static function runStatusBadge(PluginRun $run): string
    {
        return match ($run->status) {
            'completed' => '<span class="inline-flex items-center rounded-full border border-success-200 bg-success-50 px-3 py-1.5 text-xs font-semibold text-success-700 dark:border-success-800 dark:bg-success-950/40 dark:text-success-300">Last run completed</span>',
            'failed' => '<span class="inline-flex items-center rounded-full border border-danger-200 bg-danger-50 px-3 py-1.5 text-xs font-semibold text-danger-700 dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300">Last run failed</span>',
            'running' => '<span class="inline-flex items-center rounded-full border border-warning-200 bg-warning-50 px-3 py-1.5 text-xs font-semibold text-warning-700 dark:border-warning-800 dark:bg-warning-950/40 dark:text-warning-300">Run in progress</span>',
            'stale' => '<span class="inline-flex items-center rounded-full border border-warning-200 bg-warning-50 px-3 py-1.5 text-xs font-semibold text-warning-700 dark:border-warning-800 dark:bg-warning-950/40 dark:text-warning-300">Run went stale</span>',
            'cancelled' => '<span class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">Run cancelled</span>',
            default => self::mutedBadge(Str::headline($run->status)),
        };
    }

    protected static function mutedBadge(string $label): string
    {
        return '<span class="inline-flex items-center rounded-full border border-gray-200 bg-white/90 px-3 py-1.5 text-xs font-semibold text-gray-600 dark:border-gray-800 dark:bg-gray-900/80 dark:text-gray-300">'.e($label).'</span>';
    }

    protected static function mutedMessage(string $message): string
    {
        return '<div class="text-sm text-gray-500 dark:text-gray-400">'.e($message).'</div>';
    }

    protected static function latestRun(?Plugin $record): ?PluginRun
    {
        return $record?->runs()->first();
    }

    protected static function focusRun(?Plugin $record): ?PluginRun
    {
        if (! $record) {
            return null;
        }

        return $record->runs()
            ->orderByRaw("case when status = 'running' then 0 else 1 end")
            ->latest('created_at')
            ->first();
    }

    protected static function targetSummary(Plugin $record): string
    {
        return self::playlistLabel($record->getSetting('default_playlist_id')).' · '.self::epgLabel($record->getSetting('default_epg_id'));
    }

    protected static function playlistLabel(mixed $playlistId): string
    {
        $ids = is_array($playlistId) ? array_filter($playlistId) : ($playlistId ? [$playlistId] : []);

        if ($ids === []) {
            return 'No playlist default';
        }

        $names = Playlist::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $names !== [] ? implode(', ', $names) : 'No playlist default';
    }

    protected static function epgLabel(mixed $epgId): string
    {
        $ids = is_array($epgId) ? array_filter($epgId) : ($epgId ? [$epgId] : []);

        if ($ids === []) {
            return 'No EPG default';
        }

        $names = Epg::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $names !== [] ? implode(', ', $names) : 'No EPG default';
    }

    protected static function ownershipSummary(Plugin $record): string
    {
        $ownership = $record->data_ownership ?? [];
        $parts = [];

        if (($ownership['tables'] ?? []) !== []) {
            $parts[] = count($ownership['tables']).' table(s)';
        }

        if (($ownership['directories'] ?? []) !== []) {
            $parts[] = count($ownership['directories']).' director'.(count($ownership['directories']) === 1 ? 'y' : 'ies');
        }

        if (($ownership['files'] ?? []) !== []) {
            $parts[] = count($ownership['files']).' file(s)';
        }

        return $parts === [] ? 'No plugin-owned data declared' : implode(' · ', $parts);
    }
}
