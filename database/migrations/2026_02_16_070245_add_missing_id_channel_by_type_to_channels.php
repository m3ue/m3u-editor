<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'playlists',
        'custom_playlists',
        'merged_playlists',
    ];

    private array $enumValues = [
        'stream_id',
        'channel_id',
        'name',
        'title',
        'number',
    ];

    private array $previousEnumValues = [
        'stream_id',
        'channel_id',
        'name',
        'title',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->updateIdChannelByEnum($this->enumValues);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->updateIdChannelByEnum($this->previousEnumValues);
    }

    private function updateIdChannelByEnum(array $values): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            foreach ($this->tables as $tableName) {
                Schema::table($tableName, function (Blueprint $table) use ($values) {
                    $table->enum('id_channel_by', $values)->change();
                });
            }

            return;
        }

        $allowedValues = implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values
        ));

        foreach ($this->tables as $tableName) {
            $constraintName = "{$tableName}_id_channel_by_check";

            DB::statement("ALTER TABLE \"{$tableName}\" DROP CONSTRAINT IF EXISTS \"{$constraintName}\"");
            DB::statement(
                "ALTER TABLE \"{$tableName}\" ADD CONSTRAINT \"{$constraintName}\" CHECK (\"id_channel_by\" IN ({$allowedValues}))"
            );
        }
    }
};
