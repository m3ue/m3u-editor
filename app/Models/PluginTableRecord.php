<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginTableRecord extends Model
{
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
