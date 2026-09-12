<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership rows linking CachedContentFile rows to DynamicGroups.
     *
     * Both FKs cascade on delete so rows are cleaned up when either side
     * of the relationship is removed.
     */
    public function up(): void
    {
        Schema::create('cached_content_file_dynamic_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cached_content_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dynamic_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['cached_content_file_id', 'dynamic_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_content_file_dynamic_groups');
    }
};
