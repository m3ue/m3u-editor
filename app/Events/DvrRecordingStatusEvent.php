<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToEntitledTvChannels;
use App\Models\DvrRecording;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the TV app's playlist channel whenever a DvrRecording's status
 * changes (scheduled, recording, completed, failed, cancelled), so clients
 * can mark channels as recording live instead of polling get_dvr_recordings.
 *
 * Broadcasts on the same channel set as TvNotificationEvent's default (null
 * playlistAuthId) branch — the owner/admin channels plus one channel per
 * entitled PlaylistAuth/alias — since a TV session logged in via PlaylistAuth
 * credentials subscribes only to its own `tv.{type}.{uuid}.{authId}` channel,
 * not the bare owner channel.
 */
class DvrRecordingStatusEvent implements ShouldBroadcast
{
    use BroadcastsToEntitledTvChannels, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $notifiableType,
        private readonly int|string $notifiableId,
        public readonly string $notifiableUuid,
        public readonly string $uuid,
        public readonly string $status,
        public readonly ?int $channelId,
        public readonly ?string $channelName,
        public readonly string $title,
        public readonly ?string $scheduledStart,
        public readonly ?string $scheduledEnd,
    ) {}

    public static function fromRecording(DvrRecording $recording): ?self
    {
        return self::build($recording, $recording->status->value);
    }

    /**
     * `deleted` isn't a real DvrRecordingStatus value — the row is gone by
     * the time this would otherwise be checked — so it's passed explicitly.
     * The TV app treats it as a signal to drop the recording locally rather
     * than update its status (see AppStateController._onDvrStatusPush).
     */
    public static function forDeletion(DvrRecording $recording): ?self
    {
        return self::build($recording, 'deleted');
    }

    private static function build(DvrRecording $recording, string $status): ?self
    {
        $playlist = $recording->dvrSetting?->owner();

        if (! $playlist) {
            return null;
        }

        return new self(
            notifiableType: $playlist->getMorphClass(),
            notifiableId: $playlist->getKey(),
            notifiableUuid: $playlist->uuid,
            uuid: $recording->uuid,
            status: $status,
            channelId: $recording->channel_id,
            channelName: $recording->channel?->title_custom ?? $recording->channel?->title,
            title: $recording->title,
            scheduledStart: $recording->scheduled_start?->toIso8601String(),
            scheduledEnd: $recording->scheduled_end?->toIso8601String(),
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return self::entitledTvChannels($this->notifiableType, $this->notifiableId, $this->notifiableUuid);
    }

    public function broadcastAs(): string
    {
        return 'dvr.status';
    }

    /**
     * Laravel's default broadcast payload serializes the constructor property
     * names verbatim (camelCase). The TV client's DvrRecording.fromXtream()
     * parses the same snake_case keys as the REST get_dvr_recordings /
     * formatDvrRecording() response — this must match that shape or fields
     * like channel_id silently come back null on the client.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notifiableType' => $this->notifiableType,
            'notifiableUuid' => $this->notifiableUuid,
            'uuid' => $this->uuid,
            'status' => $this->status,
            'channel_id' => $this->channelId,
            'channel_name' => $this->channelName,
            'title' => $this->title,
            'scheduled_start' => $this->scheduledStart,
            'scheduled_end' => $this->scheduledEnd,
        ];
    }
}
