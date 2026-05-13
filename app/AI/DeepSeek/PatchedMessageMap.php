<?php

declare(strict_types=1);

namespace App\AI\DeepSeek;

use Prism\Prism\Providers\DeepSeek\Maps\MessageMap;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolCall;

/**
 * Extends Prism's DeepSeek MessageMap to include `reasoning_content` in
 * assistant messages when present. DeepSeek reasoning models (deepseek-reasoner)
 * require that the assistant's reasoning_content be passed back in multi-turn
 * requests — otherwise the API returns a 400 "must be passed back" error.
 */
class PatchedMessageMap extends MessageMap
{
    protected function mapAssistantMessage(AssistantMessage $message): void
    {
        $toolCalls = array_map(fn (ToolCall $toolCall): array => [
            'id' => $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments() ?: (object) []),
            ],
        ], $message->toolCalls);

        $mapped = array_filter([
            'role' => 'assistant',
            'content' => $message->content,
            'tool_calls' => $toolCalls,
        ]);

        if (isset($message->additionalContent['reasoning_content']) && $message->additionalContent['reasoning_content'] !== '') {
            $mapped['reasoning_content'] = $message->additionalContent['reasoning_content'];
        }

        $this->mappedMessages[] = $mapped;
    }
}
