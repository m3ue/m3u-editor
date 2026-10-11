<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional sub-channel ("minor") part of a channel number, for over-the-air
     * numbers like "5.1". Stored beside the existing integer number (rather than
     * changing it to a decimal) so "5.1" and "5.10" stay distinct. Nullable column
     * adds are metadata-only on Postgres, so the channels table isn't rewritten. No
     * ->after(): positioning a column forces a table rebuild on older MySQL 8.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->unsignedSmallInteger('channel_minor')->nullable();
        });

        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->unsignedSmallInteger('channel_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('channel_minor');
        });

        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropColumn('channel_minor');
        });
    }
};
