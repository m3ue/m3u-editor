<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DisableMfa extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:disable-mfa';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable multi-factor authentication for a user.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users = User::get(['id', 'name', 'email']);
        if ($users->isEmpty()) {
            $this->info('No users found.');

            return false;
        }
        if ($users->count() === 1) {
            $user = $users->first();
        } else {
            $user = $this->choice('Select a user to disable MFA for:', $users->pluck('email')->toArray());
            $user = $users->where('email', $user)->firstOrFail();
        }
        $this->info("🔓 Disabling multi-factor authentication for \"$user->name\" ...");
        if ($user) {
            DB::table('breezy_sessions')->where([
                ['authenticatable_id', $user->id],
                ['authenticatable_type', User::class],
            ])->delete();
            $this->info("✅ Multi-factor authentication has been disabled for \"$user->name\"");
        } else {
            $this->error('No user found with that name.');
        }
    }
}
