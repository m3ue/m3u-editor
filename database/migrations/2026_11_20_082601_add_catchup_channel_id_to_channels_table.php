<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY can't run inside a transaction on Postgres.
     */
    public $withinTransaction = false;

    /**
     * Link a live channel to another channel whose catchup it borrows (#1661).
     *
     * channels is written to continuously by the import and sync job chains, so
     * nothing here may rewrite or long-lock it:
     * - SQLite: the schema builder adds a foreign key to an existing table by
     *   rebuilding the whole table, so the column is added with an inline
     *   REFERENCES clause instead (allowed for a column that defaults to NULL).
     * - Postgres: the constraint is added NOT VALID (every existing row is NULL,
     *   so there is nothing to validate) and the index is built concurrently.
     *
     * The index backs ON DELETE SET NULL: without it, every deleted channel
     * would scan the whole table for links pointing at it.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE channels ADD COLUMN catchup_channel_id INTEGER NULL REFERENCES channels(id) ON DELETE SET NULL');
        } else {
            Schema::table('channels', function (Blueprint $table): void {
                $table->unsignedBigInteger('catchup_channel_id')->nullable();
                $table->foreign('catchup_channel_id')
                    ->references('id')
                    ->on('channels')
                    ->nullOnDelete()
                    ->notValid();
            });
        }

        Schema::table('channels', function (Blueprint $table): void {
            $table->index('catchup_channel_id', 'channels_catchup_channel_id_index')->online();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropIndex('channels_catchup_channel_id_index');
        });

        // SQLite refuses DROP COLUMN on a foreign key column, so the key goes first
        // (a table rebuild there, acceptable for a rollback).
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropForeign(['catchup_channel_id']);
            $table->dropColumn('catchup_channel_id');
        });
    }
};
