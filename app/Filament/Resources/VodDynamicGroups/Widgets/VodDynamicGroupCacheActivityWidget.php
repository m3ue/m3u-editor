<?php

namespace App\Filament\Resources\VodDynamicGroups\Widgets;

use App\Filament\Resources\DynamicGroups\Widgets\DynamicGroupCacheActivityWidget;

/**
 * VOD-side cache activity widget for the new "Dynamic Groups" sidebar
 * section under VOD Channels. Scopes the shared base widget to
 * `content_type = 'movie'` so VOD page visitors only see their VOD
 * dynamic-group cache activity (downloads triggered by VOD-type
 * DynamicGroup rows).
 *
 * Registered as a footer widget on `ListVodDynamicGroups`.
 */
class VodDynamicGroupCacheActivityWidget extends DynamicGroupCacheActivityWidget
{
    protected static ?string $contentType = 'movie';
}
