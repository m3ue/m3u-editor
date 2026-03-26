<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Plugin extends Model
{
    use HasFactory;

    protected $table = 'extension_plugins';

    protected $fillable = [
        'plugin_id',
        'name',
        'version',
        'api_version',
        'description',
        'entrypoint',
        'class_name',
        'capabilities',
        'hooks',
        'permissions',
        'schema_definition',
        'actions',
        'settings_schema',
        'settings',
        'data_ownership',
        'source_type',
        'path',
        'available',
        'enabled',
        'installation_status',
        'trust_state',
        'trust_reason',
        'trusted_at',
        'trusted_by_user_id',
        'blocked_at',
        'blocked_by_user_id',
        'last_cleanup_mode',
        'validation_status',
        'integrity_status',
        'manifest_hash',
        'entrypoint_hash',
        'plugin_hash',
        'trusted_hashes',
        'integrity_verified_at',
        'validation_errors',
        'last_discovered_at',
        'last_validated_at',
        'uninstalled_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'hooks' => 'array',
        'permissions' => 'array',
        'schema_definition' => 'array',
        'actions' => 'array',
        'settings_schema' => 'array',
        'settings' => 'array',
        'data_ownership' => 'array',
        'trusted_hashes' => 'array',
        'validation_errors' => 'array',
        'available' => 'boolean',
        'enabled' => 'boolean',
        'trusted_at' => 'datetime',
        'blocked_at' => 'datetime',
        'integrity_verified_at' => 'datetime',
        'last_discovered_at' => 'datetime',
        'last_validated_at' => 'datetime',
        'uninstalled_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(PluginRun::class, 'extension_plugin_id')->latest();
    }

    public function installReviews(): HasMany
    {
        return $this->hasMany(PluginInstallReview::class, 'extension_plugin_id')->latest();
    }

    public function logs(): HasManyThrough
    {
        return $this->hasManyThrough(
            PluginRunLog::class,
            PluginRun::class,
            'extension_plugin_id',
            'extension_plugin_run_id',
            'id',
            'id',
        )->latest('extension_plugin_run_logs.created_at');
    }

    public function getActionDefinition(string $actionId): ?array
    {
        foreach ($this->actions ?? [] as $action) {
            if (($action['id'] ?? null) === $actionId) {
                return $action;
            }
        }

        return null;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function isInstalled(): bool
    {
        return ($this->installation_status ?? 'installed') === 'installed';
    }

    public function isTrusted(): bool
    {
        return ($this->trust_state ?? 'pending_review') === 'trusted';
    }

    public function isBlocked(): bool
    {
        return ($this->trust_state ?? 'pending_review') === 'blocked';
    }

    public function hasVerifiedIntegrity(): bool
    {
        return ($this->integrity_status ?? 'unknown') === 'verified';
    }

    public function defaultCleanupMode(): string
    {
        return data_get($this->data_ownership ?? [], 'default_cleanup_policy', 'preserve');
    }

    public function hasActiveRuns(): bool
    {
        return $this->runs()
            ->where('status', 'running')
            ->exists();
    }
}
