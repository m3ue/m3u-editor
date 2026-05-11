@php
    /**
     * @var \App\Models\Series $record
     */
    use App\Services\StreamStatsService;

    $probedEpisodes = $record
        ->episodes()
        ->whereNotNull('stream_stats_probed_at')
        ->get(['id', 'stream_stats', 'stream_stats_probed_at']);

    $totalEpisodes = $record->episodes()->count();
    $probedCount = $probedEpisodes->count();

    $aggregator = [];
    $fieldsToTrack = [
        'video_codec',
        'audio_codec',
        'resolution',
        'video_profile',
        'pix_fmt',
        'color_transfer',
        'color_space',
        'color_primaries',
        'codec_tag_string',
        'audio_channels',
        'audio_profile',
        'bit_depth',
    ];
    $hdrValues = [];
    $qualityValues = [];

    foreach ($probedEpisodes as $episode) {
        $raw = is_array($episode->stream_stats) ? $episode->stream_stats : [];
        $stats = StreamStatsService::normalize($raw);

        $hdrValues[] = StreamStatsService::detectHdr($stats) ?: 'SDR';
        $qualityValues[] = StreamStatsService::detectQuality($stats) ?: '';

        foreach ($fieldsToTrack as $field) {
            $value = $stats[$field] ?? null;
            if ($value !== null && $value !== '') {
                $aggregator[$field][] = is_scalar($value) ? (string) $value : json_encode($value);
            }
        }
    }

    $renderField = function (string $label, array $values) {
        $unique = array_values(array_unique($values));
        if (empty($unique)) {
            return null;
        }
        if (count($unique) === 1) {
            return ['label' => $label, 'value' => $unique[0], 'mixed' => false];
        }
        return ['label' => $label, 'value' => 'Mixed (' . count($unique) . ' variants)', 'mixed' => true];
    };

    $rows = [];
    $rows[] = $renderField('Detected Quality', $qualityValues);
    $rows[] = $renderField('Detected HDR', $hdrValues);
    foreach ($fieldsToTrack as $field) {
        $label = ucwords(str_replace('_', ' ', $field));
        $rows[] = $renderField($label, $aggregator[$field] ?? []);
    }
    $rows = array_filter($rows);
@endphp

@if ($probedCount > 0)
    <div class="mb-6">
        <x-filament::section icon="heroicon-o-cog-6-tooth" :heading="__('Probed Stream Info (Series Aggregate)')" :description="__(':probed of :total episodes probed. Mixed values mean episodes differ.', [
            'probed' => $probedCount,
            'total' => $totalEpisodes,
        ])" collapsible compact
            collapsed>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @foreach ($rows as $row)
                    <div>
                        <span class="text-sm text-gray-500">{{ $row['label'] }}</span>
                        <div class="font-medium {{ $row['mixed'] ? 'text-amber-600 dark:text-amber-400' : '' }}">
                            {{ $row['value'] }}
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 text-xs text-gray-500">
                {{ __('Open a season modal and click an episode for per-episode probed stream details.') }}
            </div>
        </x-filament::section>
    </div>
@endif
