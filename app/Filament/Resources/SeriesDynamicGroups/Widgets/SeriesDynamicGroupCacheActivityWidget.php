<?php

namespace App\Filament\Resources\SeriesDynamicGroups\Widgets;

use App\Filament\Resources\DynamicGroups\Widgets\DynamicGroupCacheActivityWidget;

/**
 * Series-side cache activity widget for the new "Dynamic Groups"
 * sidebar section under Series. Scopes the shared base widget to
 * `content_type = 'episode'` so Series page visitors only see their
 * Series dynamic-group cache activity (downloads triggered by
 * series-type DynamicGroup rows — one job per episode).
 *
 * Registered as a footer widget on `ListSeriesDynamicGroups`.
 */
class SeriesDynamicGroupCacheActivityWidget extends DynamicGroupCacheActivityWidget
{
    protected static ?string $contentType = 'episode';
}
