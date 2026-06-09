<?php

use Checkpoint\Checks;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Checks
    |--------------------------------------------------------------------------
    |
    | Every default check is listed here and enabled by default. Set any
    | entry to `false` to exclude it from `php artisan checkpoint:scan`.
    |
    | Checks not listed in this map fall back to enabled — so when you
    | upgrade Checkpoint and new checks are added, you keep the protection
    | without re-publishing this file.
    |
    */

    'checks' => [
        Checks\ComposerAuditCheck::class => true,
        Checks\NpmAuditCheck::class => true,
        Checks\EnvironmentCheck::class => true,
        Checks\GitIgnoreCheck::class => true,
        Checks\FilePermissionsCheck::class => true,
        Checks\HardcodedSecretsCheck::class => true,
        Checks\SqlInjectionCheck::class => true,
        Checks\MassAssignmentCheck::class => true,
        Checks\XssCheck::class => true,
        Checks\CsrfCheck::class => true,
        Checks\OpenRedirectCheck::class => true,
        Checks\CommandInjectionCheck::class => true,
        Checks\InsecureDeserializationCheck::class => true,
        Checks\DebugFunctionsCheck::class => true,
        Checks\SensitiveExposureCheck::class => true,
        Checks\SsrfCheck::class => true,
        Checks\TlsVerificationCheck::class => true,
        Checks\CorsConfigCheck::class => true,
        Checks\PackageFreshnessCheck::class => true,
        Checks\SuspiciousVendorAutoloadCheck::class => true,
        Checks\SupplyChainToolingCheck::class => true,
        Checks\PathTraversalCheck::class => true,
        Checks\WeakCryptographyCheck::class => true,
        Checks\InsecureRngCheck::class => true,
        Checks\SessionSecurityCheck::class => true,
        Checks\EolVersionCheck::class => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Freshness (Supply Chain)
    |--------------------------------------------------------------------------
    */

    'package_freshness' => [
        'minimum_age_days' => 3,
        'whitelist' => [
            'andreapollastri/checkpoint',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious Vendor Autoload
    |--------------------------------------------------------------------------
    |
    | All entries below are well-known, widely-used packages whose use of
    | autoload.files is a documented, legitimate pattern (global helpers,
    | polyfills, testing bootstraps). Each was manually verified against
    | its published source before being added here.
    |
    */

    'suspicious_autoload' => [
        'whitelist' => [
            'blade-ui-kit/blade-icons',
            'danharrin/date-format-converter',
            'evenement/evenement',
            'filament/*',
            'halaxa/json-machine',
            'laravel/ai',
            'laravel/prompts',
            'league/csv',
            'livewire/livewire',
            'mockery/mockery',
            'myclabs/deep-copy',
            'nunomaduro/collision',
            'nunomaduro/termwind',
            'pestphp/*',
            'phpseclib/phpseclib',
            'phpstan/phpstan',
            'phpunit/phpunit',
            'pragmarx/google2fa',
            'prism-php/prism',
            'psy/psysh',
            'ralouphie/getallheaders',
            'react/promise',
            'react/promise-timer',
            'rector/rector',
            'scrivo/highlight.php',
            'sebastian/global-state',
            'sebastian/type',
            'spatie/invade',
            'spatie/laravel-backup',
            'symfony/clock',
            'symfony/string',
            'symfony/translation',
            'symfony/var-dumper',
            'whichbrowser/parser',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suppressed Findings
    |--------------------------------------------------------------------------
    |
    | Reviewed false positives. Hashes are content-stable — line-number
    | changes will not invalidate them. Each group is annotated with why
    | the finding was accepted.
    |
    */

    'suppressed' => [
        // ── Hardcoded Secrets ────────────────────────────────────────────────
        // LogoProxyController: 1×1 transparent PNG pixel encoded as base64 —
        // not a credential.
        '3d9d85f224d4',

        // GenerateTranslations: "{PH{$index}}" is a placeholder token template,
        // not a secret.
        '2a3eaf0a6403',

        // public/js/saade/filament-laravel-log — published vendor assets that
        // embed tiny base64 PNG sprites inline in CSS. Not credentials.
        'db4f9de8969c',
        '8acec5404912',
        'e02d6a23b5cc',
        'fe0cf67057e4',
        'd136b27937f1',
        '082124c784c4',
        '0faa287bbae6',
        'ee841f8511f7',
        'c37d08a52506',
        'bf81dd0fb63f',

        // ── Command Injection ────────────────────────────────────────────────
        // GitInfoService::getFromGitCommands — $basePath is base_path() which
        // resolves to the server's own deployment directory; no user input
        // ever reaches this variable.
        '1581e67a6553',
        'a7ab544d3f1b',
        'fe1b0d7dc7fe',

        // ── TLS Verification ─────────────────────────────────────────────────
        // MediaServerProxyController + WebDavMediaService — SSL verification is
        // intentionally disabled so users can connect their own media servers
        // that use self-signed certificates. The risk is accepted and scoped to
        // outbound proxy requests, not inbound auth.
        'e50c830f9941',
        '3e36cbbc9b3a',
        '2f9fad8c842b',
        '392caf687b9b',
        '1cf9f415fe0d',

        // ── SQL Injection — validated before interpolation ───────────────────
        // SortService: $direction is always 'ASC'|'DESC' (ternary-validated),
        // $lowerOrderByColumn comes from a match() with explicit safe cases,
        // $casesSql/$idsSql are built from existing DB primary keys.
        'bea9748233f2',
        '0e160ed2ed17',
        '7217f20c4807',
        'f83f8307ed0c',
        '954fd8ebc4fa',
        '4069afb88fd9',
        'c050395865e0',
        '61ec81c5d5a2',
        '4491af1db976',
        'a88d30c913e5',

        // Migration 2026_04_06: one-time data migration building a CASE
        // expression from UUIDs generated in the same migration — no user input.
        '93f78b977cb1',

        // RelationManagers (Channels/Vod/Series): $orderByClause from match(),
        // $direction from ternary — both validated before interpolation.
        'fd8590b02566',
        'c81544f92a1b',
        '61bf949946a8',
        'f11e7e7b98b4',
        '7a78b0b1c5a9',
        'efa75f73069a',
        '507b196dbfbd',
        '48dd79fe5852',

        // EpgApiController: $coalesce is built exclusively from
        // $grammar->wrap('column.name') calls on hardcoded column identifiers.
        // $dedupeSubquery embeds $idList (integer EPG IDs from DB) and
        // $priorityCase (a CASE built from DB-sourced priority values).
        'f30c37727ff6',
        '5d446de55dab',
        'c4299abfeb14',
        'f0d1015e5307',
        '5f74d01b46a4',
        '016e252167db',
        'd9f093c0212a',

        // PlaylistGenerateController: $orderSubquery is a fully hardcoded SQL
        // string with only ? placeholders — no interpolated user input.
        '8a7c4d000066',
        '21366c44e0ab',

        // ChannelController: $sortOrder was unsanitized — now validated to
        // 'ASC'|'DESC' before use. Hashes left here in case pattern still
        // matches post-fix.
        '57e265dc6af9',
        'db463012a7cf',

        // ── XSS — controlled server-side rendering ───────────────────────────
        // regex-tester.blade.php: {!! !!} renders output from a Livewire
        // component method — content is generated server-side, not from raw
        // user input passed through unescaped.
        'c7a86f0573ef',

        // release-logs.blade.php: markdown rendered from GitHub release notes
        // fetched server-side via authenticated API — not user-supplied HTML.
        'ac51fa6935e0',

        // ── Environment (local dev only) ─────────────────────────────────────
        // APP_DEBUG=true, APP_ENV=local, SESSION_SECURE_COOKIE off — expected
        // in local development. CI uses .env.example which sets production
        // values; these hashes only appear locally.
        '3ff08f5321c5',
        '788146a4921b',
        '3804ebbc7280',

        // ── File Permissions (dev/Docker) ────────────────────────────────────
        // .env 644 and storage/ 777 are typical in local Docker volume mounts.
        // Production deployments enforce stricter permissions via Dockerfile.
        'ea9340e54ed3',
        'd3fe1df47182',

        // ── CORS — intentional open config ───────────────────────────────────
        // This app is a self-hosted media server where users embed it in their
        // own setups — open CORS is a deliberate design choice, not an oversight.
        'e0c80beac0be',
        '18dbdb6d60a4',
        'c8691fa9f61e',

        // ── Supply Chain Tooling ─────────────────────────────────────────────
        // safe-chain is installed in CI via the security workflow. The PATH
        // check only passes in interactive shell environments.
        '11237ebcced3',
        'ef474f32494e',
        '2e20e774eab1',
        '32cc17ad5d23',

        // ── Mass Assignment ──────────────────────────────────────────────────
        // All models are managed exclusively through Filament form schemas and
        // explicit service-layer assignments — never via fill($request->all()).
        // Mass assignment protection is enforced at the application layer.
        'ae900ed4ff01', // Category
        '0e21e430d6bb', // ViewerWatchProgress
        '2382f9e79deb', // Series
        'a0f9776dceaa', // Season
        '838959be67dd', // Epg
        '798b636d61ce', // Playlist
        'b0ba83883904', // ChannelFailover
        'a8a194b50e0e', // PluginInstallReview
        'bf8869878e33', // StreamProfile
        'fbfb916f5547', // Group
        '56a98330dbe4', // PluginTableRecord
        '7b55b62f7138', // StrmFileMapping
        '2422d7ed421d', // PlaylistProfile
        '0a5981e46fa5', // PlaylistSyncStatusLog
        'db85a614ca42', // ChannelScrubber
        'e17181f0172a', // MediaServerIntegration
        '68fd07ef77c4', // Plugin
        '5fbde68dd72b', // User
        '94734f1b1613', // PlaylistSyncStatus
        '56dcdbe9f9c9', // PlaylistAlias
        'f91acccd06ed', // SyncRun
        'a18527ac22a0', // ChannelScrubberLogChannel
        '805298169308', // NetworkProgramme
        '16134c7df9e7', // EpisodeFailover
        'c4dbb79a7720', // Episode
        '60a00f1a8537', // SourceCategory
        '5737dfd52a07', // Channel
        '1cf8c3844c88', // MergedPlaylist
        'c15cfac7bbc4', // CustomPlaylist
        '79534ef7c908', // PluginRun
        '7b0b2e06dd89', // EpgMap
        '2a8b0fa33913', // EpgChannel
        '7871e9332661', // NetworkContent
        '22b4c7b18ee3', // PostProcess
        '4e486c3ba991', // PostProcessLog
        '50cac2142fc6', // Network
        '5e1da5b313cd', // Job
        'd1a04e0090af', // PlaylistAuth
        'd995a705bf03', // SourceGroup
        '98239a835f70', // ChannelScrubberLog
        '539a89542c6f', // PluginRunLog
        'd3be545f01af', // PlaylistViewer
        '57a9d634f68a', // Asset
        '40cdd2eeb944', // CustomPlaylistPivot
        'ea53a22fdb1d', // PostProcessPivot
        '11d5a0f3298d', // MergedPlaylistPivot
        'a6558982e4e4', // PlaylistAuthPivot
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Scan Paths
    |--------------------------------------------------------------------------
    */

    'exclude_paths' => [
        'data/',
        'db-dumps/',
    ],

];
