<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NullType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * Documents the responses of `/player_api.php`.
 *
 * One URL returns a completely different shape per `action`, which Scramble can't infer
 * from XtreamApiController::handle(). Each shape is registered as a named schema built
 * from a representative example (captured from real responses), and the 200 response
 * is documented as any one of them. Error responses are left to Scramble's inference.
 */
class DocumentXtreamApiResponses implements DocumentTransformer
{
    private const PLAYER_API_PATH = 'player_api.php';

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $operation = $this->playerApiOperation($document);

        if (! $operation) {
            return;
        }

        $actionSchemas = [];
        foreach (self::actionResponses() as $schemaName => $actionResponse) {
            $type = (isset($actionResponse['item'])
                ? (new ArrayType)->setItems($this->itemSchema($document, $actionResponse['item'], $actionResponse['example'][0]))
                : $this->typeFromExample($actionResponse['example']))
                ->setDescription("Returned by `{$actionResponse['actions']}`. {$actionResponse['description']}")
                ->examples([$actionResponse['example']]);

            $actionSchemas[] = $document->components->addSchema($schemaName, Schema::fromType($type));
        }

        // Only the 200 is replaced: Scramble infers the error responses (both the plain
        // `{error}` shape and the `{api_version, error: {code, message}}` request-error
        // shape) correctly from the controller.
        $operation->responses = collect($operation->responses)
            ->reject(fn (mixed $response) => $response instanceof Response && $response->code === 200)
            ->prepend(Response::make(200)
                ->setDescription('The response shape depends on `action`.')
                ->setContent('application/json', Schema::fromType((new AnyOf)->setItems($actionSchemas))))
            ->values()
            ->all();
    }

    private function playerApiOperation(OpenApi $document): ?Operation
    {
        foreach ($document->paths as $path) {
            if (ltrim($path->path, '/') === self::PLAYER_API_PATH) {
                return $path->operations['get'] ?? null;
            }
        }

        return null;
    }

    /**
     * Register a list response's item as its own titled schema. Stoplight Elements names an
     * array option in the anyOf picker after its item's `title`, so without this every list
     * action shows up as a bare `array[object]`.
     *
     * @param  array<string, mixed>  $example
     */
    private function itemSchema(OpenApi $document, string $schemaName, array $example): Reference
    {
        return $document->components->addSchema(
            $schemaName,
            Schema::fromType($this->typeFromExample($example))->setTitle($schemaName),
        );
    }

    /**
     * Build a schema type mirroring an example value. Objects whose keys are all numeric
     * (e.g. episodes grouped by season number) are documented as maps. Example values
     * decide the types, so give every field a representative non-null, non-empty value.
     */
    private function typeFromExample(mixed $example): Type
    {
        if (is_array($example) && array_is_list($example)) {
            return (new ArrayType)->setItems($example === [] ? new StringType : $this->typeFromExample($example[0]));
        }

        if (is_array($example)) {
            $object = new ObjectType;

            if (collect(array_keys($example))->every(fn (int|string $key) => is_numeric($key))) {
                $object->additionalProperties = $this->typeFromExample(reset($example));

                return $object;
            }

            foreach ($example as $key => $value) {
                $object->addProperty((string) $key, $this->typeFromExample($value));
            }

            return $object->setRequired(array_map('strval', array_keys($example)));
        }

        return match (true) {
            is_bool($example) => new BooleanType,
            is_int($example) => new IntegerType,
            is_float($example) => new NumberType,
            is_null($example) => new NullType,
            default => new StringType,
        };
    }

    /**
     * Representative responses per action, keyed by schema name. List responses name their
     * item schema in `item`, which is built from the first example entry.
     *
     * @return array<string, array{actions: string, description: string, item?: string, example: array<mixed>}>
     */
    private static function actionResponses(): array
    {
        return [
            'XtreamPanel' => [
                'actions' => 'panel (default), get_user_info, get_account_info, get_server_info',
                'description' => 'Account and server details.',
                'example' => [
                    'user_info' => [
                        'username' => 'test_user',
                        'password' => 'test_pass',
                        'message' => '',
                        'auth' => 1,
                        'status' => 'Active',
                        'exp_date' => '1767225600',
                        'is_trial' => '0',
                        'active_cons' => '1',
                        'created_at' => '1640995200',
                        'max_connections' => '2',
                        'allowed_output_formats' => ['m3u8', 'ts'],
                    ],
                    'server_info' => [
                        'url' => 'https://example.com',
                        'port' => '443',
                        'https_port' => '443',
                        'server_protocol' => 'https',
                        'rtmp_port' => '8001',
                        'timestamp_now' => 1719187200,
                        'time_now' => '2025-06-20 12:00:00',
                        'timezone' => 'UTC',
                        'process' => true,
                    ],
                    'm3u_editor' => [
                        'version' => '0.13.0',
                        'features' => ['viewers', 'progress', 'dvr'],
                    ],
                ],
            ],
            'XtreamLiveStreams' => [
                'actions' => 'get_live_streams',
                'description' => 'Enabled live channels, optionally filtered by `category_id`.',
                'item' => 'XtreamLiveStream',
                'example' => [
                    [
                        'num' => 1,
                        'name' => 'CNN HD',
                        'stream_type' => 'live',
                        'stream_id' => 12345,
                        'stream_icon' => 'https://example.com/logos/cnn.png',
                        'epg_channel_id' => 'cnn.us',
                        'added' => '1640995200',
                        'category_id' => '1',
                        'category_ids' => [1],
                        'tv_archive' => 1,
                        'tv_archive_duration' => 7,
                        'custom_sid' => 'cnn-hd',
                        'thumbnail' => 'https://example.com/logos/cnn.png',
                        'direct_source' => '',
                    ],
                ],
            ],
            'XtreamVodStreams' => [
                'actions' => 'get_vod_streams',
                'description' => 'Enabled VOD channels (movies), optionally filtered by `category_id`.',
                'item' => 'XtreamVodStream',
                'example' => [
                    [
                        'num' => 1,
                        'name' => 'The Matrix',
                        'title' => 'The Matrix',
                        'year' => '1999',
                        'stream_type' => 'movie',
                        'stream_id' => 67890,
                        'stream_icon' => 'https://example.com/covers/matrix.jpg',
                        'rating' => '8.7',
                        'rating_5based' => 4.35,
                        'added' => '1640995200',
                        'category_id' => '3',
                        'category_ids' => [3],
                        'tmdb' => '603',
                        'tmdb_id' => 603,
                        'container_extension' => 'mkv',
                        'custom_sid' => 'the-matrix',
                        'direct_source' => '',
                    ],
                ],
            ],
            'XtreamSeries' => [
                'actions' => 'get_series',
                'description' => 'Enabled series, optionally filtered by `category_id`.',
                'item' => 'XtreamSeriesItem',
                'example' => [
                    [
                        'num' => 1,
                        'name' => 'Breaking Bad',
                        'series_id' => 101,
                        'cover' => 'https://example.com/covers/breaking_bad.jpg',
                        'plot' => 'A high school chemistry teacher turned meth cook...',
                        'cast' => 'Bryan Cranston, Aaron Paul',
                        'director' => 'Vince Gilligan',
                        'genre' => 'Crime, Drama',
                        'releaseDate' => '2008-01-20',
                        'last_modified' => '1640995200',
                        'rating' => '9.5',
                        'rating_5based' => 4.75,
                        'backdrop_path' => ['https://example.com/backdrops/breaking_bad.jpg'],
                        'tmdb' => '1396',
                        'tmdb_id' => 1396,
                        'youtube_trailer' => 'HhesaQXLuRY',
                        'episode_run_time' => '47',
                        'category_id' => '2',
                        'category_ids' => [2],
                    ],
                ],
            ],
            'XtreamSeriesInfo' => [
                'actions' => 'get_series_info',
                'description' => 'A series with its seasons, and its episodes grouped by season number.',
                'example' => [
                    'info' => [
                        'name' => 'Breaking Bad',
                        'cover' => 'https://example.com/covers/breaking_bad.jpg',
                        'plot' => 'A high school chemistry teacher turned meth cook...',
                        'cast' => 'Bryan Cranston, Aaron Paul',
                        'director' => 'Vince Gilligan',
                        'genre' => 'Crime, Drama',
                        'releaseDate' => '2008-01-20',
                        'last_modified' => '1640995200',
                        'rating' => '9.5',
                        'rating_5based' => 4.75,
                        'backdrop_path' => ['https://example.com/backdrops/breaking_bad.jpg'],
                        'tmdb' => '1396',
                        'tmdb_id' => 1396,
                        'youtube_trailer' => 'HhesaQXLuRY',
                        'episode_run_time' => '47',
                        'category_id' => '2',
                    ],
                    'episodes' => [
                        '1' => [
                            [
                                'id' => '1001',
                                'episode_num' => 1,
                                'title' => 'Pilot',
                                'container_extension' => 'mkv',
                                'info' => [
                                    'release_date' => '2008-01-20',
                                    'duration_secs' => 3480,
                                    'duration' => '00:58:00',
                                    'rating' => '8.2',
                                    'season' => '1',
                                    'tmdb_id' => '62085',
                                    'movie_image' => 'https://example.com/images/breaking_bad_s01e01.jpg',
                                    'cover_big' => 'https://example.com/images/breaking_bad_s01e01.jpg',
                                    'plot' => 'A chemistry teacher diagnosed with terminal cancer turns to manufacturing methamphetamine.',
                                ],
                                'added' => '1640995200',
                                'season' => 1,
                                'custom_sid' => '',
                                'stream_id' => 1001,
                                'direct_source' => '',
                            ],
                        ],
                    ],
                    'seasons' => [
                        [
                            'name' => 'Season 1',
                            'episode_count' => 7,
                            'overview' => '',
                            'air_date' => '2008-01-20',
                            'cover' => 'https://example.com/covers/breaking_bad_s01.jpg',
                            'cover_tmdb' => 'https://example.com/covers/breaking_bad_s01_tmdb.jpg',
                            'season_number' => 1,
                            'cover_big' => 'https://example.com/covers/breaking_bad_s01.jpg',
                            'releaseDate' => '2008-01-20',
                            'duration' => '0',
                        ],
                    ],
                ],
            ],
            'XtreamLiveCategories' => [
                'actions' => 'get_live_categories',
                'description' => 'Live categories.',
                'item' => 'XtreamLiveCategory',
                'example' => [
                    [
                        'category_id' => '1',
                        'category_name' => 'News',
                        'parent_id' => 0,
                    ],
                    [
                        'category_id' => '2',
                        'category_name' => 'Sports',
                        'parent_id' => 0,
                    ],
                ],
            ],
            'XtreamVodCategories' => [
                'actions' => 'get_vod_categories',
                'description' => 'VOD categories.',
                'item' => 'XtreamVodCategory',
                'example' => [
                    [
                        'category_id' => '1',
                        'category_name' => 'Action Movies',
                        'parent_id' => 0,
                    ],
                    [
                        'category_id' => '2',
                        'category_name' => 'Comedy Movies',
                        'parent_id' => 0,
                    ],
                ],
            ],
            'XtreamSeriesCategories' => [
                'actions' => 'get_series_categories',
                'description' => 'Series categories.',
                'item' => 'XtreamSeriesCategory',
                'example' => [
                    [
                        'category_id' => '1',
                        'category_name' => 'Drama Series',
                        'parent_id' => 0,
                    ],
                    [
                        'category_id' => '2',
                        'category_name' => 'Comedy Series',
                        'parent_id' => 0,
                    ],
                ],
            ],
            'XtreamShortEpg' => [
                'actions' => 'get_short_epg',
                'description' => 'The current and next `limit` programmes for `stream_id`.',
                'example' => [
                    'epg_listings' => [
                        [
                            'id' => '8037716',
                            'epg_id' => '8',
                            'title' => 'Morning News',
                            'subtitle' => '',
                            'lang' => 'en',
                            'start' => '2025-08-14 07:00:00',
                            'end' => '2025-08-14 07:15:00',
                            'description' => 'Latest morning news and updates',
                            'channel_id' => 'cnn.us',
                            'start_timestamp' => '1755154800',
                            'stop_timestamp' => '1755155700',
                            'now_playing' => 1,
                            'has_archive' => 0,
                        ],
                    ],
                ],
            ],
            'XtreamSimpleDataTable' => [
                'actions' => 'get_simple_data_table',
                'description' => 'The full guide for `stream_id`. `title` and `description` are base64 encoded.',
                'example' => [
                    'epg_listings' => [
                        [
                            'id' => '8037716',
                            'epg_id' => '8',
                            'title' => 'TW9ybmluZyBOZXdz',
                            'description' => 'TGF0ZXN0IG1vcm5pbmcgbmV3cyBhbmQgdXBkYXRlcw==',
                            'lang' => 'en',
                            'start' => '2025-08-14 07:00:00',
                            'end' => '2025-08-14 07:15:00',
                            'channel_id' => 'cnn.us',
                            'start_timestamp' => '1755154800',
                            'stop_timestamp' => '1755155700',
                            'now_playing' => 1,
                            'has_archive' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }
}
