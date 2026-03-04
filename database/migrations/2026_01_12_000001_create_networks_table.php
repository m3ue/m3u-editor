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
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->unsignedInteger('channel_number')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('schedule_type')->default('sequential'); // sequential, shuffle
            $table->boolean('loop_content')->default(true);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('media_server_integration_id')->nullable()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamp('schedule_generated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('networks');
    }
};
