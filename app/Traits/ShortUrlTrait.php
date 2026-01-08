<?php

namespace App\Traits;

use AshAllenDesign\ShortURL\Classes\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait ShortUrlTrait
{
    /**
     * Generate a short URL.
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
            return $this->removeShortUrls();
        }

        return $this;
    }

    /**
     * Remove short URLs.
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
