<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginTableRecord extends Model
{
    // All columns are intentionally fillable; the dynamic table name is validated before use.
    protected $guarded = [];

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
