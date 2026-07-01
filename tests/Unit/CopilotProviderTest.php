<?php

use App\Support\CopilotProvider;

it('defines OpenCode provider aliases with the correct drivers and endpoints', function (): void {
    expect(CopilotProvider::options())
        ->toHaveKey('opencode_zen', 'OpenCode Zen')
        ->toHaveKey('opencode_go', 'OpenCode Go');

    expect(CopilotProvider::driver('opencode_zen'))->toBe('openai')
        ->and(CopilotProvider::defaultUrl('opencode_zen'))->toBe('https://opencode.ai/zen/v1')
        ->and(CopilotProvider::defaultModel('opencode_zen'))->toBe('gpt-5.4-mini');

    expect(CopilotProvider::driver('opencode_go'))->toBe('deepseek')
        ->and(CopilotProvider::defaultUrl('opencode_go'))->toBe('https://opencode.ai/zen/v1')
        ->and(CopilotProvider::defaultModel('opencode_go'))->toBe('deepseek-v4-flash');
});

it('keeps OpenCode Go on a chat completions compatible driver instead of OpenAI responses', function (): void {
    expect(CopilotProvider::driver('opencode_go'))->not->toBe('openai');
});

it('lists providers whose base URL can be overridden in Copilot settings', function (): void {
    expect(CopilotProvider::supportsCustomUrl('opencode_zen'))->toBeTrue()
        ->and(CopilotProvider::supportsCustomUrl('opencode_go'))->toBeTrue()
        ->and(CopilotProvider::supportsCustomUrl('deepseek'))->toBeFalse();
});

it('registers OpenCode aliases in Laravel AI config with endpoint root URLs', function (): void {
    expect(config('ai.providers.opencode_zen.driver'))->toBe('openai')
        ->and(config('ai.providers.opencode_zen.url'))->toBe('https://opencode.ai/zen/v1')
        ->and(config('ai.providers.opencode_go.driver'))->toBe('deepseek')
        ->and(config('ai.providers.opencode_go.url'))->toBe('https://opencode.ai/zen/v1');
});
