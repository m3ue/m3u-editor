<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->string('webhook_secret')->unique()->nullable()->after('guest_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
