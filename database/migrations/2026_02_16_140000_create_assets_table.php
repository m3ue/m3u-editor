<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('source', 64);
            $table->string('name');
            $table->string('extension', 32)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_image')->default(false);
            $table->timestamp('last_modified_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path']);
            $table->index(['source', 'is_image']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
