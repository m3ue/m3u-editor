<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('copilot_messages', function (Blueprint $table) {
            // 'positive' | 'negative' | null (never rated, or rating cleared).
            $table->string('rating')->nullable()->after('content');

            // Conversation first: the management table filters conversations by
            // "has a negative-rated message", which is an equality lookup on
            // (conversation_id, rating). A plain rating index would still force
            // a per-conversation row scan.
            $table->index(['conversation_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::table('copilot_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'rating']);
            $table->dropColumn('rating');
        });
    }
};
