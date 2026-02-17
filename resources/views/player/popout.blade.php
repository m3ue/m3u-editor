<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $channelTitle }} - Player</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-black text-white">
    <main class="flex h-screen flex-col">
        <header class="flex items-center justify-between border-b border-white/10 bg-black/80 px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                @if($channelLogo)
                    <img src="{{ $channelLogo }}" alt="{{ $channelTitle }}" class="h-8 w-8 rounded object-cover"
                        onerror="this.style.display='none'">
                @endif
                <div class="min-w-0">
                    <h1 class="truncate text-sm font-semibold sm:text-base">{{ $channelTitle }}</h1>
                    <p class="text-xs text-white/70">{{ strtoupper($streamFormat) }} Stream</p>
                </div>
            </div>
        </header>

        <section class="relative flex-1 overflow-hidden group">
            <video id="popout-player" class="h-full w-full" controls autoplay preload="metadata"
                data-url="{{ $streamUrl }}" data-format="{{ $streamFormat }}">
                <p class="p-4">Your browser does not support video playback.</p>
            </video>

            <div id="popout-player-loading"
                class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-50">
                <div class="flex items-center gap-2 text-sm">
                    <svg class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                        </circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z">
                        </path>
                    </svg>
                    <span>Loading stream...</span>
                </div>
            </div>

            <div id="popout-player-error"
                class="absolute inset-0 hidden items-center justify-center bg-black bg-opacity-75">
                <div class="p-4 text-center">
                    <h2 class="text-lg font-semibold">Playback Error</h2>
                    <p id="popout-player-error-message" class="mt-2 text-sm text-white/75">Unable to load the stream.
                    </p>
                    <button type="button" onclick="retryStream('popout-player')"
                        class="mt-4 rounded bg-blue-600 px-4 py-2 text-sm font-medium hover:bg-blue-500">
                        Retry
                    </button>
                </div>
            </div>

            <div id="popout-player-details-overlay"
                class="absolute top-2 left-2 hidden max-w-xs rounded bg-black/90 p-3 text-xs text-white z-10">
                <div class="mb-2 flex items-center justify-between">
                    <span class="font-medium">Stream Details</span>
                    <button type="button" onclick="toggleStreamDetails('popout-player')"
                        class="text-white/70 hover:text-white">
                        <x-heroicon-o-x-mark class="w-3 h-3" />
                    </button>
                </div>
                <div id="popout-player-details" class="space-y-1">
                    <div class="text-white/60">Loading stream details...</div>
                </div>
            </div>

            <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                <button type="button" onclick="toggleStreamDetails('popout-player')"
                    class="rounded bg-black/75 hover:bg-black/90 px-2 py-1 text-xs text-white transition-colors"
                    title="Toggle Stream Details">
                    <x-heroicon-o-information-circle class="w-4 h-4" />
                </button>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!window.streamPlayer) {
                return;
            }

            const videoElement = document.getElementById('popout-player');
            if (!videoElement) {
                return;
            }

            const streamUrl = videoElement.dataset.url ?? '';
            const streamFormat = videoElement.dataset.format ?? 'ts';

            const player = window.streamPlayer();
            player.initPlayer(streamUrl, streamFormat, 'popout-player');

            window.addEventListener('beforeunload', () => {
                if (typeof player.cleanup === 'function') {
                    player.cleanup();
                }
            });
        });
    </script>
</body>

</html>