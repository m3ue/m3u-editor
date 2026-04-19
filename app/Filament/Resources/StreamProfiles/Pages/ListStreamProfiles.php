<?php

namespace App\Filament\Resources\StreamProfiles\Pages;

use App\Filament\Resources\StreamProfiles\StreamProfileResource;
use App\Models\StreamProfile;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListStreamProfiles extends ListRecords
{
    protected static string $resource = StreamProfileResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Stream profiles are used to define how streams are transcoded by the proxy. They can be assigned to playlists to enable transcoding for those playlists. If a playlist does not have a stream profile assigned, direct stream proxying will be used.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate_default_profiles')
                ->label(__('Generate Default Profiles'))
                ->requiresConfirmation()
                ->action(function () {
                    $userId = auth()->id();
                    $defaultProfiles = [
                        [
                            'user_id' => $userId,
                            'name' => 'Default Live Profile',
                            'description' => 'Optimized for live streaming content with CBR encoding.',
                            'backend' => 'ffmpeg',
                            'format' => 'ts',
                            'args' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -f mpegts {output_args|pipe:1}',
                        ],
                        [
                            'user_id' => $userId,
                            'name' => 'Default HLS Profile',
                            'description' => 'Optimized for live streaming with low latency, better buffering, and CBR encoding.',
                            'backend' => 'ffmpeg',
                            'format' => 'm3u8',
                            'args' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -f hls {output_args|index.m3u8}',
                        ],
                        [
                            'user_id' => $userId,
                            'name' => 'Default Streamlink Profile',
                            'description' => 'For platforms like Twitch and YouTube — extracts the stream directly without re-encoding.',
                            'backend' => 'streamlink',
                            'format' => 'ts',
                            'args' => 'best --hls-live-edge 3',
                        ],
                        [
                            'user_id' => $userId,
                            'name' => 'Default yt-dlp Profile',
                            'description' => 'For platforms supported by yt-dlp — extracts the best available quality without re-encoding.',
                            'backend' => 'ytdlp',
                            'format' => 'ts',
                            'args' => 'bestvideo+bestaudio/best --no-playlist',
                        ],
                    ];
                    foreach ($defaultProfiles as $defaultProfile) {
                        StreamProfile::query()->create($defaultProfile);
                    }
                })
                ->after(function () {
                    Notification::make()
                        ->title(__('Default stream profiles have been generated!'))
                        ->success()
                        ->send();
                })
                ->color('success')
                ->icon('heroicon-o-check-badge'),
            Actions\CreateAction::make()
                ->label(__('New Profile'))
                ->slideOver(),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
