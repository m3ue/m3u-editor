<?php

namespace App\Models;

use App\Plugins\PluginUiRequestCache;
use Illuminate\Database\Eloquent\Model;

class PluginTableRecord extends Model
{
    // All columns are intentionally fillable; the dynamic table name is validated before use.
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => app(PluginUiRequestCache::class)->forgetData());
        static::deleted(fn () => app(PluginUiRequestCache::class)->forgetData());
    }

    /**
     * @param  array<int, string>  $jsonColumns
     */
    public static function forTable(string $table, array $jsonColumns = [], bool $timestamps = true): self
    {
        $record = new self;
        $record->setTable($table);
        $record->timestamps = $timestamps;

        if ($jsonColumns !== []) {
            $record->mergeCasts(array_fill_keys($jsonColumns, 'array'));
        }

        return $record;
    }
}
