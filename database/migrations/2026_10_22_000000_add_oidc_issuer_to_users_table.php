<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Legacy subject-only bindings require an explicit operator/user link.
            $table->string('oidc_issuer')->nullable();
            $table->unique(['oidc_issuer', 'oidc_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_issuer', 'oidc_id']);
            $table->dropColumn('oidc_issuer');
        });
    }
};
