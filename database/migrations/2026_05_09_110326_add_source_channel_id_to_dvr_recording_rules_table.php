<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->foreignId('source_channel_id')
                ->nullable()
                ->after('channel_id')
                ->constrained('channels')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->dropForeignIdFor(Channel::class, 'source_channel_id');
            $table->dropColumn('source_channel_id');
        });
    }
};
