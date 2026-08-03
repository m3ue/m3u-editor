<?php

namespace App\Models;

use Database\Factories\EmbyLibraryMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmbyLibraryMapping extends Model
{
    /** @use HasFactory<EmbyLibraryMappingFactory> */
    use HasFactory;

    public const COLLECTION_TYPES = ['movies', 'tvshows'];

    public const SOURCE_KINDS = ['vod_group', 'series_category', 'custom_playlist_group', 'all'];

    protected $fillable = [
        'media_server_integration_id',
        'user_id',
        'enabled',
        'source_kind',
        'source_identifier',
        'source_label',
        'target_library_id',
        'target_library_name',
        'collection_type',
        'output_path',
        'is_managed',
        'options',
        'last_planned_revision',
        'last_applied_revision',
        'last_success_at',
        'status',
        'status_summary',
        'error_summary',
    ];

    protected $attributes = [
        'enabled' => true,
        'is_managed' => false,
        'options' => '[]',
        'status' => 'idle',
    ];

    protected static function booted(): void
    {
        static::saving(function (EmbyLibraryMapping $mapping): void {
            Validator::make(['options' => $mapping->options], [
                'options' => ['array:naming,nfo,versions,cleanup,refresh', 'max:5'],
                'options.naming' => ['sometimes', 'string', 'max:64'],
                'options.nfo' => ['sometimes', 'boolean'],
                'options.versions' => ['sometimes', 'boolean'],
                'options.cleanup' => ['sometimes', 'in:replace,keep,disabled'],
                'options.refresh' => ['sometimes', 'boolean'],
            ])->validate();

            if (! in_array($mapping->source_kind, self::SOURCE_KINDS, true)) {
                throw ValidationException::withMessages([
                    'source_kind' => trans('validation.in', ['attribute' => 'source kind']),
                ]);
            }

            if (! in_array($mapping->collection_type, self::COLLECTION_TYPES, true)) {
                throw ValidationException::withMessages([
                    'collection_type' => trans('validation.in', ['attribute' => 'collection type']),
                ]);
            }

            $isOwnedIntegration = MediaServerIntegration::query()
                ->whereKey($mapping->media_server_integration_id)
                ->where('user_id', $mapping->user_id)
                ->exists();

            if (! $isOwnedIntegration) {
                throw ValidationException::withMessages([
                    'media_server_integration_id' => trans('validation.exists', [
                        'attribute' => 'media server integration',
                    ]),
                ]);
            }
        });

        static::creating(function (EmbyLibraryMapping $mapping): void {
            $mapping->uuid ??= Str::orderedUuid()->toString();
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_managed' => 'boolean',
            'options' => 'array',
            'last_success_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class, 'media_server_integration_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function redactSummary(?string $summary): ?string
    {
        if ($summary === null || trim($summary) === '') {
            return null;
        }

        $summary = preg_replace('#https?://[^\s]+#i', '[redacted-url]', $summary) ?? '';
        $summary = preg_replace(
            '/\b(api[_-]?key|token|password|secret)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[redacted]',
            $summary,
        ) ?? '';
        $summary = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $summary) ?? '';

        return Str::limit(trim($summary), 500, '');
    }
}
