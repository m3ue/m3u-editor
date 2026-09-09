<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ephemeral scratch storage for the interactive "Migrate Provider" preview so the review
 * screen can page/search/sort server-side instead of rendering every channel at once.
 *
 * Deliberately carries no foreign-key constraints: rows are short-lived, are wiped when a
 * preview is rebuilt or applied, and adding FKs to channels/playlists would take DDL locks
 * on tables the import job chains write to continuously.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_migration_plan_rows')) {
            return;
        }

        Schema::create('provider_migration_plan_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_key');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_playlist_id');
            $table->unsignedBigInteger('target_playlist_id');

            $table->unsignedBigInteger('source_channel_id');
            $table->string('source_name')->nullable();
            $table->string('source_stream_id')->nullable();
            $table->string('source_group')->nullable();

            $table->unsignedBigInteger('suggested_target_channel_id')->nullable();
            $table->unsignedBigInteger('matched_target_channel_id')->nullable();
            $table->string('matched_target_name')->nullable();
            $table->string('matched_target_stream_id')->nullable();
            $table->string('match_pass')->nullable();

            // matched | ambiguous | unmatched
            $table->string('bucket')->default('unmatched');
            $table->json('candidate_target_channel_ids')->nullable();
            $table->boolean('include')->default(true);

            $table->unsignedBigInteger('epg_channel_id')->nullable();
            // none | ok | conflict | unavailable
            $table->string('epg_status')->default('none');
            $table->boolean('epg_confirmed')->default(false);

            $table->timestamps();

            $table->index('session_key');
            $table->index('created_at');
            $table->index(['user_id', 'source_playlist_id', 'target_playlist_id'], 'pmpr_owner_pair_idx');
            $table->index(['session_key', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_migration_plan_rows');
    }
};
