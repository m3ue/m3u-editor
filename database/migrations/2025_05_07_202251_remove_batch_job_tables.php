<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clenup the job tables since they are not used
        if (Schema::hasTable('job_batches')) {
            DB::table('job_batches')->truncate();
        }
        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')->truncate();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
