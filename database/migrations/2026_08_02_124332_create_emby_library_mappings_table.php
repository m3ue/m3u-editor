<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('emby_library_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('media_server_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('source_kind', 32);
            $table->string('source_identifier', 255);
            $table->string('source_label');
            $table->string('target_library_id')->nullable();
            $table->string('target_library_name');
            $table->string('collection_type', 16);
            $table->string('output_path', 1024);
            $table->boolean('is_managed')->default(false);
            $table->json('options');
            $table->string('last_planned_revision', 64)->nullable();
            $table->string('last_applied_revision', 64)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('status', 32)->default('idle');
            $table->string('status_summary', 500)->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->timestamps();

            $table->index(['media_server_integration_id', 'enabled']);
            $table->unique(
                ['media_server_integration_id', 'source_kind', 'source_identifier', 'source_label', 'collection_type'],
                'emby_library_mapping_source_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emby_library_mappings');
    }
};
