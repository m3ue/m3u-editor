<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->after('name');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('enabled')->default(false)->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
