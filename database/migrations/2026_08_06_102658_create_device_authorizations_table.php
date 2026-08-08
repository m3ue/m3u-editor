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
        Schema::create('device_authorizations', function (Blueprint $table) {
            $table->id();
            $table->string('device_code', 64)->unique();
            $table->string('user_code', 16)->unique();
            $table->string('status')->default('pending');
            $table->foreignId('playlist_auth_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_ip', 45)->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedSmallInteger('interval_seconds')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_authorizations');
    }
};
