<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.tv_notification_channels')) {
            $this->migrator->add('general.tv_notification_channels', [
                ['name' => 'dvr', 'label' => 'DVR'],
                ['name' => 'requests', 'label' => 'Requests'],
            ]);

            return;
        }

        $this->migrator->update('general.tv_notification_channels', function (array $channels): array {
            $existingNames = array_column($channels, 'name');

            if (! in_array('dvr', $existingNames)) {
                $channels[] = ['name' => 'dvr', 'label' => 'DVR'];
            }

            if (! in_array('requests', $existingNames)) {
                $channels[] = ['name' => 'requests', 'label' => 'Requests'];
            }

            return $channels;
        });
    }
};
