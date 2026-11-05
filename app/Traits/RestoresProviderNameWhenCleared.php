<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * Clearing a group or category's name resets it to the provider's (name_internal), the
 * value the inline name columns show as their placeholder. Blank input reaches the model
 * as null, which the NOT NULL column would otherwise reject. A legacy row with no
 * name_internal keeps its current name.
 */
trait RestoresProviderNameWhenCleared
{
    protected static function bootRestoresProviderNameWhenCleared(): void
    {
        static::saving(function (Model $model): void {
            if ($model->name === null) {
                $model->name = filled($model->name_internal) ? $model->name_internal : $model->getOriginal('name');
            }
        });
    }
}
