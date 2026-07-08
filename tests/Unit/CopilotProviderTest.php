<?php

use App\Support\CopilotProvider;

it('defines OpenCode provider aliases with the correct defaults', function (): void {
    expect(CopilotProvider::options())
        ->toHaveKey('opencode_zen', 'OpenCode Zen')
        ->toHaveKey('opencode_go', 'OpenCode Go');

    expect(CopilotProvider::defaultUrl('opencode_zen'))->toBe(CopilotProvider::OPENCODE_URL)
        ->and(CopilotProvider::defaultModel('opencode_zen'))->toBe('gpt-5.4-mini');

    expect(CopilotProvider::defaultUrl('opencode_go'))->toBe(CopilotProvider::OPENCODE_URL)
        ->and(CopilotProvider::defaultModel('opencode_go'))->toBe('deepseek-v4-flash');
});

it('lists providers whose base URL can be overridden in Copilot settings', function (): void {
    expect(CopilotProvider::supportsCustomUrl('opencode_zen'))->toBeTrue()
        ->and(CopilotProvider::supportsCustomUrl('opencode_go'))->toBeTrue()
        ->and(CopilotProvider::supportsCustomUrl('deepseek'))->toBeFalse();
});

it('registers OpenCode aliases in Laravel AI config with endpoint root URLs', function (): void {
    expect(config('ai.providers.opencode_zen.driver'))->toBe('openai')
        ->and(config('ai.providers.opencode_zen.url'))->toBe(CopilotProvider::OPENCODE_URL)
        ->and(config('ai.providers.opencode_go.driver'))->toBe('deepseek')
        ->and(config('ai.providers.opencode_go.url'))->toBe(CopilotProvider::OPENCODE_URL);
});
