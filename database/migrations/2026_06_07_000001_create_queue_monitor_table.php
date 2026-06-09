<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_monitor', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->index();
            $table->string('name')->nullable();
            $table->string('queue')->nullable();
            $table->string('batch_id')->nullable()->index();
            $table->string('batch_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('failed')->default(false)->index();
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('exception_message')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_monitor');
    }
};
