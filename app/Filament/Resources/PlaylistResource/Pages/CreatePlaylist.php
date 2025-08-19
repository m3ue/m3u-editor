<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Resources\PlaylistResource;
use App\Models\PlaylistAuth;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePlaylist extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSteps(): array
    {
        return PlaylistResource::getFormSteps();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Remove auth-related fields from playlist creation data
        // These will be handled after the playlist is created
        unset($data['auth_option'], $data['existing_auth_id'], $data['auth_name'], $data['auth_username'], $data['auth_password']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $data = $this->form->getState();

        // Handle authentication based on the selected option
        if (isset($data['auth_option'])) {
            switch ($data['auth_option']) {
                case 'existing':
                    // Assign existing auth to the playlist
                    if (!empty($data['existing_auth_id'])) {
                        $auth = PlaylistAuth::find($data['existing_auth_id']);
                        if ($auth && !$auth->isAssigned()) {
                            $auth->assignTo($this->record);
                        }
                    }
                    break;

                case 'create':
                    // Create new auth and assign to playlist
                    if (!empty($data['auth_username']) && !empty($data['auth_password'])) {
                        $auth = PlaylistAuth::create([
                            'user_id' => Auth::id(),
                            'name' => $data['auth_name'] ?: 'Auth for ' . $this->record->name,
                            'username' => $data['auth_username'],
                            'password' => $data['auth_password'],
                            'enabled' => true,
                        ]);

                        $auth->assignTo($this->record);
                    }
                    break;

                case 'none':
                default:
                    // No authentication setup
                    break;
            }
        }
    }
}
