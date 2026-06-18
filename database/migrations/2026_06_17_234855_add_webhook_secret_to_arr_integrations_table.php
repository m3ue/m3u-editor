<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->string('webhook_secret')->unique()->nullable()->after('guest_enabled');
        });

        // Backfill existing rows so every integration immediately has a usable webhook URL.
        DB::table('arr_integrations')->lazyById()->each(function (object $row) {
            DB::table('arr_integrations')
                ->where('id', $row->id)
                ->update(['webhook_secret' => (string) Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('arr_integrations', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
