<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginInstallReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'plugin_id',
        'plugin_name',
        'plugin_version',
        'api_version',
        'source_type',
        'source_path',
        'source_origin',
        'source_metadata',
        'archive_filename',
        'archive_path',
        'expected_archive_sha256',
        'archive_sha256',
        'staging_path',
        'extracted_path',
        'installed_path',
        'status',
        'validation_status',
        'validation_errors',
        'scan_status',
        'scan_summary',
        'scan_details',
        'capabilities',
        'hooks',
        'permissions',
        'schema_definition',
        'data_ownership',
        'integrity_hashes',
        'manifest_snapshot',
        'review_notes',
        'extension_plugin_id',
        'created_by_user_id',
        'approved_by_user_id',
        'rejected_by_user_id',
        'scanned_at',
        'approved_at',
        'rejected_at',
        'installed_at',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'source_metadata' => 'array',
        'capabilities' => 'array',
        'hooks' => 'array',
        'permissions' => 'array',
        'schema_definition' => 'array',
        'data_ownership' => 'array',
        'integrity_hashes' => 'array',
        'manifest_snapshot' => 'array',
        'scan_details' => 'array',
        'scanned_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'installed_at' => 'datetime',
    ];

    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Plugin::class, 'extension_plugin_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function hasCleanScan(): bool
    {
        return $this->scan_status === 'clean';
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'installed'], true);
    }
}
