<?php

namespace App\Filament\Pages;

use App\Services\PluginScaffoldService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreatePlugin extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Plugins';

    public static function getNavigationLabel(): string
    {
        return __('Create Plugin');
    }

    public function getTitle(): string
    {
        return __('Create Plugin');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Plugins');
    }

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = null;

    protected string $view = 'filament.pages.create-plugin';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check() && (auth()->user()?->canManagePlugins() ?? false);
    }

    public function getSubheading(): ?string
    {
        return __('Generate a new plugin scaffold with all the files you need to get started.');
    }

    public function mount(): void
    {
        $this->form->fill([
            'cleanup_mode' => 'preserve',
            'lifecycle' => false,
            'bare' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Wizard::make([
                    Step::make('Details')
                        ->icon('heroicon-o-pencil')
                        ->description('Name and describe your plugin')
                        ->schema([
                            TextInput::make('name')
                                ->label('Plugin Name')
                                ->placeholder('My Awesome Plugin')
                                ->required()
                                ->maxLength(100)
                                ->helperText(fn (?string $state): string => $state
                                    ? 'Plugin ID: '.Str::slug(trim($state))
                                    : 'Enter a human-friendly name — the slug is generated automatically.')
                                ->live(debounce: 500),
                            TextInput::make('description')
                                ->label('Description')
                                ->placeholder('What does this plugin do?')
                                ->maxLength(255)
                                ->helperText('Short description for the plugin manifest. Leave blank for a default.'),

                        ]),

                    Step::make('Capabilities')
                        ->icon('heroicon-o-puzzle-piece')
                        ->description('What your plugin can do')
                        ->schema([
                            Section::make('Capabilities')
                                ->compact()
                                ->description('Select what your plugin will participate in. Each capability adds a required PHP interface to your Plugin class.')
                                ->schema([
                                    CheckboxList::make('capabilities')
                                        ->hiddenLabel()
                                        ->options(
                                            collect(config('plugins.capabilities', []))
                                                ->mapWithKeys(fn (array $cap, string $key) => [
                                                    $key => ($cap['label'] ?? Str::headline($key)).' — '.($cap['description'] ?? ''),
                                                ])
                                                ->all()
                                        )
                                        ->columns(1),
                                ]),
                            Section::make('Event Triggers')
                                ->compact()
                                ->description('Subscribe to host events that will automatically run your plugin in the background.')
                                ->schema([
                                    CheckboxList::make('hooks')
                                        ->hiddenLabel()
                                        ->options(
                                            collect(config('plugins.hooks', []))
                                                ->mapWithKeys(fn (string $description, string $hook) => [
                                                    $hook => "{$hook} — {$description}",
                                                ])
                                                ->all()
                                        )
                                        ->columns(1),
                                ]),
                        ]),

                    Step::make('Options')
                        ->icon('heroicon-o-cog-6-tooth')
                        ->description('Configure scaffold options')
                        ->schema([
                            Radio::make('cleanup_mode')
                                ->label('Default Uninstall Behavior')
                                ->options([
                                    'preserve' => 'Preserve data — keep plugin tables and files on uninstall',
                                    'purge' => 'Purge data — delete plugin tables and files on uninstall',
                                ])
                                ->default('preserve'),
                            Toggle::make('lifecycle')
                                ->label('Include lifecycle hook')
                                ->helperText('Adds an uninstall() method for custom cleanup logic beyond what the manifest declares.'),
                            Toggle::make('bare')
                                ->label('Bare scaffold')
                                ->helperText('Generate only plugin.json and Plugin.php — skip README, CI workflow, scripts, and AI guidance files.'),
                        ]),

                    Step::make('Generate')
                        ->icon('heroicon-o-rocket-launch')
                        ->description('Review and create your plugin')
                        ->schema([
                            Placeholder::make('summary')
                                ->hiddenLabel()
                                ->content(fn (): HtmlString => new HtmlString($this->buildSummaryHtml())),
                            Placeholder::make('actions_placeholder')
                                ->hiddenLabel()
                                ->content(new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">Choose how to generate your plugin below.</p>')),
                        ]),
                ])
                    ->columnSpanFull()
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <div class="flex gap-3">
                            <x-filament::button wire:click="downloadZip" color="primary" icon="heroicon-o-arrow-down-tray">
                                Download as ZIP
                            </x-filament::button>
                        </div>
                    BLADE))),
            ])
            ->statePath('data');
    }

    /**
     * Generate the scaffold as a ZIP download.
     */
    public function downloadZip()
    {
        $this->form->validate();
        $data = $this->form->getState();
        $scaffoldService = app(PluginScaffoldService::class);

        try {
            $zipPath = $scaffoldService->scaffoldToZip(
                name: $data['name'],
                description: $data['description'] ?? '',
                capabilities: $data['capabilities'] ?? [],
                hooks: $data['hooks'] ?? [],
                cleanupMode: $data['cleanup_mode'] ?? 'preserve',
                lifecycle: (bool) ($data['lifecycle'] ?? false),
                bare: (bool) ($data['bare'] ?? false),
            );

            $pluginId = $scaffoldService->derivePluginId($data['name']);

            return response()->streamDownload(function () use ($zipPath): void {
                readfile($zipPath);
                @unlink($zipPath);
            }, "{$pluginId}.zip", [
                'Content-Type' => 'application/zip',
            ]);

        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->danger()
                ->title('Plugin creation failed')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return null;
        }
    }

    /**
     * Build the review summary HTML from current form state.
     */
    private function buildSummaryHtml(): string
    {
        $data = $this->data;
        $name = $data['name'] ?? '';
        $pluginId = Str::slug(trim($name));
        $description = $data['description'] ?? '';
        $capabilities = $data['capabilities'] ?? [];
        $hooks = $data['hooks'] ?? [];
        $cleanupMode = $data['cleanup_mode'] ?? 'preserve';
        $lifecycle = $data['lifecycle'] ?? false;
        $bare = $data['bare'] ?? false;

        if ($pluginId === '') {
            return '<p class="text-sm text-gray-500 dark:text-gray-400">Enter a plugin name in the first step to see a preview.</p>';
        }

        $capList = empty($capabilities)
            ? '<span class="text-gray-400">None</span>'
            : implode(', ', array_map(fn ($c) => '<code>'.e($c).'</code>', $capabilities));

        $hookList = empty($hooks)
            ? '<span class="text-gray-400">None</span>'
            : implode(', ', array_map(fn ($h) => '<code>'.e($h).'</code>', $hooks));

        $flags = [];
        if ($lifecycle) {
            $flags[] = 'Lifecycle hook';
        }
        if ($bare) {
            $flags[] = 'Bare scaffold';
        }
        $flagsStr = empty($flags)
            ? '<span class="text-gray-400">None</span>'
            : implode(', ', $flags);

        return <<<HTML
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div class="font-medium text-gray-600 dark:text-gray-400">Plugin Name</div>
                    <div class="text-gray-900 dark:text-white">{$name}</div>

                    <div class="font-medium text-gray-600 dark:text-gray-400">Plugin ID</div>
                    <div class="text-gray-900 dark:text-white"><code>{$pluginId}</code></div>

                    <div class="font-medium text-gray-600 dark:text-gray-400">Description</div>
                    <div class="text-gray-900 dark:text-white">{$description}</div>

                    <div class="font-medium text-gray-600 dark:text-gray-400">Capabilities</div>
                    <div class="text-gray-900 dark:text-white">{$capList}</div>

                    <div class="font-medium text-gray-600 dark:text-gray-400">Event Triggers</div>
                    <div class="text-gray-900 dark:text-white">{$hookList}</div>

                    <div class="font-medium text-gray-600 dark:text-gray-400">Cleanup Mode</div>
                    <div class="text-gray-900 dark:text-white">{$cleanupMode}</div>

                    <div class="font-medium text-gray-600 dark:text-gray-400">Options</div>
                    <div class="text-gray-900 dark:text-white">{$flagsStr}</div>
                </div>
            </div>
        HTML;
    }
}
