<?php

declare(strict_types=1);
use Wnx\LaravelBackupRestore\HealthChecks\Checks\DatabaseHasTables;

return [

    /**
     * Health checks are run after a given backup has been restored.
     * With health checks, you can make sure that the restored database contains the data you expect.
     * By default, we check if the restored database contains any tables.
     *
     * You can add your own health checks by adding a class that extends the HealthCheck class.
     * The restore command will fail, if any health checks fail.
     */
    'health-checks' => [
        DatabaseHasTables::class,
    ],
];
