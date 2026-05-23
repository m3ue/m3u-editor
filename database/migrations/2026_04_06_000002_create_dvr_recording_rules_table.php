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

        Schema::create('dvr_recording_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('dvr_setting_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('type')->default('once'); // DvrRuleType enum
            $table->string('programme_id')->nullable()->index();
            $table->string('series_title')->nullable();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('epg_channel_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->boolean('new_only')->default(false);
            $table->unsignedSmallInteger('priority')->default(50);
            $table->unsignedSmallInteger('start_early_seconds')->nullable();
            $table->unsignedSmallInteger('end_late_seconds')->nullable();
            $table->unsignedSmallInteger('keep_last')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('manual_start')->nullable();
            $table->dateTime('manual_end')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dvr_recording_rules');
    }
};
