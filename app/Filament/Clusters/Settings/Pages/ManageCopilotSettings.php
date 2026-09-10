<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Filament\CopilotTools\DvrOverviewTool;
use App\Filament\CopilotTools\DvrScheduleTool;
use App\Filament\CopilotTools\EpgChannelMatcherTool;
use App\Filament\CopilotTools\EpgMappingApplyTool;
use App\Filament\CopilotTools\EpgMappingStateTool;
use App\Filament\CopilotTools\ExecuteDatabaseQueryTool;
use App\Filament\CopilotTools\GetDatabaseSchemaTool;
use App\Filament\CopilotTools\NetworkContentBulkAddTool;
use App\Filament\CopilotTools\NetworkContentPinTool;
use App\Filament\CopilotTools\SearchDocsTool;
use App\Filament\CopilotTools\VodContentSearchTool;
use App\Support\CopilotProvider;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ManageCopilotSettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $slug = 'ai-copilot';

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return __('AI Copilot');
    }

    public function getTitle(): string
    {
        return __('AI Copilot');
    }

    /**
     * Re-index the quick actions repeater array (Filament uses string keys internally)
     * before persisting to GeneralSettings.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['copilot_quick_actions']) && is_array($data['copilot_quick_actions'])) {
            $data['copilot_quick_actions'] = array_values($data['copilot_quick_actions']);
        }

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('AI Copilot'))
                    ->description(__('You will need to save and refresh the page after changing settings for them to take effect. Look for the ✨ AI Copilot icon in the top navigation bar after enabling.'))
                    ->schema([
                        Toggle::make('copilot_enabled')
                            ->label(__('Enable AI Copilot'))
                            ->helperText(__('When enabled and configured, the AI Copilot assistant will appear in the top navigation bar.'))
                            ->live(),
                        Toggle::make('copilot_mgmt_enabled')
                            ->label(__('Enable AI Copilot Management'))
                            ->helperText(__('Enables audit log, custom rate limits, conversation history, and other management features for the AI Copilot assistant.'))
                            ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled')),
                    ]),
                Section::make(__('AI Provider'))
                    ->description(__('Select your AI provider and configure the API credentials.'))
                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                    ->schema([
                        Grid::make()
                            ->columns(2)
                            ->schema([
                                Select::make('copilot_provider')
                                    ->label(__('Provider'))
                                    ->searchable()
                                    ->options(CopilotProvider::options())
                                    ->live()
                                    ->required(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                                    ->helperText(__('The AI provider to use for the Copilot assistant.')),
                                TextInput::make('copilot_model')
                                    ->label(__('Model'))
                                    ->placeholder(fn (Get $get): string => CopilotProvider::defaultModel($get('copilot_provider')))
                                    ->helperText(__('The model to use. Leave blank to use the provider default.')),
                            ]),
                        TextInput::make('copilot_api_key')
                            ->label(__('API Key'))
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->visible(fn (Get $get): bool => $get('copilot_provider') !== 'ollama')
                            ->required(fn (Get $get): bool => (bool) $get('copilot_enabled') && $get('copilot_provider') !== 'ollama')
                            ->helperText(__('Your API key for the selected provider. Stored in the database.')),
                        TextInput::make('copilot_url')
                            ->label(__('Base URL'))
                            ->url()
                            ->placeholder(fn (Get $get): string => CopilotProvider::defaultUrl($get('copilot_provider')))
                            ->visible(fn (Get $get): bool => CopilotProvider::supportsCustomUrl($get('copilot_provider')))
                            ->helperText(__('Override the default API base URL. Leave blank to use the provider default. Useful for self-hosted models or proxy endpoints.')),
                    ]),
                Section::make(__('System Prompt'))
                    ->description(__('The system prompt sent to the AI on every conversation to configure its behaviour.'))
                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                    ->schema([
                        Textarea::make('copilot_system_prompt')
                            ->label(__('System Prompt'))
                            ->placeholder(__('You are a helpful AI assistant integrated into m3u editor. You help users manage playlists, EPG data, streams, channels, and other features. Be concise and accurate.'))
                            ->rows(4)
                            ->helperText(__('Leave empty to use the default.')),
                    ]),
                Section::make(__('Global Tools'))
                    ->description(__('Tools available to the Copilot assistant across all pages.'))
                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                    ->schema([
                        CheckboxList::make('copilot_global_tools')
                            ->label(__('Enabled Tools'))
                            ->bulkToggleable()
                            ->options([
                                SearchDocsTool::class => __('Search Documentation'),
                                EpgMappingStateTool::class => __('EPG Mapper: Mapping State'),
                                EpgChannelMatcherTool::class => __('EPG Mapper: Channel Matcher'),
                                EpgMappingApplyTool::class => __('EPG Mapper: Apply Mappings'),
                                GetDatabaseSchemaTool::class => __('Database: Get Schema'),
                                ExecuteDatabaseQueryTool::class => __('Database: Execute Query'),
                                DvrOverviewTool::class => __('DVR: Overview'),
                                DvrScheduleTool::class => __('DVR: Schedule'),
                                VodContentSearchTool::class => __('Content: VOD Search'),
                                NetworkContentBulkAddTool::class => __('Content: Network Bulk Add'),
                                NetworkContentPinTool::class => __('Content: Pin to Timeslot'),
                            ])
                            ->afterStateHydrated(function ($component, $state) {
                                // Strip built-in tools that were saved by older versions.
                                // They are always registered by ToolRegistry and must not
                                // appear in the options list, or Filament validation fails.
                                $validOptions = array_keys($component->getOptions());
                                $component->state(
                                    array_values(array_filter(
                                        (array) $state,
                                        fn ($v) => \in_array($v, $validOptions, true)
                                    ))
                                );
                            })
                            ->columns(2)
                            ->default([
                                SearchDocsTool::class,
                                DvrScheduleTool::class,
                            ])
                            ->helperText(__('Select which additional tools the AI assistant can use. Core tools (navigation, memory) are always available.')),
                    ]),
                Section::make(__('Quick Actions'))
                    ->description(__('Pre-defined prompts displayed as buttons in the Copilot chat window.'))
                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                    ->schema([
                        Repeater::make('copilot_quick_actions')
                            ->label(__('Quick Actions'))
                            ->schema([
                                TextInput::make('label')
                                    ->label(__('Label'))
                                    ->required()
                                    ->placeholder(__('e.g. Help me find a channel')),
                                Textarea::make('prompt')
                                    ->label(__('Prompt'))
                                    ->required()
                                    ->rows(2)
                                    ->placeholder(__('e.g. Help me find a channel by name.')),
                            ])
                            ->columns(2)
                            ->addActionLabel(__('Add Quick Action'))
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(0),
                    ]),
            ]);
    }
}
