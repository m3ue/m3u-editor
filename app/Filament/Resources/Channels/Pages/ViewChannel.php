<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewChannel extends ViewRecord
{
    protected static string $resource = ChannelResource::class;

    public function infolist(Schema $schema): Schema
    {
        return ChannelResource::infolist($schema);
    }
}
