<?php

namespace App\Http\Controllers\Api;

use App\Casts\UtcDateTime;
use App\Enums\DvrMatchMode;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DvrStreamController;
use App\Http\Resources\Dvr\RecordingResource;
use App\Http\Resources\Dvr\SeriesRuleMatchResource;
use App\Http\Resources\Dvr\SeriesRuleResource;
use App\Jobs\EnrichDvrMetadata;
use App\Jobs\IntegrateDvrRecordingToVod;
use App\Jobs\ProcessComskipOnRecording;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\EpgProgramme;
use App\Services\DvrAccessScope;
use App\Services\DvrRecorderService;
use App\Services\DvrSchedulerService;
use App\Support\SeriesKey;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dispatcharr-compatible DVR API.
 *
 * Mirrors the request/response shapes of Dispatcharr's `/api/channels/recordings/`
 * and `/api/channels/series-rules/` endpoints, served at `/recordings/` and
 * `/series-rules/` alongside the app's other API routes, so players built against
 * Dispatcharr's DVR can point at m3u-editor with little more than a base URL change.
 * Everything is translated onto the native DvrRecording/DvrRecordingRule models: a
 * one-off recording becomes a Manual rule, a series rule becomes a Series rule.
 * Authentication (Sanctum API token) and scoping are handled by
 * DispatcharrDvrAuthMiddleware, which puts a DvrAccessScope on the request.
 */
#[Group('DVR', 'Dispatcharr-style DVR endpoints for scheduling, managing, and playing recordings. Authenticate with an API token sent as `Authorization: Bearer <token>`, `X-API-Key: <token>`, `Authorization: ApiKey <token>`, or `?token=<token>` (media requests). The token needs the `view`, `create`, `update` or `delete` ability the endpoint requires.', weight: 80)]
class DispatcharrDvrController extends Controller
{
    /**
     * Relations needed to resolve the playlist that owns a recording's DVR setting.
     */
    private const OWNER_RELATIONS = ['dvrSetting.playlist', 'dvrSetting.customPlaylist', 'dvrSetting.mergedPlaylist'];

    public function __construct(
        protected DvrRecorderService $recorder,
        protected DvrSchedulerService $scheduler,
    ) {}

    /**
     * List recordings
     *
     * Every recording visible to the caller, in Dispatcharr's shape: a thin row
     * with the descriptive fields nested in `custom_properties`.
     */
    public function index(Request $request): JsonResponse
    {
        $recordings = $this->visibleRecordings($this->scope($request))
            ->with(['channel.epgChannel', ...self::OWNER_RELATIONS])
            ->orderBy('scheduled_start')
            ->get();

        return response()->json(RecordingResource::collection($recordings));
    }

    /**
     * Get a recording
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        return response()->json(new RecordingResource($recording));
    }

    /**
     * Schedule a recording
     *
     * Body: {channel, start_time, end_time, custom_properties: {program: {...}}}.
     * Stored as a Manual rule, which the scheduler turns into a Scheduled
     * recording synchronously; that recording is returned.
     */
    public function store(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        $validator = Validator::make($request->all(), [
            'channel' => ['required', 'integer'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'custom_properties' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toArray(), 400);
        }

        $channelId = (int) $request->input('channel');
        $channel = $scope->findChannel($channelId);

        if (! $channel) {
            return response()->json(['channel' => ["Invalid pk \"{$channelId}\" - object does not exist."]], 400);
        }

        // manual_start/manual_end are re-hydrated by Eloquent in app.timezone - see
        // XtreamApiController::scheduleDvr() for the same compensation.
        $appTimezone = config('app.timezone', 'UTC');
        $manualStart = Carbon::parse($request->input('start_time'))->setTimezone($appTimezone);
        $manualEnd = Carbon::parse($request->input('end_time'))->setTimezone($appTimezone);

        if ($manualEnd->isPast()) {
            return $this->nonFieldError('End time must be in the future.');
        }

        $dvrSetting = $scope->settingForWrite($channelId);

        if (! $dvrSetting?->enabled) {
            return $this->nonFieldError('DVR is not enabled for this channel.');
        }

        $existing = $scope->rules()
            ->where('dvr_setting_id', $dvrSetting->id)
            ->where('type', DvrRuleType::Manual)
            ->where('enabled', true)
            ->where('channel_id', $channelId)
            ->where('manual_start', '<', UtcDateTime::forQuery($manualEnd))
            ->where('manual_end', '>', UtcDateTime::forQuery($manualStart))
            ->first();

        if ($existing) {
            return response()->json([
                'detail' => 'A recording for this time window already exists.',
                'id' => $existing->recordings()->latest('id')->value('id'),
            ], 409);
        }

        $program = $request->input('custom_properties.program');
        $programTitle = is_array($program) ? trim((string) ($program['title'] ?? '')) : '';
        $title = $programTitle ?: ($channel->title_custom ?? $channel->title ?? $channel->name);

        // Like Dispatcharr, pre/post padding only applies to EPG-based recordings.
        $isEpgBased = is_array($program);

        $rule = DvrRecordingRule::create([
            'user_id' => $dvrSetting->user_id,
            'dvr_setting_id' => $dvrSetting->id,
            'type' => DvrRuleType::Manual,
            'channel_id' => $channelId,
            'series_title' => $title,
            'match_mode' => DvrMatchMode::Exact,
            'manual_start' => $manualStart,
            'manual_end' => $manualEnd,
            'start_early_seconds' => $isEpgBased ? (int) ($dvrSetting->default_start_early_seconds ?? 0) : 0,
            'end_late_seconds' => $isEpgBased ? (int) ($dvrSetting->default_end_late_seconds ?? 0) : 0,
            'enabled' => true,
        ]);

        $recording = $rule->recordings()->with(['channel.epgChannel', ...self::OWNER_RELATIONS])->latest('id')->first();

        if (! $recording) {
            $rule->delete();

            return $this->nonFieldError('The recording could not be scheduled. It may already be scheduled, or the DVR is at capacity.');
        }

        return response()->json(new RecordingResource($recording), 201);
    }

    /**
     * Delete a recording
     *
     * Stops the recording if it is scheduled or in progress, then deletes it and its files.
     */
    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        if (in_array($recording->status, [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording], true)) {
            $this->recorder->cancel($recording);
            $recording->refresh();
        }

        $this->recorder->releaseProxyResources($recording);
        $recording->delete();

        return response()->noContent();
    }

    /**
     * Stop a recording
     *
     * Stops a recording early, keeping whatever was already captured.
     */
    public function stop(Request $request, int $id): JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        if (! in_array($recording->status, [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Recording is already '.RecordingResource::dispatcharrStatus($recording),
            ], 409);
        }

        $this->recorder->cancel($recording);

        return response()->json(['success' => true, 'status' => 'stopped']);
    }

    /**
     * Extend a scheduled recording
     *
     * Body: {extra_minutes}. Only recordings that have not started yet can be
     * extended: the proxy is handed a fixed duration when the recording starts,
     * so a running recording's end time cannot be moved.
     */
    public function extend(Request $request, int $id): JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        $extraMinutes = filter_var($request->input('extra_minutes'), FILTER_VALIDATE_INT);

        if ($extraMinutes === false || $extraMinutes <= 0) {
            return response()->json(['success' => false, 'error' => 'extra_minutes must be a positive integer'], 400);
        }

        if ($recording->status === DvrRecordingStatus::Recording) {
            return response()->json(['success' => false, 'error' => 'A recording that is already in progress cannot be extended'], 409);
        }

        if ($recording->status !== DvrRecordingStatus::Scheduled) {
            return response()->json(['success' => false, 'error' => 'Recording has already finished'], 400);
        }

        $recording->update(['scheduled_end' => $recording->scheduled_end->copy()->addMinutes($extraMinutes)]);

        return response()->json(['success' => true, 'new_end_time' => $recording->scheduled_end->toIso8601String()]);
    }

    /**
     * Update recording metadata
     *
     * Body: {title?, description?}. Blank values are ignored. A completed recording
     * is re-integrated into the VOD library so the new title shows up there too.
     */
    #[BodyParameter('title', 'New title. Blank values are ignored.', type: 'string')]
    #[BodyParameter('description', 'New description. Blank values are ignored.', type: 'string')]
    public function updateMetadata(Request $request, int $id): JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        if (! $request->has('title') && ! $request->has('description')) {
            return response()->json(['success' => false, 'error' => 'No fields to update'], 400);
        }

        $changes = array_filter([
            'title' => trim((string) $request->input('title', '')),
            'description' => trim((string) $request->input('description', '')),
        ], fn (string $value) => $value !== '');

        if ($changes === []) {
            return response()->json(['success' => false, 'error' => 'Title and description cannot be blank'], 400);
        }

        $recording->update($changes);

        if ($recording->status === DvrRecordingStatus::Completed && $recording->hasFilePath()) {
            IntegrateDvrRecordingToVod::dispatch($recording->id)->onQueue('dvr-post');
        }

        return response()->json(['success' => true]);
    }

    /**
     * Refresh recording artwork
     *
     * Re-runs TMDB/TVMaze metadata enrichment (and the VOD integration that follows
     * it) for a completed recording.
     */
    public function refreshArtwork(Request $request, int $id): JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        if ($recording->status !== DvrRecordingStatus::Completed || ! $recording->hasFilePath()) {
            return response()->json(['success' => false, 'error' => 'Artwork can only be refreshed for a completed recording'], 400);
        }

        if (! $recording->dvrSetting?->enable_metadata_enrichment) {
            return response()->json(['success' => false, 'error' => 'Metadata enrichment is disabled for this DVR'], 400);
        }

        EnrichDvrMetadata::dispatch($recording->id)->onQueue('dvr-meta');

        return response()->json(['success' => true, 'message' => 'Artwork refresh started']);
    }

    /**
     * Run commercial detection
     *
     * Queues commercial detection on the recording file, overwriting any existing EDL.
     */
    public function comskip(Request $request, int $id): JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        if (! $recording->hasFilePath()) {
            return response()->json(['success' => false, 'error' => 'Recording file is not available'], 400);
        }

        ProcessComskipOnRecording::dispatch($recording->id);

        return response()->json(['success' => true, 'queued' => true]);
    }

    /**
     * Cancel all upcoming recordings
     *
     * Cancels every still-scheduled recording that has not started yet.
     */
    public function bulkDeleteUpcoming(Request $request): JsonResponse
    {
        $removed = $this->cancelScheduled(
            $this->scope($request)->recordings()->where('scheduled_start', '>', now())
        );

        return response()->json(['success' => true, 'removed' => $removed]);
    }

    /**
     * Stream a recording
     *
     * Streams the finished file (with range support), or the live HLS playlist
     * while the recording is still in progress. Completed recordings are usually
     * MPEG-TS, but can be MP4 or Matroska depending on the DVR's output settings.
     *
     * @response JsonResponse<array{detail: 'Not found.'}, 404>
     */
    #[ApiResponse(200, 'The recording file.', mediaType: 'video/mp2t', type: 'string', format: 'binary')]
    public function file(Request $request, int $id): Response|StreamedResponse|RedirectResponse|JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording) {
            return $this->notFound();
        }

        return app(DvrStreamController::class)->serveRecording($request, $recording);
    }

    /**
     * Live HLS playlist
     *
     * Live HLS playlist for an in-progress recording. Segment URLs point straight at
     * the proxy, so only the playlist itself is served here. Once the recording has
     * finished, redirects to the file endpoint like Dispatcharr does.
     *
     * @response JsonResponse<array{detail: 'Not found.'}, 404>
     */
    #[ApiResponse(200, 'The live HLS playlist.', mediaType: 'application/vnd.apple.mpegurl', type: 'string')]
    public function hls(Request $request, int $id, string $path): Response|RedirectResponse|JsonResponse
    {
        $recording = $this->findRecording($request, $id);

        if (! $recording || ! str_ends_with($path, '.m3u8')) {
            return $this->notFound();
        }

        if ($recording->status === DvrRecordingStatus::Recording && $recording->proxy_network_id) {
            return app(DvrStreamController::class)->serveLivePlaylist($request, $recording);
        }

        if ($recording->status === DvrRecordingStatus::Completed && $recording->hasFilePath()) {
            $query = $request->query('token') ? '?'.http_build_query(['token' => $request->query('token')]) : '';

            return redirect()->to(RecordingResource::filePath($recording).$query);
        }

        return $this->notFound();
    }

    /**
     * List series rules
     */
    public function seriesRules(Request $request): JsonResponse
    {
        return response()->json(['rules' => $this->formattedSeriesRules($this->scope($request))]);
    }

    /**
     * Create or update a series rule
     *
     * Creates the rule, or updates the existing rule for the same show (Dispatcharr
     * upserts by tvg_id + title; m3u-editor keeps one series rule per show).
     *
     * @response array{success: true, rules: SeriesRuleResource[]}
     */
    #[BodyParameter('title', 'Series title to match.', required: true, type: 'string', example: 'Evening News')]
    #[BodyParameter('tvg_id', 'EPG channel id. Pins the rule to the lowest-numbered channel mapped to it; omit to match on any channel.', type: 'string', example: 'news.us')]
    #[BodyParameter('channel_id', 'Channel to pin the rule to. Takes precedence over tvg_id.', type: 'integer')]
    #[BodyParameter('mode', 'all records every airing, new only airings flagged as new.', type: 'string', default: 'all', example: 'all')]
    #[BodyParameter('title_mode', 'exact, contains, or search (treated as contains). regex is rejected.', type: 'string', default: 'exact')]
    public function storeSeriesRule(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $rule = $this->buildSeriesRule($scope, $request);

        if ($rule instanceof JsonResponse) {
            return $rule;
        }

        $existing = $scope->rules()
            ->where('dvr_setting_id', $rule->dvr_setting_id)
            ->where('type', DvrRuleType::Series)
            ->where('normalized_title', SeriesKey::normalize($rule->series_title))
            ->first();

        if ($existing) {
            $existing->update($rule->only(['channel_id', 'series_title', 'match_mode', 'series_mode', 'new_only', 'enabled']));
        } else {
            $rule->save();
        }

        return response()->json(['success' => true, 'rules' => $this->formattedSeriesRules($scope)]);
    }

    /**
     * Delete a series rule
     *
     * Deletes the matching rule(s) and cancels their upcoming scheduled recordings.
     * Recordings that already captured footage are kept.
     */
    #[QueryParameter('title', 'Series title of the rule to delete. Takes precedence over tvg_id. Either title or tvg_id is required.', type: 'string')]
    #[QueryParameter('tvg_id', 'Delete the rules pinned to channels mapped to this EPG channel id. Either title or tvg_id is required.', type: 'string')]
    public function destroySeriesRule(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $tvgId = trim((string) $request->query('tvg_id', ''));
        $title = trim((string) $request->query('title', ''));

        // Without an identity the rule query is unfiltered and would delete every rule.
        if ($tvgId === '' && $title === '') {
            return response()->json(['error' => 'tvg_id or title is required'], 400);
        }

        $rules = $this->matchingSeriesRules($scope, $tvgId, $title)->get();

        $removed = 0;
        foreach ($rules as $rule) {
            $removed += $this->cancelScheduled(
                $scope->recordings()->where('dvr_recording_rule_id', $rule->id)
            );
            $rule->delete();
        }

        return response()->json([
            'success' => true,
            'rules' => $this->formattedSeriesRules($scope),
            'removed' => $removed,
        ]);
    }

    /**
     * Preview series rule matches
     *
     * Same body as creating a series rule plus an optional `limit` (default 25, max
     * 100). Returns the upcoming airings in the next 7 days the rule would match,
     * without saving anything. `will_record` is false for airings the rule would
     * skip because that episode is already recorded or scheduled.
     *
     * @response array{matches: SeriesRuleMatchResource[], total: int, limit: int, epg_found: true, warn: bool}
     */
    #[BodyParameter('title', 'Series title to match.', required: true, type: 'string', example: 'Evening News')]
    #[BodyParameter('tvg_id', 'EPG channel id. Pins the rule to the lowest-numbered channel mapped to it; omit to match on any channel.', type: 'string', example: 'news.us')]
    #[BodyParameter('channel_id', 'Channel to pin the rule to. Takes precedence over tvg_id.', type: 'integer')]
    #[BodyParameter('mode', 'all records every airing, new only airings flagged as new.', type: 'string', default: 'all', example: 'all')]
    #[BodyParameter('title_mode', 'exact, contains, or search (treated as contains). regex is rejected.', type: 'string', default: 'exact')]
    #[BodyParameter('limit', 'Maximum matches to return (1-100).', type: 'integer', default: 25)]
    public function previewSeriesRule(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $rule = $this->buildSeriesRule($scope, $request);

        if ($rule instanceof JsonResponse) {
            return $rule;
        }

        $limit = max(1, min((int) ($request->input('limit') ?: 25), 100));
        $result = $this->scheduler->matchSeriesRuleDryRun($rule, 7 * 24 * 60);
        $willRecordIds = array_flip($result['scheduled'] ?? []);
        $matchedIds = [...($result['scheduled'] ?? []), ...($result['skipped'] ?? [])];

        $programmes = EpgProgramme::whereIn('id', $matchedIds)
            ->orderBy('start_time')
            ->limit($limit)
            ->get();

        return response()->json([
            'matches' => $programmes->map(fn (EpgProgramme $programme) => new SeriesRuleMatchResource(
                $programme,
                willRecord: isset($willRecordIds[$programme->id]),
            ))->values(),
            'total' => count($matchedIds),
            'limit' => $limit,
            'epg_found' => true,
            'warn' => count($matchedIds) > 50,
        ]);
    }

    /**
     * Evaluate series rules
     *
     * Runs the scheduler for the series rules in scope (optionally only those pinned
     * to channels mapped to `tvg_id`) and reports how many recordings were added.
     */
    #[BodyParameter('tvg_id', 'Only evaluate rules pinned to channels mapped to this EPG channel id.', type: 'string')]
    public function evaluateSeriesRules(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $tvgId = trim((string) $request->input('tvg_id', ''));

        $rules = $this->matchingSeriesRules($scope, $tvgId, null)
            ->where('enabled', true)
            ->with('channel.epgChannel')
            ->get();

        $details = [];
        $totalScheduled = 0;

        foreach ($rules as $rule) {
            $before = $rule->recordings()->count();
            $this->scheduler->scheduleRuleImmediately($rule);
            $created = $rule->recordings()->count() - $before;
            $totalScheduled += $created;

            $details[] = [
                'tvg_id' => $rule->channel?->epgChannel?->channel_id ?? '',
                'title' => $rule->series_title,
                'status' => 'ok',
                'created' => $created,
            ];
        }

        if ($tvgId !== '' && $rules->isEmpty()) {
            $details[] = ['tvg_id' => $tvgId, 'status' => 'no_rule'];
        }

        return response()->json(['success' => true, 'scheduled' => $totalScheduled, 'details' => $details]);
    }

    /**
     * Cancel upcoming recordings for a series
     *
     * Body: {tvg_id, title?, scope: "title"|"channel"}. Cancels upcoming scheduled
     * recordings without touching the rule itself.
     */
    #[BodyParameter('tvg_id', 'EPG channel id. Either tvg_id or title is required.', type: 'string')]
    #[BodyParameter('title', 'Series title. Either tvg_id or title is required.', type: 'string')]
    #[BodyParameter('scope', 'title limits removal to the series title, channel removes every upcoming recording on the tvg_id channels (tvg_id required).', type: 'string', default: 'title')]
    public function bulkRemoveSeriesRecordings(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $tvgId = trim((string) $request->input('tvg_id', ''));
        $title = trim((string) $request->input('title', ''));
        $removeScope = strtolower((string) ($request->input('scope') ?: 'title'));

        if ($tvgId === '' && $title === '') {
            return response()->json(['error' => 'tvg_id or title is required'], 400);
        }

        if (! in_array($removeScope, ['title', 'channel'], true)) {
            return response()->json(['error' => "scope must be 'title' or 'channel'"], 400);
        }

        // scope=channel ignores the title, so without a tvg_id nothing would narrow the query.
        if ($removeScope === 'channel' && $tvgId === '') {
            return response()->json(['error' => 'tvg_id is required when scope is channel'], 400);
        }

        $query = $scope->recordings()->where('scheduled_start', '>', now());

        if ($tvgId !== '') {
            $query->whereIn('channel_id', $scope->channelIdsForTvgId($tvgId));
        }

        if ($removeScope === 'title' && $title !== '') {
            $query->where('normalized_title', SeriesKey::normalize($title));
        }

        return response()->json(['success' => true, 'removed' => $this->cancelScheduled($query)]);
    }

    /**
     * Validate a Dispatcharr series-rule payload and build the (unsaved) native rule.
     * Shared by create and preview so both accept and reject exactly the same input.
     */
    private function buildSeriesRule(DvrAccessScope $scope, Request $request): DvrRecordingRule|JsonResponse
    {
        $title = trim((string) $request->input('title', ''));
        $mode = strtolower((string) ($request->input('mode') ?: 'all'));
        $titleMode = strtolower((string) ($request->input('title_mode') ?: 'exact'));
        $tvgId = trim((string) $request->input('tvg_id', ''));

        if (! in_array($mode, ['all', 'new'], true)) {
            return response()->json(['error' => "mode must be 'all' or 'new'"], 400);
        }

        if (! in_array($titleMode, ['exact', 'contains', 'search', 'regex'], true)) {
            return response()->json(['error' => 'title_mode must be one of exact, contains, search, regex'], 400);
        }

        if ($titleMode === 'regex') {
            return response()->json(['error' => 'regex title matching is not supported'], 400);
        }

        if (trim((string) $request->input('description', '')) !== '') {
            return response()->json(['error' => 'Description matching is not supported'], 400);
        }

        if ($title === '') {
            return response()->json(['error' => 'A title is required'], 400);
        }

        $channelId = $request->filled('channel_id') ? (int) $request->input('channel_id') : null;

        if ($channelId !== null && ! $scope->findChannel($channelId)) {
            return response()->json(['error' => 'channel_id does not exist'], 400);
        }

        if ($channelId === null && $tvgId !== '') {
            $channelId = $scope->channelIdsForTvgId($tvgId)[0] ?? null;

            if ($channelId === null) {
                return response()->json(['error' => 'No channel is mapped to this tvg_id'], 400);
            }
        }

        $dvrSetting = $scope->settingForWrite($channelId);

        if (! $dvrSetting?->enabled) {
            return response()->json(['error' => 'DVR is not enabled for this playlist'], 400);
        }

        $seriesMode = $mode === 'new' ? DvrSeriesMode::NewFlag : DvrSeriesMode::All;

        return new DvrRecordingRule([
            'user_id' => $dvrSetting->user_id,
            'dvr_setting_id' => $dvrSetting->id,
            'type' => DvrRuleType::Series,
            'channel_id' => $channelId,
            'series_title' => $title,
            'match_mode' => $titleMode === 'exact' ? DvrMatchMode::Exact : DvrMatchMode::Contains,
            'series_mode' => $seriesMode,
            // Keep the legacy new_only flag in lockstep with series_mode - see
            // XtreamApiController::createDvrSeriesRule().
            'new_only' => $seriesMode === DvrSeriesMode::NewFlag,
            'keep_last' => $dvrSetting->default_series_keep_last,
            'enabled' => true,
        ]);
    }

    private function scope(Request $request): DvrAccessScope
    {
        return $request->attributes->get('dvr_scope');
    }

    /**
     * Recordings that make sense to a Dispatcharr client. Cancelled rows (a
     * Dispatcharr cancel deletes the row) and purged rows (file removed by
     * retention) are hidden.
     *
     * @return Builder<DvrRecording>
     */
    private function visibleRecordings(DvrAccessScope $scope): Builder
    {
        return $scope->recordings()->whereNotIn('status', [
            DvrRecordingStatus::Cancelled,
            DvrRecordingStatus::Purged,
        ]);
    }

    private function findRecording(Request $request, int $id): ?DvrRecording
    {
        return $this->visibleRecordings($this->scope($request))
            ->with(['channel.epgChannel', ...self::OWNER_RELATIONS])
            ->whereKey($id)
            ->first();
    }

    /**
     * Series rules in scope matching a Dispatcharr (tvg_id, title) identity. The
     * title decides when given, since m3u-editor keeps one series rule per show;
     * otherwise tvg_id selects the rules pinned to channels mapped to it.
     *
     * @return Builder<DvrRecordingRule>
     */
    private function matchingSeriesRules(DvrAccessScope $scope, string $tvgId, ?string $title): Builder
    {
        $query = $scope->rules()->where('type', DvrRuleType::Series);

        if ($title !== null && trim($title) !== '') {
            return $query->where('normalized_title', SeriesKey::normalize($title));
        }

        if ($tvgId !== '') {
            $query->whereIn('channel_id', $scope->channelIdsForTvgId($tvgId));
        }

        return $query;
    }

    /**
     * Cancel every still-Scheduled recording in the query.
     *
     * @param  Builder<DvrRecording>  $query
     */
    private function cancelScheduled(Builder $query): int
    {
        $cancelled = 0;

        $query->where('status', DvrRecordingStatus::Scheduled)
            ->lazyById()
            ->each(function (DvrRecording $recording) use (&$cancelled): void {
                $this->recorder->cancel($recording);
                $cancelled++;
            });

        return $cancelled;
    }

    private function formattedSeriesRules(DvrAccessScope $scope): AnonymousResourceCollection
    {
        return SeriesRuleResource::collection(
            $scope->rules()
                ->where('type', DvrRuleType::Series)
                ->with('channel.epgChannel')
                ->orderBy('created_at')
                ->get()
        );
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['detail' => 'Not found.'], 404);
    }

    private function nonFieldError(string $message): JsonResponse
    {
        return response()->json(['non_field_errors' => [$message]], 400);
    }
}
