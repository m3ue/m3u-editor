<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table): void {
            $table->boolean('include_disabled_channels')->default(false)->after('enable_comskip');
        });
    }

    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table): void {
            $table->dropColumn('include_disabled_channels');
        });
    }
};
