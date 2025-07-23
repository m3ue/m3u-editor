<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Epg;
use App\Models\EpgChannel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use XMLReader;

class EpgApiController extends Controller
{
    /**
     * Get EPG data for viewing
     *
     * @param string $uuid
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getData(string $uuid, Request $request)
    {
        // Increase execution time for large EPG files
        set_time_limit(120);
        
        $epg = Epg::where('uuid', $uuid)->firstOrFail();

        // Get the date range for EPG data (default to current day)
        $startDate = $request->get('start_date', Carbon::now()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::parse($startDate)->addDay()->format('Y-m-d'));
        
        Log::info('EPG API Request', [
            'uuid' => $uuid,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'file_path' => $epg->file_path
        ]);
        
        // Get the EPG file path using Storage disk
        $filePath = Storage::disk('local')->path($epg->file_path);

        if (!file_exists($filePath)) {
            return response()->json(['error' => 'EPG file not found at: ' . $filePath], 404);
        }

        // Parse the XML and extract program data
        $channels = [];
        $programmes = [];

        try {
            // First pass: get channels
            $channelReader = new XMLReader();
            $channelReader->open('compress.zlib://' . $filePath);

            while (@$channelReader->read()) {
                if ($channelReader->nodeType == XMLReader::ELEMENT && $channelReader->name === 'channel') {
                    $channelId = trim($channelReader->getAttribute('id'));
                    $innerXML = $channelReader->readOuterXml();
                    $innerReader = new XMLReader();
                    $innerReader->xml($innerXML);

                    $channel = [
                        'id' => $channelId,
                        'display_name' => '',
                        'icon' => '',
                        'lang' => 'en'
                    ];

                    while (@$innerReader->read()) {
                        if ($innerReader->nodeType == XMLReader::ELEMENT) {
                            switch ($innerReader->name) {
                                case 'display-name':
                                    if (!$channel['display_name']) {
                                        $channel['display_name'] = trim($innerReader->readString());
                                        $channel['lang'] = trim($innerReader->getAttribute('lang')) ?: 'en';
                                    }
                                    break;
                                case 'icon':
                                    $channel['icon'] = trim($innerReader->getAttribute('src'));
                                    break;
                            }
                        }
                    }
                    $innerReader->close();

                    if ($channelId) {
                        $channels[$channelId] = $channel;
                    }
                }
            }
            $channelReader->close();

            // Second pass: get programmes for the date range
            $programmes = [];
            $programmeCount = 0;
            $filteredCount = 0;
            
            $programReader = new XMLReader();
            $programReader->open('compress.zlib://' . $filePath);

            $startTimestamp = Carbon::parse($startDate)->startOfDay();
            $endTimestamp = Carbon::parse($endDate)->endOfDay();
            
            Log::info('Starting programme parsing', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'start_timestamp' => $startTimestamp->toISOString(),
                'end_timestamp' => $endTimestamp->toISOString()
            ]);

            while (@$programReader->read()) {
                if ($programReader->nodeType == XMLReader::ELEMENT && $programReader->name === 'programme') {
                    $programmeCount++;
                    
                    // Add safety limit to prevent excessive processing
                    if ($programmeCount > 50000) {
                        Log::info('Programme processing limit reached', ['count' => $programmeCount]);
                        break;
                    }
                    
                    $channelId = trim($programReader->getAttribute('channel'));
                    $start = trim($programReader->getAttribute('start'));
                    $stop = trim($programReader->getAttribute('stop'));

                    if (!$channelId || !$start) {
                        continue;
                    }

                    // Parse the datetime format (YYYYMMDDHHMMSS +ZZZZ)
                    $startDateTime = $this->parseXmltvDateTime($start);
                    $stopDateTime = $stop ? $this->parseXmltvDateTime($stop) : null;

                    // Log first few programmes for debugging
                    if ($programmeCount <= 5) {
                        Log::info('Programme sample', [
                            'count' => $programmeCount,
                            'channel_id' => $channelId,
                            'start_raw' => $start,
                            'start_parsed' => $startDateTime ? $startDateTime->toISOString() : null,
                            'in_range' => $startDateTime && $startDateTime >= $startTimestamp && $startDateTime <= $endTimestamp
                        ]);
                    }

                    // Filter by date range
                    if ($startDateTime && ($startDateTime < $startTimestamp || $startDateTime > $endTimestamp)) {
                        continue;
                    }
                    
                    $filteredCount++;

                    $innerXML = $programReader->readOuterXml();
                    $innerReader = new XMLReader();
                    $innerReader->xml($innerXML);

                    $programme = [
                        'channel' => $channelId,
                        'start' => $startDateTime ? $startDateTime->toISOString() : null,
                        'stop' => $stopDateTime ? $stopDateTime->toISOString() : null,
                        'title' => '',
                        'desc' => '',
                        'category' => '',
                        'icon' => ''
                    ];

                    while (@$innerReader->read()) {
                        if ($innerReader->nodeType == XMLReader::ELEMENT) {
                            switch ($innerReader->name) {
                                case 'title':
                                    $programme['title'] = trim($innerReader->readString());
                                    break;
                                case 'desc':
                                    $programme['desc'] = trim($innerReader->readString());
                                    break;
                                case 'category':
                                    if (!$programme['category']) {
                                        $programme['category'] = trim($innerReader->readString());
                                    }
                                    break;
                                case 'icon':
                                    $programme['icon'] = trim($innerReader->getAttribute('src'));
                                    break;
                            }
                        }
                    }
                    $innerReader->close();

                    if ($programme['title']) {
                        if (!isset($programmes[$channelId])) {
                            $programmes[$channelId] = [];
                        }
                        $programmes[$channelId][] = $programme;
                    }
                }
            }
            $programReader->close();
            
            Log::info('Programme parsing complete', [
                'total_programmes' => $programmeCount,
                'filtered_programmes' => $filteredCount,
                'programmes_with_titles' => array_sum(array_map('count', $programmes)),
                'channels_with_programmes' => count($programmes)
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to parse EPG data: ' . $e->getMessage()], 500);
        }

        // Sort programmes by start time
        foreach ($programmes as &$channelProgrammes) {
            usort($channelProgrammes, function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
        }

        return response()->json([
            'epg' => [
                'id' => $epg->id,
                'name' => $epg->name,
                'uuid' => $epg->uuid,
            ],
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'channels' => $channels,
            'programmes' => $programmes,
        ]);
    }

    /**
     * Parse XMLTV datetime format
     *
     * @param string $datetime
     * @return Carbon|null
     */
    private function parseXmltvDateTime($datetime)
    {
        try {
            // Format: YYYYMMDDHHMMSS +ZZZZ or YYYYMMDDHHMMSS
            if (preg_match('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s*([+-]\d{4})?/', $datetime, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];
                $second = $matches[6];
                $timezone = $matches[7] ?? '+0000';

                $dateString = "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";
                
                // Convert timezone offset to proper format
                if (preg_match('/([+-])(\d{2})(\d{2})/', $timezone, $tzMatches)) {
                    $tzString = $tzMatches[1] . $tzMatches[2] . ':' . $tzMatches[3];
                    $dateString .= ' ' . $tzString;
                }

                return Carbon::parse($dateString);
            }
        } catch (\Exception $e) {
            // Return null if parsing fails
        }

        return null;
    }
}
