<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->jsonb('rules')->nullable()->after('args');
            $table->foreignId('else_stream_profile_id')
                ->nullable()
                ->after('rules')
                ->constrained('stream_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->dropForeign(['else_stream_profile_id']);
            $table->dropColumn(['rules', 'else_stream_profile_id']);
        });
    }
};
