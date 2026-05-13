<?php

declare(strict_types=1);

namespace App\AI\DeepSeek;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\DeepSeek\Handlers\Stream;
use Prism\Prism\Providers\DeepSeek\Maps\ToolChoiceMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolMap;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;

/**
 * Fixes a Prism bug where DeepSeek reasoning models (deepseek-reasoner) fail
 * during tool-call multi-turn conversations because `reasoning_content` is
 * discarded instead of being passed back in the follow-up assistant message.
 *
 * Tracks accumulated reasoning per step and stores it in AssistantMessage's
 * additionalContent so PatchedMessageMap can serialize it back to the API.
 */
class PatchedStream extends Stream
{
    private string $currentStepThinking = '';

    /**
     * Intercept reasoning delta extraction to accumulate thinking text as a side-effect.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractReasoningDelta(array $data): string
    {
        $delta = parent::extractReasoningDelta($data);

        if ($delta !== '' && $delta !== '0') {
            $this->currentStepThinking .= $delta;
        }

        return $delta;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return Generator<StreamEvent>
     */
    protected function handleToolCalls(Request $request, string $text, array $toolCalls, int $depth): Generator
    {
        $mappedToolCalls = $this->mapToolCalls($toolCalls);

        foreach ($mappedToolCalls as $toolCall) {
            yield new ToolCallEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolCall: $toolCall,
                messageId: $this->state->messageId()
            );
        }

        $toolResults = [];
        yield from $this->callToolsAndYieldEvents($request->tools(), $mappedToolCalls, $this->state->messageId(), $toolResults);

        $this->state->markStepFinished();
        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        // Capture reasoning from this step and reset before the next step starts.
        $additionalContent = [];
        if ($this->currentStepThinking !== '') {
            $additionalContent['reasoning_content'] = $this->currentStepThinking;
        }
        $this->currentStepThinking = '';

        $request->addMessage(new AssistantMessage($text, $mappedToolCalls, $additionalContent));
        $request->addMessage(new ToolResultMessage($toolResults));
        $request->resetToolChoice();

        $this->state->resetTextState();
        $this->state->withMessageId(EventID::generate());

        $depth++;
        if ($depth < $request->maxSteps()) {
            $nextResponse = $this->sendRequest($request);
            yield from $this->processStream($nextResponse, $request, $depth);
        } else {
            yield $this->emitStreamEndEvent();
        }
    }

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'stream' => true,
                'model' => $request->model(),
                'messages' => (new PatchedMessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], Arr::whereNotNull([
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'tools' => ToolMap::map($request->tools()) ?: null,
                'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
            ]))
        );

        return $response;
    }
}
