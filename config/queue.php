<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => 'redis', // env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // Must stay longer than the longest job 'timeout' in config/horizon.php (currently 60*125=7500s),
            // otherwise the queue can re-release a job to another worker while the first is still running it.
            'retry_after' => 60 * 130 + 5,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            // Must stay longer than the longest job 'timeout' in config/horizon.php (currently 60*125=7500s),
            // otherwise the queue can re-release a job to another worker while the first is still running it.
            'retry_after' => 60 * 130 + 5,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Must stay longer than the longest job 'timeout' in config/horizon.php (currently 60*125=7500s),
            // otherwise the queue can re-release a job to another worker while the first is still running it,
            // causing duplicate concurrent execution of the same job (and the memory usage that goes with it).
            'retry_after' => 60 * 130 + 5,
            'block_for' => null,
            'after_commit' => false,
        ],

        'plugin' => [
            'driver' => 'redis',
            // Dedicated queue key, not a dedicated Redis server - shares the same Redis
            // connection as the default queue unless REDIS_QUEUE_CONNECTION is set.
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'plugin-invocations',
            // Must stay longer than the plugin supervisor's job 'timeout' in config/horizon.php
            // (6h by default) so a running plugin invocation is never released to a second
            // worker mid-run. 305s of head-room, matching the margin on the redis connection above.
            'retry_after' => 60 * 60 * 6 + 305,
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
