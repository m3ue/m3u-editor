<?php

namespace App\Services;

use App\Enums\PlaylistChannelId;

/**
 * Assigns output channel numbers in output order, shared by every output
 * (M3U, HDHR, EPG, Xtream) so a channel gets the same number, and so the same
 * number-based tvg-id, everywhere it's listed.
 *
 * A channel keeps its own number unless the playlist forces sequential numbering,
 * or it has none and the playlist auto-increments (or identifies channels by
 * number), in which case it takes the next number in the sequence.
 */
class ChannelNumberSequence
{
    private int $current;

    private bool $force;

    private bool $autoIncrement;

    private bool $identifiesByNumber;

    /**
     * @param  mixed  $playlist  Playlist, CustomPlaylist, MergedPlaylist or PlaylistAlias
     */
    public function __construct($playlist)
    {
        $this->force = (bool) $playlist->force_channel_numbering;
        $this->autoIncrement = (bool) $playlist->auto_channel_increment;
        $this->identifiesByNumber = $playlist->id_channel_by === PlaylistChannelId::Number;
        $this->current = ($this->autoIncrement || $this->force) ? (int) $playlist->channel_start - 1 : 0;
    }

    /**
     * Resolve the output number for the next channel in output order, given its own
     * number (the custom playlist pivot number when set, else the channel's number).
     */
    public function next(int|string|null $channelNumber): int|string|null
    {
        if ($this->force || (! $channelNumber && ($this->autoIncrement || $this->identifiesByNumber))) {
            return ++$this->current;
        }

        return $channelNumber;
    }

    /**
     * Take the next number in the sequence unconditionally (e.g. for M3U series
     * episodes, which are always numbered after the channels).
     */
    public function advance(): int
    {
        return ++$this->current;
    }

    /**
     * Whether a channel's output number can depend on the channels output before
     * it, rather than only on its own number.
     */
    public function isPositional(): bool
    {
        return $this->force || $this->autoIncrement || $this->identifiesByNumber;
    }

    /**
     * Output numbers for a subset of channels, as they are numbered in the full
     * output, keyed by channel id. Used when serving a filtered or paginated
     * listing (e.g. one Xtream category, one EPG viewer page) so its numbers match
     * the full output instead of restarting from the start number.
     *
     * @param  mixed  $playlist  Playlist, CustomPlaylist, MergedPlaylist or PlaylistAlias
     * @param  mixed  $fullQuery  The unfiltered, output-ordered channel query (see PlaylistGenerateController::getChannelQuery())
     * @param  mixed  $subsetQuery  The filtered query whose channels need numbers
     * @return array<int, int|string|null>
     */
    public static function numbersFor($playlist, $fullQuery, $subsetQuery, bool $isCustomContext): array
    {
        // select() (unlike pluck() alone) also drops the select bindings of the
        // query's correlated subquery columns, which would otherwise misalign.
        $wanted = (clone $subsetQuery)->select('channels.id')->pluck('channels.id')->flip()->all();
        $numbers = [];
        if (empty($wanted)) {
            return $numbers;
        }

        // Only the id and numbers are needed, so walk a light copy of the query.
        $query = (clone $fullQuery)->select('channels.id', 'channels.channel');
        if ($isCustomContext) {
            $query->addSelect('channel_custom_playlist.channel_number as pivot_channel_number');
        }

        $sequence = new self($playlist);
        foreach ($query->toBase()->cursor() as $row) {
            $channelNo = ($isCustomContext && ! empty($row->pivot_channel_number))
                ? (int) $row->pivot_channel_number
                : ($row->channel === null ? null : (int) $row->channel);
            $channelNo = $sequence->next($channelNo);

            if (isset($wanted[$row->id])) {
                $numbers[$row->id] = $channelNo;
                if (count($numbers) === count($wanted)) {
                    break;
                }
            }
        }

        return $numbers;
    }
}
