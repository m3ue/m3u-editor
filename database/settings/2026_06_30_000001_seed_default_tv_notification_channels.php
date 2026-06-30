<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if ($this->migrator->exists('general.tv_notification_channels')) {
            $this->migrator->update(
                'general.tv_notification_channels',
                fn (array $current) => empty($current) ? self::defaults() : $current,
            );
        }
    }

    public function down(): void
    {
        if ($this->migrator->exists('general.tv_notification_channels')) {
            $this->migrator->delete('general.tv_notification_channels');
        }
    }

    /** @return array<array{name: string, label: string}> */
    private static function defaults(): array
    {
        return [
            ['name' => 'general', 'label' => 'General'],
            ['name' => 'info', 'label' => 'Info'],
            ['name' => 'danger', 'label' => 'Danger'],
            ['name' => 'warning', 'label' => 'Warning'],
            ['name' => 'success', 'label' => 'Success'],
        ];
    }
};
