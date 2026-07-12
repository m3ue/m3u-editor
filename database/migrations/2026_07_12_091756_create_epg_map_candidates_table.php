<?php

use App\Enums\EpgMapCandidateStatus;
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
        Schema::create('epg_map_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epg_map_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('epg_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('normalized_name')->default('');
            $table->unsignedSmallInteger('top_confidence')->default(0);
            $table->string('top_reason')->default('');
            $table->string('top_matched_value')->default('');
            $table->string('top_normalized_value')->default('');
            $table->boolean('is_exact')->default(false);
            $table->boolean('automatic_match')->default(false);
            $table->json('alternatives')->nullable();
            $table->string('status')->default(EpgMapCandidateStatus::Pending->value);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['epg_map_id', 'channel_id']);
            $table->index(['epg_map_id', 'status']);
            $table->index(['epg_map_id', 'top_confidence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('epg_map_candidates');
    }
};
