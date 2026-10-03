<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which automated feature owns this cached file (CachedContentManagedBy).
     *
     * `dynamic_group` means a dynamic group's auto-cache created the file
     * and group retention may release it once the item leaves the group's
     * cache scope. NULL means the file is manual (Cache Now) or pinned
     * (`never_expire` retention), and automated retention never deletes it.
     * A future arr-stack feature will add its own case (see the enum).
     *
     * Adding a nullable string column with no default is a metadata-only
     * change on Postgres (no table rewrite), so this does not lock the
     * table against the download/cache workers writing to it.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->string('managed_by')->nullable()->after('failure_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->dropColumn('managed_by');
        });
    }
};
