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

        Schema::create('processables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_process_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->morphs('processable');
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processables');
    }
};
