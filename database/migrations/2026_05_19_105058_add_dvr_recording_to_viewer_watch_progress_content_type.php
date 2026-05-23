<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Drop the named CHECK constraint — column is already varchar, nothing else needed.
            DB::statement('ALTER TABLE viewer_watch_progress DROP CONSTRAINT IF EXISTS viewer_watch_progress_content_type_check');

            return;
        }

        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            $table->string('content_type')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Restore the original CHECK constraint via raw SQL — Laravel's Schema Builder
            // generates invalid syntax for inline CHECK on ALTER COLUMN in PostgreSQL.
            DB::statement('ALTER TABLE viewer_watch_progress DROP CONSTRAINT IF EXISTS viewer_watch_progress_content_type_check');
            DB::statement("ALTER TABLE viewer_watch_progress ADD CONSTRAINT viewer_watch_progress_content_type_check CHECK (content_type IN ('live', 'vod', 'episode'))");

            return;
        }

        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            $table->enum('content_type', ['live', 'vod', 'episode'])->change();
        });
    }
};
