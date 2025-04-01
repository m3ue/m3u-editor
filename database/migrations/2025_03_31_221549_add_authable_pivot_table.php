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
        Schema::disableForeignKeyConstraints();

        Schema::create('authenticatables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_auth_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->morphs('authenticatable');
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authenticatables');
    }
};
