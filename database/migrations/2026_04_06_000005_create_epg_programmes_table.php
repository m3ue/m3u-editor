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

        Schema::create('epg_programmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epg_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('epg_channel_id', 500)->index();
            $table->string('title', 500);
            $table->string('subtitle', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('category', 255)->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->string('episode_num', 255)->nullable();
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedSmallInteger('episode')->nullable();
            $table->boolean('is_new')->default(false);
            $table->string('icon', 500)->nullable();
            $table->string('rating', 50)->nullable();
            $table->timestamps();

            $table->index('start_time');
            $table->index(['epg_channel_id', 'start_time']);
            $table->index(['epg_id', 'start_time']);
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('epg_programmes');
    }
};
