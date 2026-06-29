<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aed_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Source extraction
            $table->string('title_regex')->nullable();
            $table->string('time_regex')->nullable();
            $table->string('time_format')->nullable();
            $table->string('source_timezone')->default('UTC');
            $table->string('date_regex')->nullable();
            $table->string('date_format')->nullable();
            $table->string('team_delimiter')->nullable();
            $table->string('logo_url')->nullable();

            // Output format
            $table->unsignedSmallInteger('event_duration_minutes')->default(180);
            $table->string('output_timezone')->default('UTC');
            $table->string('title_format')->default('{title}');
            $table->string('description_format')->nullable();
            $table->string('no_event_format')->nullable();
            $table->string('pre_event_format')->default('Live in {time_until}: {title}');
            $table->string('post_event_format')->default('Signing Off');
            $table->string('category')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aed_profiles');
    }
};
