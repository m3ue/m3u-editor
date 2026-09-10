<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Actions\CronHelperAction;
use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Rules\Cron;
use App\Services\DateFormatService;
use BackedEnum;
use Cron\CronExpression;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ManageBackupSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $slug = 'backups';

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('Backups');
    }

    public function getTitle(): string
    {
        return __('Backups');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Automated backups'))
                    ->schema([
                        Toggle::make('auto_backup_database')
                            ->label(__('Enable Automatic Database Backups'))
                            ->live()
                            ->helperText(__('When enabled, automatic database backups will be created based on the specified schedule.')),
                        Group::make()
                            ->columnSpanFull()
                            ->columns(3)
                            ->schema([
                                TextInput::make('auto_backup_database_schedule')
                                    ->label(__('Backup Schedule'))
                                    ->suffix(config('app.timezone'))
                                    ->rules([new Cron])
                                    ->live()
                                    ->hintAction(CronHelperAction::make(name: 'backup-cron', cronField: 'auto_backup_database_schedule'))
                                    ->helperText(fn ($get) => CronExpression::isValidExpression($get('auto_backup_database_schedule'))
                                        ? 'Next scheduled backup: '.(new CronExpression($get('auto_backup_database_schedule')))->getNextRunDate()->format(app(DateFormatService::class)->getFormat())
                                        : 'Specify the CRON schedule for automatic backups, e.g. "0 3 * * *".'),
                                TextInput::make('auto_backup_database_max_backups')
                                    ->label(__('Max Backups'))
                                    ->type('number')
                                    ->minValue(0)
                                    ->helperText(__('Specify the maximum number of backups to keep. Enter 0 for no limit.')),
                                TextInput::make('auto_backup_database_delete_after_days')
                                    ->label(__('Delete Backups After (Days)'))
                                    ->type('number')
                                    ->minValue(0)
                                    ->helperText(__('Automatically delete backups older than this many days. Enter 0 for no limit.')),
                            ])->hidden(fn ($get) => ! $get('auto_backup_database')),
                    ]),
            ]);
    }
}
