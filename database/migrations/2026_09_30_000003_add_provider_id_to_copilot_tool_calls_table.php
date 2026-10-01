<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('copilot_tool_calls', function (Blueprint $table): void {
            $table->string('provider_id')->nullable()->index()->after('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('copilot_tool_calls', function (Blueprint $table): void {
            $table->dropIndex(['provider_id']);
            $table->dropColumn('provider_id');
        });
    }
};
