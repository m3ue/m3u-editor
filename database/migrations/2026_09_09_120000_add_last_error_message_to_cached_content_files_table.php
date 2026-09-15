<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist the actual exception message from `DownloadCachedContentFile::markFailed()`
     * onto the row so operators can see WHY a download failed from the UI
     * (Dynamic Group Cache Activity widget's "View error" record action) without
     * digging through `storage/logs/laravel-*.log`.
     *
     * Existing rows pre-migration have `null` here — only failures logged AFTER
     * this migration runs will populate the column. The activity widget hides
     * the "View error" action when the column is null.
     *
     * TEXT (not VARCHAR) so long stack-trace-style messages from Guzzle /
     * Symfony HttpClient don't get truncated. Postgres `text` is unbounded.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->text('last_error_message')->nullable()->after('last_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table): void {
            $table->dropColumn('last_error_message');
        });
    }
};
