<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table): void {
            $table->boolean('enable_comskip')->default(false)->after('generate_nfo_files');
            $table->string('comskip_ini_path')->nullable()->after('enable_comskip');
        });
    }

    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table): void {
            $table->dropColumn(['comskip_ini_path', 'enable_comskip']);
        });
    }
};
