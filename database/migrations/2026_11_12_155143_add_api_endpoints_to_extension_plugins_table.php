<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->json('api_endpoints')->nullable()->after('actions');
        });
    }

    public function down(): void
    {
        Schema::table('extension_plugins', function (Blueprint $table) {
            $table->dropColumn('api_endpoints');
        });
    }
};
