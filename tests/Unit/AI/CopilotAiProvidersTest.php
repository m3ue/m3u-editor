<?php

use App\AI\PatchedGeminiGateway;
use App\Filament\CopilotTools\DvrScheduleTool;
use App\Filament\CopilotTools\SearchDocsTool;
use App\Providers\Filament\AdminPanelProvider;
use EslamRedaDiv\FilamentCopilot\Tools\RecallTool;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Laravel\Ai\Tools\Request;

function copilotTestTool(array $schema): Tool
{
    return new class($schema) implements Tool
    {
        public function __construct(private array $properties) {}

        public function description(): string
        {
            return 'Test tool';
        }

        public function handle(Request $request): string
        {
            return '';
        }

        public function schema(JsonSchema $schema): array
        {
            return array_map(fn (Closure $property) => $property($schema), $this->properties);
        }
    };
}

function mapGeminiTool(Tool $tool): array
{
    $gateway = new PatchedGeminiGateway(app(Dispatcher::class));

    return (fn () => $this->mapTool($tool))->call($gateway);
}

it('omits the required key from Gemini tools with only optional parameters', function (): void {
    $definition = mapGeminiTool(copilotTestTool([
        'key' => fn (JsonSchema $schema) => $schema->string(),
    ]));

    expect($definition['parameters'])
        ->toHaveKey('properties')
        ->not->toHaveKey('required');
});

it('keeps required Gemini tool parameters', function (): void {
    $definition = mapGeminiTool(copilotTestTool([
        'epg_id' => fn (JsonSchema $schema) => $schema->integer()->required(),
        'note' => fn (JsonSchema $schema) => $schema->string(),
    ]));

    expect($definition['parameters']['required'])->toBe(['epg_id']);
});

it('resolves Gemini through the patched gateway', function (): void {
    config(['ai.providers.gemini.key' => 'test-key']);

    $provider = app(AiManager::class)->textProvider('gemini');

    expect($provider->textGateway())->toBeInstanceOf(PatchedGeminiGateway::class);
});

it('runs MiniMax on the built-in OpenAI-compatible driver with its model defaults', function (): void {
    config(['ai.providers.minimax.key' => 'test-key']);

    $provider = app(AiManager::class)->textProvider('minimax');

    expect($provider)->toBeInstanceOf(OpenAiCompatibleProvider::class)
        ->and($provider->defaultTextModel())->toBe('MiniMax-M2.7')
        ->and($provider->cheapestTextModel())->toBe('MiniMax-M2.7-highspeed')
        ->and($provider->additionalConfiguration()['url'])->toBe('https://api.minimax.io/v1');
});

it('strips built-in and repeated tools from the configured global tools', function (): void {
    $filter = new ReflectionMethod(AdminPanelProvider::class, 'filterBuiltInTools');

    $tools = $filter->invoke(new AdminPanelProvider(app()), [
        SearchDocsTool::class,
        RecallTool::class,
        DvrScheduleTool::class,
        SearchDocsTool::class,
    ]);

    expect($tools)->toBe([SearchDocsTool::class, DvrScheduleTool::class]);
});
