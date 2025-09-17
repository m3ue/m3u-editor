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
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->text('user_agent')->default('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
            $table->boolean('enable_proxy')->default(false);
            $table->jsonb('proxy_options')->nullable();
            $table->integer('streams')->default(0);
            $table->unsignedInteger('available_streams')->default(0);
            $table->string('server_timezone')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn([
                'user_agent',
                'enable_proxy',
                'proxy_options',
                'streams',
                'available_streams',
                'server_timezone',
            ]);
        });
    }
};
