<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use AshAllenDesign\ShortURL\Classes\Builder;
use Illuminate\Support\Facades\DB;

trait ShortUrlTrait
{
    /**
     * Generate a short URL.
     *
     * @return Model
     */
    public function generateShortUrl(): Model
    {
        if ($this->short_urls_enabled) {
            $urls = [
                'm3u' => route('playlist.generate', ['uuid' => $this->uuid]),
                'hdhr' => route('playlist.hdhr.overview', ['uuid' => $this->uuid]),
                'epg' => route('epg.generate', ['uuid' => $this->uuid]),
                'epg_zip' => route('epg.generate.compressed', ['uuid' => $this->uuid]),
            ];
            $short_urls = [];
            foreach ($urls as $type => $url) {
                $short = app(Builder::class)->destinationUrl($url)->make();
                $short_urls[] = [
                    'id' => $short->id,
                    'type' => $type,
                    'key' => $short->url_key,
                ];
            }
            $this->short_urls = $short_urls;
        } else {
            // Delete the short URLs that contain the playlist UUID
            DB::table('short_urls')
                ->where('destination_url', 'LIKE', "%{$this->uuid}%")
                ->delete();

            // Set the short URLs to null
            $this->short_urls = null;
        }

        return $this;
    }

    /**
     * Remove short URLs.
     *
     * @return Model
     */
    public function removeShortUrls(): Model
    {
        // Delete the short URLs that contain the playlist UUID
        DB::table('short_urls')
            ->where('destination_url', 'LIKE', "%{$this->uuid}%")
            ->delete();

        // Set the short URLs to null
        $this->short_urls = null;

        return $this;
    }
}
