<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GroupFindAndReplaceReset implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $user_id,
        public ?Collection $groups = null,
        public ?int $playlist_id = null,
        public ?string $group_type = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $start = now();
        $totalUpdated = 0;

        if (! $this->groups) {
            Group::query()
                ->where('user_id', $this->user_id)
                ->when($this->playlist_id, fn ($query) => $query->where('playlist_id', $this->playlist_id))
                ->when($this->group_type, fn ($query) => $query->where('type', $this->group_type))
                ->where('custom', false)
                ->whereColumn('name', '!=', 'name_internal')
                ->chunkById(1000, function ($groups) use (&$totalUpdated) {
                    $groupIds = $groups->pluck('id')->toArray();

                    if (count($groupIds) > 0) {
                        $updated = DB::table('groups')
                            ->whereIn('id', $groupIds)
                            ->update([
                                'name' => DB::raw('name_internal'),
                                'updated_at' => now(),
                            ]);

                        $totalUpdated += $updated;
                    }
                });
        } else {
            $this->groups
                ->filter(fn ($group) => ! $group->custom && $group->name !== $group->name_internal)
                ->chunk(1000)
                ->each(function ($chunk) use (&$totalUpdated) {
                    $groupIds = $chunk->pluck('id')->toArray();

                    if (count($groupIds) > 0) {
                        $updated = DB::table('groups')
                            ->whereIn('id', $groupIds)
                            ->update([
                                'name' => DB::raw('name_internal'),
                                'updated_at' => now(),
                            ]);

                        $totalUpdated += $updated;
                    }
                });
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->user_id);

        Notification::make()
            ->success()
            ->title('Find & Replace reset')
            ->body("Group find & replace reset has completed successfully. {$totalUpdated} groups updated.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Find & Replace reset completed')
            ->body("Group find & replace reset has completed successfully. {$totalUpdated} groups updated in {$completedIn} seconds.")
            ->sendToDatabase($user);
    }
}
