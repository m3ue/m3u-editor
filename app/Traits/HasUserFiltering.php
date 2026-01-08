<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasUserFiltering
{
    /**
     * Get the Eloquent query for the resource.
     * Filters by user_id.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where(static::getModel()::make()->getTable().'.user_id', auth()->id());

        return $query;
    }

    /**
     * Get the global search Eloquent query for the resource.
     * Filters by user_id.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery()
            ->where(static::getModel()::make()->getTable().'.user_id', auth()->id());

        return $query;
    }
}
