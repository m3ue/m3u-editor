<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-password';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the password for a user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users = User::get(['id', 'email', 'name']);
        if ($users->isEmpty()) {
            $this->info('No users found.');

            return false;
        }
        if ($users->count() === 1) {
            $user = $users->first();
        } else {
            $choices = $users->mapWithKeys(fn ($u) => [$u->email => "{$u->name} ({$u->email})"])->toArray();
            $selected = $this->choice('Select a user to reset the password for:', $choices);
            $user = $users->where('email', $selected)->firstOrFail();
        }

        $password = $this->ask('🔒 Enter the new password');
        if (empty($password)) {
            $this->error('Password cannot be empty.');

            return false;
        }
        $user->password = bcrypt($password);
        $user->save();
        $this->info('✅ Password reset successfully!');
        $this->info("🔑 New password: $password, set for user: {$user->name}");

        return true;
    }
}
