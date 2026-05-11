@php
    /**
     * @var \App\Models\Channel|\App\Models\Episode|null $record
     */
    use App\Services\StreamStatsService;

    $rawStats = is_array($record?->stream_stats ?? null) ? $record->stream_stats : [];
    $stats = StreamStatsService::normalize($rawStats);
    $hasStats = ! empty(array_filter($stats, fn ($v) => $v !== null && $v !== ''));

    $probedAt = $record?->stream_stats_probed_at ?? null;

    // Pull container bitrate from raw ffprobe output if it was stored as ['format' => [...]]
    $formatBitrate = null;
    foreach ($rawStats as $entry) {
        if (is_array($entry) && isset($entry['format']) && is_array($entry['format'])) {
            $formatBitrate = $entry['format']['bit_rate'] ?? null;
            break;
        }
    }

    $resolution = $stats['resolution'] ?? null;
    if (! $resolution && ($stats['width'] ?? null) && ($stats['height'] ?? null)) {
        $resolution = $stats['width'] . 'x' . $stats['height'];
    }

    $quality = $hasStats ? StreamStatsService::detectQuality($stats) : '';
    $videoCodec = $hasStats ? StreamStatsService::detectVideoCodec($stats) : '';
    $audioCodec = $hasStats ? StreamStatsService::detectAudio($stats) : '';
    $hdr = $hasStats ? StreamStatsService::detectHdr($stats) : '';

    $bitrateMbps = null;
    foreach ([$stats['bitrate'] ?? null, $stats['bit_rate'] ?? null, $formatBitrate] as $candidate) {
        if (is_numeric($candidate) && (int) $candidate > 0) {
            $bitrateMbps = round(((int) $candidate) / 1_000_000, 2);
            break;
        }
    }

    $fields = [
        'Detected Quality'  => $quality,
        'Detected Video'    => $videoCodec,
        'Detected Audio'    => $audioCodec,
        'Detected HDR'      => $hdr ?: ($hasStats ? 'SDR' : ''),
        'Resolution'        => $resolution,
        'Video Profile'     => $stats['video_profile'] ?? null,
        'Pixel Format'      => $stats['pix_fmt'] ?? null,
        'Bit Depth'         => isset($stats['bit_depth']) && $stats['bit_depth'] ? $stats['bit_depth'] . '-bit' : null,
        'Color Transfer'    => $stats['color_transfer'] ?? null,
        'Color Space'       => $stats['color_space'] ?? null,
        'Color Primaries'   => $stats['color_primaries'] ?? null,
        'Color Range'       => $stats['color_range'] ?? null,
        'Codec Tag'         => $stats['codec_tag_string'] ?? null,
        'Audio Channels'    => $stats['audio_channels'] ?? null,
        'Audio Profile'     => $stats['audio_profile'] ?? null,
        'Bitrate'           => $bitrateMbps !== null ? $bitrateMbps . ' Mbps' : null,
    ];

    $sideData = $stats['side_data_list'] ?? null;
    $sideDataLabels = [];
    if (is_array($sideData)) {
        foreach ($sideData as $sd) {
            if (! is_array($sd)) {
                continue;
            }
            $type = $sd['side_data_type'] ?? null;
            if (is_string($type) && $type !== '') {
                $sideDataLabels[] = $type;
            }
        }
    }
@endphp

@if ($hasStats)
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mt-2">
        @foreach ($fields as $label => $value)
            @if ($value !== null && $value !== '')
                <div>
                    <span class="text-sm text-gray-500">{{ $label }}</span>
                    <div class="font-medium">{{ $value }}</div>
                </div>
            @endif
        @endforeach
    </div>

    @if (! empty($sideDataLabels))
        <div class="mt-3">
            <span class="text-sm text-gray-500">Side Data</span>
            <div class="flex flex-wrap gap-1 mt-1">
                @foreach (array_unique($sideDataLabels) as $label)
                    <span class="inline-block text-xs px-2 py-0.5 rounded bg-gray-200 dark:bg-gray-700">
                        {{ $label }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    @if ($probedAt)
        <div class="mt-3 text-xs text-gray-500">
            {{ __('Last probed') }}: {{ \Illuminate\Support\Carbon::parse($probedAt)->diffForHumans() }}
        </div>
    @endif
@else
    <div class="text-sm text-gray-500 italic">
        {{ __('No probed stream data yet. Run a stream probe to populate this section.') }}
    </div>
@endif
