<?php

namespace App\Support;

final class CopilotProvider
{
    private const DEFAULT_OPENAI_URL = 'https://api.openai.com/v1';

    private const OPENCODE_URL = 'https://opencode.ai/zen/v1';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'openai' => 'OpenAI',
            'opencode_zen' => 'OpenCode Zen',
            'opencode_go' => 'OpenCode Go',
            'anthropic' => 'Anthropic',
            'gemini' => 'Google Gemini',
            'mistral' => 'Mistral',
            'groq' => 'Groq',
            'deepseek' => 'DeepSeek',
            'xai' => 'xAI (Grok)',
            'minimax' => 'MiniMax',
            'openrouter' => 'OpenRouter',
            'ollama' => 'Ollama (Local)',
        ];
    }

    public static function driver(string $provider): string
    {
        return match ($provider) {
            'opencode_zen' => 'openai',
            'opencode_go' => 'deepseek',
            default => $provider,
        };
    }

    public static function defaultModel(?string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'claude-sonnet-4-6',
            'gemini' => 'gemini-2.5-flash',
            'mistral' => 'mistral-large-latest',
            'groq' => 'llama-3.3-70b-versatile',
            'deepseek' => 'deepseek-v4-flash',
            'opencode_go' => 'deepseek-v4-flash',
            'xai' => 'grok-3',
            'minimax' => 'MiniMax-M2.7',
            'openrouter' => 'openai/gpt-5.4',
            'ollama' => 'llama3',
            'opencode_zen' => 'gpt-5.4-mini',
            default => 'gpt-5.4-mini',
        };
    }

    public static function defaultUrl(?string $provider): string
    {
        return match ($provider) {
            'ollama' => 'http://localhost:11434',
            'minimax' => 'https://api.minimax.io/v1',
            'opencode_zen', 'opencode_go' => self::OPENCODE_URL,
            default => self::DEFAULT_OPENAI_URL,
        };
    }

    public static function supportsCustomUrl(?string $provider): bool
    {
        return in_array($provider, ['openai', 'opencode_zen', 'opencode_go', 'ollama', 'minimax'], true);
    }
}
