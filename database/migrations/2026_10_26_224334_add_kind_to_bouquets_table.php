<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a bouquet adds its groups to the aliases it is assigned to (allow) or hides
     * them (block). Existing bouquets are all allow bouquets, so they keep working as before.
     */
    public function up(): void
    {
        Schema::table('bouquets', function (Blueprint $table) {
            $table->string('kind')->default('allow')->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bouquets', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
