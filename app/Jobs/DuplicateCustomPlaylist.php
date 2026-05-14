<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Series;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DuplicateCustomPlaylist implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public CustomPlaylist $playlist,
        public string $newName
    ) {
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $newPlaylist = $this->playlist->replicate();

            $newPlaylist->name = $this->newName;
            $newPlaylist->uuid = Str::uuid()->toString();

            if (isset($newPlaylist->short_urls)) {
                $newPlaylist->short_urls = false;
            }

            $newPlaylist->push();

            $channelPivots = DB::table('channel_custom_playlist')
                ->where('custom_playlist_id', $this->playlist->id)
                ->get();

            foreach ($channelPivots as $pivot) {

                $newChannelId = $pivot->channel_id;

                $channel = Channel::find($pivot->channel_id);

                if ($channel && $channel->custom) {

                    $newChannel = $channel->replicate();

                    $newChannel->uuid = Str::uuid()->toString();

                    $newChannel->push();

                    $failovers = DB::table('channel_failovers')
                        ->where('channel_id', $channel->id)
                        ->get();

                    foreach ($failovers as $failover) {

                        DB::table('channel_failovers')->insert([
                            'channel_id' => $newChannel->id,
                            'failover_channel_id' => $failover->failover_channel_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $newChannelId = $newChannel->id;
                }

                DB::table('channel_custom_playlist')->insert([
                    'channel_id' => $newChannelId,
                    'custom_playlist_id' => $newPlaylist->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $seriesPivots = DB::table('series_custom_playlist')
                ->where('custom_playlist_id', $this->playlist->id)
                ->get();

            foreach ($seriesPivots as $pivot) {

                DB::table('series_custom_playlist')->insert([
                    'series_id' => $pivot->series_id,
                    'custom_playlist_id' => $newPlaylist->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $tags = DB::table('tags')
                ->where('taggable_type', CustomPlaylist::class)
                ->where('taggable_id', $this->playlist->id)
                ->get();

            foreach ($tags as $tag) {

                DB::table('tags')->insert([
                    'name' => $tag->name,
                    'type' => $tag->type,
                    'order_column' => $tag->order_column,
                    'taggable_type' => $tag->taggable_type,
                    'taggable_id' => $newPlaylist->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $postProcesses = DB::table('post_processes')
                ->where('custom_playlist_id', $this->playlist->id)
                ->get();

            foreach ($postProcesses as $process) {

                DB::table('post_processes')->insert([
                    'custom_playlist_id' => $newPlaylist->id,
                    'type' => $process->type,
                    'value' => $process->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
