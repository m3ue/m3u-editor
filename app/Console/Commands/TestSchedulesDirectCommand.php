<?php

namespace App\Console\Commands;

use App\Models\Epg;
use App\Services\SchedulesDirectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestSchedulesDirectCommand extends Command
{
    private static string $USER_AGENT = 'm3u-editor/dev';

    protected $signature = 'app:schedules-direct-test {--epg=} {--username=} {--password=} {--country=USA} {--postal_code=60030} {--metadata}';

    protected $description = 'Test SchedulesDirect API connection and metadata endpoints';

    public function handle(SchedulesDirectService $service): int
    {
        // Set a more descriptive user agent
        self::$USER_AGENT = 'm3u-editor/'.config('dev.version');

        try {
            // Determine authentication method
            if ($epgId = $this->option('epg')) {
                $epg = Epg::find($epgId);
                if (! $epg) {
                    $this->error("EPG with ID {$epgId} not found");

                    return Command::FAILURE;
                }

                if (! $epg->sd_username || ! $epg->sd_password) {
                    $this->error("EPG {$epgId} does not have SchedulesDirect credentials configured");

                    return Command::FAILURE;
                }

                $this->info("Using EPG '{$epg->name}' credentials...");
                $authData = $service->authenticateFromEpg($epg);
            } elseif ($this->option('username') && $this->option('password')) {
                $username = $this->option('username');
                $password = $this->option('password');
                $this->info('Using provided credentials...');
                $authData = $service->authenticate($username, $password);
            } else {
                // Interactive EPG selection
                $epgs = Epg::whereNotNull('sd_username')
                    ->whereNotNull('sd_password')
                    ->get();

                if ($epgs->isEmpty()) {
                    $this->error('No EPGs with SchedulesDirect credentials found. Use --username and --password options or configure an EPG first.');

                    return Command::FAILURE;
                }

                $this->info('Available EPGs with SchedulesDirect credentials:');
                foreach ($epgs as $epg) {
                    $this->info("  [{$epg->id}] {$epg->name} ({$epg->sd_username})");
                }

                $epgId = $this->ask('Enter EPG ID to use');
                $epg = $epgs->find($epgId);

                if (! $epg) {
                    $this->error("Invalid EPG ID: {$epgId}");

                    return Command::FAILURE;
                }

                $this->info("Using EPG '{$epg->name}' credentials...");
                $authData = $service->authenticateFromEpg($epg);
            }

            // Authentication successful
            $this->info('✓ Authentication successful!');
            $this->info('Token: '.substr($authData['token'], 0, 20).'...');
            $this->info('Expires: '.date('Y-m-d H:i:s', $authData['expires']));

            $token = $authData['token'];

            // Test basic API endpoints
            $this->testBasicEndpoints($service, $token);

            // Test metadata endpoint if requested
            if ($this->option('metadata')) {
                $this->testMetadataEndpoints($token);
            }

            $this->info("\n✓ SchedulesDirect API test completed successfully");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Test failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function testBasicEndpoints(SchedulesDirectService $service, string $token): void
    {
        $country = $this->option('country');
        $postalCode = $this->option('postal_code');

        // Get status
        $this->info("\nTesting server status...");
        $status = $service->getStatus($token);
        $this->info('✓ Server status: '.($status['systemStatus'][0]['status'] ?? 'Unknown'));

        // Test countries
        $this->info("\nTesting countries...");
        $countries = $service->getCountries();
        $this->info('✓ Available countries: '.count($countries));
        foreach ($countries as $locale => $locations) {
            // Handle different possible response structures
            $this->line("  🌎 Country: {$locale}");
            foreach ($locations as $countryData) {
                $fullName = $countryData['fullName'] ?? $countryData['name'] ?? 'Unknown';
                $shortName = $countryData['shortName'] ?? $countryData['code'] ?? 'Unknown';
                $this->line("  - {$fullName} ({$shortName})");
            }
        }

        // Test headends
        $this->info("\nTesting headends for {$country} {$postalCode}...");
        $headends = $service->getHeadends($token, $country, $postalCode);
        $this->info('✓ Found '.count($headends).' headend(s)');

        // Display headends and test lineup preview
        foreach (array_slice($headends, 0, 2) as $headend) {
            // Handle different possible response structures for headends
            $headendId = $headend['headend'] ?? 'Unknown';
            $headendName = $headend['name'] ?? $headend['location'] ?? 'Unknown';
            $this->line("  Headend: {$headendId} - {$headendName}");

            if (! empty($headend['lineups'])) {
                foreach (array_slice($headend['lineups'], 0, 1) as $lineup) {
                    $lineupName = $lineup['name'] ?? 'Unknown';
                    $lineupId = $lineup['lineup'] ?? 'Unknown';
                    $this->line("    Lineup: {$lineupName} (ID: {$lineupId})");

                    try {
                        $preview = $service->previewLineup($token, $lineupId);
                        $this->line('    ✓ Preview loaded: '.count($preview['map'] ?? []).' channels');

                        // Show first few channels
                        if (! empty($preview['map'])) {
                            foreach (array_slice($preview['map'], 0, 3) as $channel) {
                                $stationId = $channel['stationID'] ?? 'Unknown';
                                $channelNum = $channel['channel'] ?? 'N/A';
                                $this->line("      - Ch {$channelNum}: {$stationId}");
                            }
                        }
                    } catch (\Exception $e) {
                        $this->line('    ✗ Preview failed: '.$e->getMessage());
                    }
                }
            } else {
                $this->line('    No lineups found for this headend');
            }
        }
    }

    private function testMetadataEndpoints(string $token): void
    {
        $this->info("\n=== METADATA ENDPOINT TESTING ===");

        // Try to get real program IDs from the configured EPG
        $sampleProgramIds = [];

        // Check if we have an EPG with configured stations
        if ($epgId = $this->option('epg')) {
            $epg = Epg::find($epgId);
            if ($epg && ! empty($epg->sd_station_ids) && ! empty($epg->sd_lineup_id)) {
                $this->line("Using EPG's configured lineup: {$epg->sd_lineup_id}");
                $sampleProgramIds = $this->getProgramIdsFromEpgStations($token, $epg);
            }
        }

        // Fallback to discovery method
        if (empty($sampleProgramIds)) {
            $this->line('Trying to discover program IDs from available lineups...');
            $sampleProgramIds = $this->getSampleProgramIds($token);
        }

        // Final fallback to test IDs
        if (empty($sampleProgramIds)) {
            $this->line('Could not get real program IDs, using test program IDs...');
            // Use some known test program IDs for metadata testing
            $sampleProgramIds = [
                'EP000000060003', // Generic test program ID
                'MV000012340000', // Generic movie test ID
                'EP000000060004',
                'EP000000060005',
                'SH000000010000',  // Generic show test ID
            ];
        }

        $this->info('Using sample program IDs: '.implode(', ', array_slice($sampleProgramIds, 0, 3)).'...');

        // Test different metadata endpoint formats
        $this->info("\nTesting various metadata endpoint formats...");

        $testCases = [
            'CORRECT FORMAT: /metadata/programs/ with trailing slash (per API docs)' => [
                'url' => 'https://json.schedulesdirect.org/20141201/metadata/programs/',
                'data' => array_slice($sampleProgramIds, 0, 3), // Use fewer IDs for testing
                'method' => 'POST',
            ],
            'API DOC ALTERNATIVE: GET /metadata/programs/{programID} for single program' => [
                'url' => 'https://json.schedulesdirect.org/20141201/metadata/programs/'.$sampleProgramIds[0],
                'data' => null,
                'method' => 'GET',
            ],
            'Verification: /programs endpoint (known working, shows hasImageArtwork)' => [
                'url' => 'https://json.schedulesdirect.org/20141201/programs',
                'data' => array_slice($sampleProgramIds, 0, 3),
                'method' => 'POST',
            ],
        ];

        foreach ($testCases as $description => $testCase) {
            $this->line("\n--- {$description} ---");
            $this->line("URL: {$testCase['url']}");

            try {
                $request = Http::withHeaders([
                    'User-Agent' => self::$USER_AGENT,
                    'token' => $token,
                ])->timeout(30);

                if ($testCase['method'] === 'POST' && $testCase['data']) {
                    $this->line('POST Data: '.json_encode($testCase['data']));
                    $response = $request->post($testCase['url'], $testCase['data']);
                } else {
                    $response = $request->get($testCase['url']);
                }

                $this->line('Status: '.$response->status());

                if ($response->successful()) {
                    $data = $response->json();
                    if (is_array($data)) {
                        $this->info('✅ SUCCESS - Response contains '.count($data).' items');

                        // Check for artwork in first item
                        if (! empty($data[0])) {
                            $firstItem = $data[0];
                            $this->analyzeResponseStructure($firstItem);

                            // If this is metadata/programs response, show artwork details
                            if (str_contains($testCase['url'], '/metadata/programs') && is_array($data)) {
                                $this->analyzeArtworkDetails($data);
                            }
                        }
                    } else {
                        $this->info('✅ SUCCESS - Response: '.substr(json_encode($data), 0, 200).'...');
                    }
                } else {
                    $errorBody = $response->body();
                    $this->error('❌ FAILED - '.$errorBody);

                    // Try to parse error
                    $errorData = json_decode($errorBody, true);
                    if ($errorData && isset($errorData['message'])) {
                        $this->error('  Error message: '.$errorData['message']);
                    }
                }
            } catch (\Exception $e) {
                $this->error('❌ EXCEPTION - '.$e->getMessage());
            }

            // Small delay between requests
            usleep(500000); // 500ms
        }
    }

    private function getSampleProgramIds(string $token): array
    {
        $this->info('Fetching sample program IDs...');

        try {
            // First, try to use account lineups that are already subscribed
            $this->line('Checking account lineups...');
            $accountLineups = Http::withHeaders([
                'User-Agent' => self::$USER_AGENT,
                'token' => $token,
            ])->get('https://json.schedulesdirect.org/20141201/lineups')->json();

            if (! empty($accountLineups)) {
                foreach ($accountLineups as $lineup) {
                    $lineupId = $lineup['lineup'] ?? null;
                    if (! $lineupId) {
                        continue;
                    }

                    try {
                        // Get actual lineup data (not preview)
                        $lineupData = Http::withHeaders([
                            'User-Agent' => self::$USER_AGENT,
                            'token' => $token,
                        ])->get("https://json.schedulesdirect.org/20141201/lineups/{$lineupId}")->json();

                        $stations = array_column($lineupData['map'] ?? [], 'stationID');
                        if (count($stations) >= 3) {
                            $stationIds = array_slice($stations, 0, 3);
                            $this->info("✓ Using account lineup {$lineupId} with ".count($stations).' stations');

                            return $this->fetchProgramIds($token, $stationIds, $lineupId);
                        }
                    } catch (\Exception $e) {
                        // Continue to next lineup
                        continue;
                    }
                }
            }

            // If no account lineups work, try adding a free lineup temporarily
            $this->line('No suitable account lineups found, trying free lineups...');

            // Try multiple postal codes to find a lineup with channels
            $testAreas = [
                ['country' => 'USA', 'postalcode' => '90210'], // Los Angeles
                ['country' => 'USA', 'postalcode' => '10001'], // New York
                ['country' => 'USA', 'postalcode' => '60601'], // Chicago
                ['country' => 'USA', 'postalcode' => '30301'], // Atlanta
            ];

            foreach ($testAreas as $area) {
                $this->line("Trying {$area['country']} {$area['postalcode']}...");

                $headends = Http::withHeaders([
                    'User-Agent' => self::$USER_AGENT,
                    'token' => $token,
                ])->get('https://json.schedulesdirect.org/20141201/headends', $area)->json();

                if (empty($headends)) {
                    continue;
                }

                // Look for free lineups (usually OTA - over the air)
                foreach ($headends as $headend) {
                    if (empty($headend['lineups'])) {
                        continue;
                    }

                    foreach ($headend['lineups'] as $lineupInfo) {
                        $lineupId = $lineupInfo['lineup'] ?? null;
                        $lineupName = $lineupInfo['name'] ?? '';

                        if (! $lineupId) {
                            continue;
                        }

                        // Look for OTA (over-the-air) lineups which are usually free
                        if (
                            stripos($lineupName, 'antenna') !== false ||
                            stripos($lineupName, 'over') !== false ||
                            stripos($lineupName, 'broadcast') !== false ||
                            stripos($lineupId, 'OTA') !== false
                        ) {

                            try {
                                // Try to add lineup temporarily
                                $addResponse = Http::withHeaders([
                                    'User-Agent' => self::$USER_AGENT,
                                    'token' => $token,
                                ])->put("https://json.schedulesdirect.org/20141201/lineups/{$lineupId}");

                                if ($addResponse->successful()) {
                                    // Get lineup data
                                    $lineupData = Http::withHeaders([
                                        'User-Agent' => self::$USER_AGENT,
                                        'token' => $token,
                                    ])->get("https://json.schedulesdirect.org/20141201/lineups/{$lineupId}")->json();

                                    $stations = array_column($lineupData['map'] ?? [], 'stationID');
                                    if (count($stations) >= 3) {
                                        $stationIds = array_slice($stations, 0, 3);
                                        $this->info("✓ Added and using lineup {$lineupId} with ".count($stations).' stations');

                                        try {
                                            return $this->fetchProgramIds($token, $stationIds, $lineupId);
                                        } finally {
                                            // Clean up - remove the lineup after testing
                                            Http::withHeaders([
                                                'User-Agent' => self::$USER_AGENT,
                                                'token' => $token,
                                            ])->delete("https://json.schedulesdirect.org/20141201/lineups/{$lineupId}");
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                // Continue trying other lineups
                                continue;
                            }
                        }
                    }
                }
            }

            throw new \Exception('Could not find any suitable lineup for testing');
        } catch (\Exception $e) {
            $this->error('Error getting sample program IDs: '.$e->getMessage());

            return [];
        }
    }

    private function fetchProgramIds(string $token, array $stationIds, string $lineupId): array
    {
        if (empty($stationIds)) {
            throw new \Exception('No station IDs provided');
        }

        $today = date('Y-m-d');

        $stationRequests = array_map(function ($stationId) use ($today) {
            return [
                'stationID' => $stationId,
                'date' => [$today],
            ];
        }, $stationIds);

        $this->line('Requesting schedules for '.count($stationIds)." stations from lineup {$lineupId}...");

        $response = Http::withHeaders([
            'User-Agent' => self::$USER_AGENT,
            'token' => $token,
        ])->timeout(60)->post('https://json.schedulesdirect.org/20141201/schedules', $stationRequests);

        if ($response->successful()) {
            $schedules = $response->json();
            $programIds = [];

            foreach ($schedules as $schedule) {
                if (isset($schedule['programs'])) {
                    foreach (array_slice($schedule['programs'], 0, 10) as $program) {
                        if (isset($program['programID'])) {
                            $programIds[] = $program['programID'];
                        }
                    }
                }
            }

            $programIds = array_unique($programIds);
            $this->info('✓ Found '.count($programIds).' unique program IDs');

            return array_slice($programIds, 0, 15); // Return first 15 for testing
        } else {
            throw new \Exception('Failed to get schedules: '.$response->body());
        }
    }

    private function analyzeResponseStructure(array $item): void
    {
        // Look for artwork-related fields
        $artworkKeys = ['artwork', 'images', 'data', 'metadata', 'hasImageArtwork', 'hasEpisodeArtwork'];
        $foundArtworkKeys = [];

        foreach ($artworkKeys as $key) {
            if (isset($item[$key])) {
                $foundArtworkKeys[] = $key;
            }
        }

        if (! empty($foundArtworkKeys)) {
            $this->info('  🎨 Found artwork-related fields: '.implode(', ', $foundArtworkKeys));

            // Show sample of artwork data
            foreach ($foundArtworkKeys as $key) {
                $value = $item[$key];
                if (is_bool($value)) {
                    $this->line("    {$key}: ".($value ? 'true' : 'false'));
                } elseif (is_string($value)) {
                    $this->line("    {$key}: ".substr($value, 0, 100).'...');
                } elseif (is_array($value)) {
                    $this->line("    {$key}: array with ".count($value).' items');
                }
            }
        } else {
            $this->line('  📝 Available fields: '.implode(', ', array_keys($item)));
        }
    }

    private function analyzeArtworkDetails(array $metadataResponse): void
    {
        $this->info('  🎨 Analyzing artwork categories and types...');

        $categoryCounts = [];
        $tierCounts = [];
        $sampleArtwork = [];

        foreach ($metadataResponse as $programData) {
            $programId = $programData['programID'] ?? 'unknown';
            $artworkItems = $programData['data'] ?? [];

            if (! empty($artworkItems)) {
                foreach ($artworkItems as $index => $artwork) {
                    $category = $artwork['category'] ?? 'unknown';
                    $tier = $artwork['tier'] ?? 'unknown';
                    $width = $artwork['width'] ?? 0;
                    $height = $artwork['height'] ?? 0;

                    $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
                    $tierCounts[$tier] = ($tierCounts[$tier] ?? 0) + 1;

                    // Collect sample artwork (first few items)
                    if (count($sampleArtwork) < 5) {
                        $sampleArtwork[] = [
                            'programId' => $programId,
                            'category' => $category,
                            'tier' => $tier,
                            'dimensions' => "{$width}x{$height}",
                            'ratio' => $artwork['ratio'] ?? 'unknown',
                        ];
                    }
                }
            }
        }

        $this->line('    Categories found: '.implode(', ', array_keys($categoryCounts)));
        $this->line('    Tiers found: '.implode(', ', array_keys($tierCounts)));

        if (! empty($sampleArtwork)) {
            $this->line('    Sample artwork:');
            foreach ($sampleArtwork as $sample) {
                $this->line("      - {$sample['category']} ({$sample['tier']}) {$sample['dimensions']} ratio:{$sample['ratio']}");
            }
        }
    }

    private function getProgramIdsFromEpgStations(string $token, Epg $epg): array
    {
        try {
            // Use the EPG's configured station IDs (just a few for testing)
            $stationIds = array_slice($epg->sd_station_ids, 0, 3);
            $this->line('Using '.count($stationIds).' stations from EPG configuration');

            return $this->fetchProgramIds($token, $stationIds, $epg->sd_lineup_id);
        } catch (\Exception $e) {
            $this->error('Error getting program IDs from EPG stations: '.$e->getMessage());

            return [];
        }
    }
}
