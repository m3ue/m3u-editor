<?php

namespace App\Events;

use App\Models\CustomPlaylist;
use App\Models\MediaRequest;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to the TV app's playlist channel whenever a MediaRequest's status
 * changes (approved, rejected, completed), so clients can update the
 * requests screen live instead of polling request_status/request_history.
 *
 * Reuses the same `tv.{type}.{uuid}` channel as TvNotificationEvent/
 * DvrRecordingStatusEvent — the TV app is already subscribed to it after
 * login, so no new subscription or broadcasting/auth change is needed.
 */
class MediaRequestStatusEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $notifiableType,
        public readonly string $notifiableUuid,
        public readonly int $id,
        public readonly string $status,
        public readonly string $type,
        public readonly ?string $externalId,
        public readonly string $title,
        public readonly ?int $integrationId,
        public readonly ?string $integrationName,
        public readonly ?int $seasonNumber,
        public readonly ?int $episodeNumber,
        public readonly ?string $requestedAt,
        public readonly bool $canDismiss,
    ) {}

    public static function fromRequest(MediaRequest $request): ?self
    {
        $playlist = self::resolvePlaylist($request);

        if (! $playlist) {
            return null;
        }

        return new self(
            notifiableType: $playlist->getMorphClass(),
            notifiableUuid: $playlist->uuid,
            id: $request->id,
            status: $request->status === 'pending' ? 'pending_approval' : $request->status,
            type: $request->request_type,
            externalId: $request->external_id,
            title: $request->title,
            integrationId: $request->arr_integration_id,
            integrationName: $request->arrIntegration?->name,
            seasonNumber: $request->season_number,
            episodeNumber: $request->episode_number,
            requestedAt: $request->requested_at?->toIso8601String(),
            canDismiss: in_array($request->status, ['completed', 'rejected'], true),
        );
    }

    /**
     * Mirrors XtreamApiController::resolveEffectivePlaylist() — unwraps a
     * PlaylistAlias to its effective playlist so the channel name matches
     * what the TV client actually subscribed to after login.
     */
    private static function resolvePlaylist(MediaRequest $request): Playlist|CustomPlaylist|MergedPlaylist|null
    {
        $model = $request->playlistAuth?->playlist();

        if ($model instanceof PlaylistAlias) {
            $model = $model->getEffectivePlaylist();
        }

        return $model instanceof Playlist || $model instanceof CustomPlaylist || $model instanceof MergedPlaylist
            ? $model
            : null;
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tv.{$this->notifiableType}.{$this->notifiableUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'request.status';
    }

    /**
     * Snake_case to match ContentRequestService::formatRequest()'s wire shape,
     * which the TV client's MediaRequestSummary.fromJson() parses.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notifiableType' => $this->notifiableType,
            'notifiableUuid' => $this->notifiableUuid,
            'id' => $this->id,
            'status' => $this->status,
            'type' => $this->type,
            'external_id' => $this->externalId,
            'title' => $this->title,
            'integration_id' => $this->integrationId,
            'integration_name' => $this->integrationName,
            'season_number' => $this->seasonNumber,
            'episode_number' => $this->episodeNumber,
            'requested_at' => $this->requestedAt,
            'can_dismiss' => $this->canDismiss,
        ];
    }
}
