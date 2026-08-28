<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Epg;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use XMLReader;
use XMLWriter;

class EpgPlaylistPreprocessor
{
    /** @return array{path: string, channels: int, programmes: int, playlist_channels: int} */
    public function preprocess(Epg $epg, string $sourcePath): array
    {
        if (blank($epg->uuid)) {
            throw new RuntimeException(__('The EPG must have a UUID before it can be preprocessed.'));
        }

        $playlist = $epg->preprocessPlaylist;
        if (! $playlist || $playlist->user_id !== $epg->user_id) {
            throw new RuntimeException(__('The EPG preprocessing playlist is not accessible.'));
        }

        if (! ($playlist->import_prefs['preprocess'] ?? false)) {
            throw new RuntimeException(__('Enable playlist preprocessing and sync the playlist before using it to preprocess an EPG.'));
        }

        $channelIds = $this->playlistChannelIds($epg);
        if ($channelIds === []) {
            throw new RuntimeException(__('The selected playlist has no imported live channel IDs. Sync it after configuring its processing filters.'));
        }
        $displayNamePrefixes = $this->displayNamePrefixes($epg);

        $targetPath = Storage::disk('local')->path($epg->file_path);
        $temporaryPath = $targetPath.'.preprocessing';
        Storage::disk('local')->makeDirectory($epg->folder_path);
        File::delete($temporaryPath);

        try {
            $counts = $this->filterXmltv(
                $sourcePath,
                $temporaryPath,
                $channelIds,
                $displayNamePrefixes,
            );
        } catch (\Throwable $exception) {
            File::delete($temporaryPath);

            throw $exception;
        }

        if ($counts['channels'] === 0) {
            File::delete($temporaryPath);

            throw new RuntimeException($displayNamePrefixes === []
                ? __('None of the selected playlist channel IDs exist in this EPG.')
                : __('None of the selected playlist channel IDs have an XMLTV display-name matching the configured prefixes.'));
        }

        if (File::exists($targetPath)) {
            File::delete($targetPath);
        }
        File::move($temporaryPath, $targetPath);

        return [
            'path' => $targetPath,
            ...$counts,
            'playlist_channels' => count($channelIds),
        ];
    }

    /** @return array<string, true> */
    private function playlistChannelIds(Epg $epg): array
    {
        $channelIds = [];

        Channel::query()
            ->where('playlist_id', $epg->preprocess_playlist_id)
            ->where('is_vod', false)
            ->leftJoin('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->select([
                'channels.id',
                'channels.stream_id',
                'channels.stream_id_custom',
                'channels.source_id',
                'epg_channels.epg_id as mapped_epg_id',
                'epg_channels.channel_id as mapped_channel_id',
            ])
            ->cursor()
            ->each(function (Channel $channel) use (&$channelIds, $epg): void {
                $mappedChannelId = (int) $channel->mapped_epg_id === $epg->id
                    ? $channel->mapped_channel_id
                    : null;

                foreach ([$mappedChannelId, $channel->stream_id_custom, $channel->stream_id, $channel->source_id] as $channelId) {
                    $normalizedChannelId = $this->normalizeChannelId($channelId);
                    if ($normalizedChannelId !== '') {
                        $channelIds[$normalizedChannelId] = true;
                    }
                }
            });

        return $channelIds;
    }

    /** @return array{channels: int, programmes: int} */
    private function filterXmltv(string $sourcePath, string $targetPath, array $channelIds, array $displayNamePrefixes): array
    {
        $retainedChannelIds = $displayNamePrefixes === []
            ? $channelIds
            : $this->matchingDisplayNameChannelIds($sourcePath, $channelIds, $displayNamePrefixes);
        $reader = new XMLReader;
        $writer = new XMLWriter;

        if (! $reader->open('compress.zlib://'.$sourcePath, null, LIBXML_NONET | LIBXML_COMPACT)
            || ! $writer->openUri($targetPath)) {
            throw new RuntimeException(__('The EPG XMLTV file could not be opened for preprocessing.'));
        }

        $writer->startDocument('1.0', 'UTF-8');
        $rootWritten = false;
        $channelCount = 0;
        $programmeCount = 0;

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->name === 'tv' && ! $rootWritten) {
                    $writer->startElement('tv');
                    $this->copyAttributes($reader, $writer);
                    $rootWritten = true;

                    continue;
                }

                if ($reader->name === 'channel') {
                    $channelId = $this->normalizeChannelId($reader->getAttribute('id'));
                    $channelXml = $reader->readOuterXml();
                    if (! isset($retainedChannelIds[$channelId])
                        || ! $this->matchesDisplayNamePrefix($channelXml, $displayNamePrefixes)) {
                        continue;
                    }

                    $writer->writeRaw($channelXml);
                    $channelCount++;

                    continue;
                }

                if ($reader->name === 'programme'
                    && isset($retainedChannelIds[$this->normalizeChannelId($reader->getAttribute('channel'))])) {
                    $writer->writeRaw($reader->readOuterXml());
                    $programmeCount++;
                }
            }

            if (! $rootWritten) {
                throw new RuntimeException(__('The EPG source is not a valid XMLTV document.'));
            }

            $writer->endElement();
            $writer->endDocument();
            $writer->flush();
        } finally {
            $reader->close();
        }

        return ['channels' => $channelCount, 'programmes' => $programmeCount];
    }

    /**
     * @param  array<string, true>  $channelIds
     * @param  list<string>  $displayNamePrefixes
     * @return array<string, true>
     */
    private function matchingDisplayNameChannelIds(string $sourcePath, array $channelIds, array $displayNamePrefixes): array
    {
        $reader = new XMLReader;
        if (! $reader->open('compress.zlib://'.$sourcePath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException(__('The EPG XMLTV file could not be opened for display-name preprocessing.'));
        }

        $matchingChannelIds = [];

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'channel') {
                    continue;
                }

                $channelId = $this->normalizeChannelId($reader->getAttribute('id'));
                if (isset($channelIds[$channelId])
                    && $this->matchesDisplayNamePrefix($reader->readOuterXml(), $displayNamePrefixes)) {
                    $matchingChannelIds[$channelId] = true;
                }
            }
        } finally {
            $reader->close();
        }

        return $matchingChannelIds;
    }

    /** @return list<string> */
    private function displayNamePrefixes(Epg $epg): array
    {
        if (! $epg->preprocess_display_name_filter) {
            return [];
        }

        $prefixes = collect($epg->preprocess_display_name_prefixes)
            ->map(fn (mixed $prefix): string => $this->normalizeChannelId($prefix))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($prefixes === []) {
            throw new RuntimeException(__('Configure at least one XMLTV display-name prefix or disable display-name filtering.'));
        }

        return $prefixes;
    }

    /** @param list<string> $displayNamePrefixes */
    private function matchesDisplayNamePrefix(string $channelXml, array $displayNamePrefixes): bool
    {
        if ($displayNamePrefixes === []) {
            return true;
        }

        $document = new \DOMDocument;
        if (! @$document->loadXML($channelXml, LIBXML_NONET | LIBXML_COMPACT)) {
            return false;
        }

        foreach ($document->getElementsByTagName('display-name') as $displayName) {
            $normalizedDisplayName = $this->normalizeChannelId($displayName->textContent);
            foreach ($displayNamePrefixes as $prefix) {
                if (Str::startsWith($normalizedDisplayName, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function copyAttributes(XMLReader $reader, XMLWriter $writer): void
    {
        if (! $reader->moveToFirstAttribute()) {
            return;
        }

        do {
            $writer->writeAttribute($reader->name, $reader->value);
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();
    }

    private function normalizeChannelId(mixed $channelId): string
    {
        return Str::lower(trim((string) $channelId));
    }
}
