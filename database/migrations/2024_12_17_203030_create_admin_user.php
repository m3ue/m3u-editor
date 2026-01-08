<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $user = User::query()->where('name', 'admin')->first();
        if (! $user) {
            User::query()->create([
                'name' => 'admin',
                'email' => 'admin@test.com',
                'password' => bcrypt('admin'),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
