<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arr failback: integrations can opt into having arr-sourced cache
     * requests fall back to a provider download when the arr can't deliver.
     *
     * Plain DDL is fine here: `cached_content_files` holds one row per cached
     * item and is not written by the Process/Sync import job chains.
     */
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table): void {
            $table->boolean('cache_failback')->default(false)->after('cache_cleanup');
        });

        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->string('source')->default('provider')->after('managed_by');
            $table->foreignId('arr_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('arr_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('arr_integration_id');
            $table->dropColumn(['source', 'arr_requested_at']);
        });

        Schema::table('arr_integrations', function (Blueprint $table): void {
            $table->dropColumn('cache_failback');
        });
    }
};
