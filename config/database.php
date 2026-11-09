<?php

use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'cache_size' => 10000,
            'busy_timeout' => 30000,
        ],

        'sqlite_testing' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'jobs' => [
            'driver' => 'sqlite',
            'url' => null,
            'database' => database_path('jobs.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'cache_size' => 10000,
            'busy_timeout' => 30000,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'm3ue'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', 'root'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'm3ue'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', 'root'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'm3ue'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', 'root'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'timezone' => env('DB_TIMEZONE', env('TZ', 'UTC')),
        ],

        'pg_test' => [
            'driver' => 'pgsql',
            'url' => env('TEST_DB_URL'), // Don't use DB_URL - it connects to production!
            'host' => env('TEST_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TEST_DB_PORT', env('DB_PORT', '5432')),
            'database' => env('TEST_DB_DATABASE', 'm3ue_test'), // SEPARATE test database
            'username' => env('TEST_DB_USERNAME', env('DB_USERNAME', 'postgres')),
            'password' => env('TEST_DB_PASSWORD', env('DB_PASSWORD', 'root')),
            'charset' => env('TEST_DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'm3ue'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', 'root'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'm3u_editor_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_SERVER_PORT', '36790'),
            'database' => env('REDIS_DB', '0'),
        ],

        // Add cache connection
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_SERVER_PORT', '36790'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        // Add import connection
        'import' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_SERVER_PORT', '36790'),
            'database' => env('REDIS_IMPORT_DB', '3'),
        ],

        // Add file sync connection
        'file_sync' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_SERVER_PORT', '36790'),
            'database' => env('REDIS_FILE_SYNC_DB', '4'),
        ],

        // Add session connection
        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_SERVER_PORT', '36790'),
            'database' => env('REDIS_SESSION_DB', '5'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Backup/Restore Client Version
    |--------------------------------------------------------------------------
    |
    | The image bundles PostgreSQL 17 and 18 client tools. Set PG_CLIENT_VERSION
    | to the major version backup/restore should use when it differs from the
    | bundled default (17) - never auto-detected. start-container sets this
    | automatically to match the active embedded Postgres major; it only
    | needs to be set by hand when connecting to an external server. See
    | App\Services\PostgresDumpBinaryResolver.
    |
    */

    'pg_client_version' => env('PG_CLIENT_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Embedded PostgreSQL Status File
    |--------------------------------------------------------------------------
    |
    | Written by docker/8.4/pg-cluster.sh on every boot when embedded Postgres
    | is enabled: which major is active, the result of the last upgrade
    | attempt, and any warning. See App\Services\EmbeddedPostgresStatus.
    |
    | Not configurable via env(): pg-cluster.sh always writes this next to
    | PG_MOUNT, which is itself a fixed path (not an env var) in
    | start-container. An env override here would silently do nothing, since
    | the shell writer would never read it.
    |
    */

    'embedded_postgres_status_path' => '/var/lib/postgresql/data/.m3ue-postgres-status.json',

];
