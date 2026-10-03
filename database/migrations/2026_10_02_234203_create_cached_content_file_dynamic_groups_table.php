<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance rows linking cached files to the dynamic groups that
     * queued them, with a snapshot of the group rule's retention settings
     * taken at attach time.
     *
     * `dynamic_group_id` is nullable with nullOnDelete (NOT cascade):
     * SyncDynamicGroups' stale-group cleanup deletes DynamicGroup rows via
     * the query builder for disabled/renamed rules and when TMDB is
     * unconfigured. A cascade would orphan-delete every provenance row at
     * that moment, and the next 03:00 retention sweep would then release
     * all of the group's managed files with no grace period. With
     * nullOnDelete the row survives with a NULL group and still carries its
     * retention snapshot, so retention can honor the grace period. Postgres
     * allows several NULL-group rows under the unique index, which is fine.
     */
    public function up(): void
    {
        Schema::create('cached_content_file_dynamic_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cached_content_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dynamic_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('retention');
            $table->unsignedInteger('retention_days')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->unique(['cached_content_file_id', 'dynamic_group_id']);
            $table->index('dynamic_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cached_content_file_dynamic_groups');
    }
};
