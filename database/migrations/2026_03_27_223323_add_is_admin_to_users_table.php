<?php

use App\Models\User;
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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
        });

        // Seed is_admin for existing users whose email matches the configured admin emails
        $adminEmails = config('dev.admin_emails', ['admin@test.com']);
        User::whereIn('email', $adminEmails)->update(['is_admin' => true]);

        // Fallback: if no users matched (e.g. admin email was already changed),
        // promote the first user as admin
        if (User::where('is_admin', true)->doesntExist()) {
            User::query()->orderBy('id')->first()?->update(['is_admin' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
