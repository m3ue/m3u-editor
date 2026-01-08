<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_aliases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('custom_playlist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('uuid', 36)->unique();
            $table->jsonb('xtream_config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('priority')->default(0); // Lower number = higher priority for fallback
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['playlist_id', 'enabled']);
            $table->index(['priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_aliases');
    }
};
