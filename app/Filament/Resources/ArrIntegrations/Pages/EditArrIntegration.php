<?php

namespace App\Filament\Resources\ArrIntegrations\Pages;

use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use App\Models\ArrIntegration;
use App\Services\Arr\ArrService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditArrIntegration extends EditRecord
{
    protected static string $resource = ArrIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('test')
                    ->label(__('Test Connection'))
                    ->icon('heroicon-o-signal')
                    ->action(function () {
                        $service = ArrService::make($this->record);
                        $result = $service->testConnection();

                        if ($result['ok']) {
                            $this->record->forceFill(['last_test_at' => now()])->save();

                            Notification::make()
                                ->success()
                                ->title(__('Connection Successful'))
                                ->body(__('Connected to :name (v:version)', [
                                    'name' => $this->record->name,
                                    'version' => $result['version'] ?? 'unknown',
                                ]))
                                ->send();
                        } else {
                            Notification::make()
                                ->danger()
                                ->title(__('Connection Failed'))
                                ->body($result['error'] ?? 'Unknown error')
                                ->send();
                        }
                    }),

                Action::make('syncProfiles')
                    ->label(__('Sync Profiles & Folders'))
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (ArrIntegration $record) {
                        $service = ArrService::make($record);
                        $profiles = $service->fetchQualityProfiles();
                        $folders = $service->fetchRootFolders();

                        $update = [];
                        if (! empty($profiles)) {
                            $update['quality_profile_id'] = $profiles[0]['id'];
                            $update['quality_profile_name'] = $profiles[0]['name'];
                        }
                        if (! empty($folders)) {
                            $update['root_folder_path'] = $folders[0]['path'];
                        }

                        if (! empty($update)) {
                            $record->forceFill($update)->save();
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Synced'))
                            ->body(__('Cached :profiles profiles and :folders root folders.', [
                                'profiles' => count($profiles),
                                'folders' => count($folders),
                            ]))
                            ->send();
                    }),

                DeleteAction::make(),
            ])->button(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
