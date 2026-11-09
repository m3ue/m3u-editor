<?php

namespace App\Livewire;

use EslamRedaDiv\FilamentCopilot\Enums\MessageRole;
use EslamRedaDiv\FilamentCopilot\Enums\ToolCallStatus;
use EslamRedaDiv\FilamentCopilot\Livewire\CopilotChat as BaseCopilotChat;
use EslamRedaDiv\FilamentCopilot\Models\CopilotConversation;
use EslamRedaDiv\FilamentCopilot\Models\CopilotToolCall;
use Filament\Facades\Filament;
use Livewire\Attributes\On;

/**
 * Conversation switching fixes on top of the package's chat component. The rest of
 * the chat lives in the published view (resources/views/vendor/filament-copilot).
 */
class CopilotChat extends BaseCopilotChat
{
    /**
     * Ignore the completion of a stream for a conversation that is no longer selected.
     */
    #[On('copilot-stream-complete')]
    public function handleStreamComplete(string $content, ?string $newConversationId = null, ?array $toolCalls = null, ?string $messageId = null): void
    {
        if ($newConversationId && $this->conversationId && $newConversationId !== $this->conversationId) {
            return;
        }

        parent::handleStreamComplete($content, $newConversationId, $toolCalls, $messageId);
    }

    /**
     * Loading a conversation also hands any undecided approvals back to the chat, so
     * the original calls can still be approved or rejected. This only reads them.
     */
    #[On('copilot-load-conversation')]
    public function loadConversation(string $conversationId): void
    {
        parent::loadConversation($conversationId);

        $conversation = $this->ownedConversation($conversationId);

        if ($conversation) {
            $this->dispatch(
                'copilot-approvals-restored',
                conversationId: $conversationId,
                approvals: $this->pendingApprovals($conversation),
            );
        }
    }

    /**
     * Same scope as the parent's lookup. Its result can't be inferred from the selection
     * afterwards: conversationId is a public property the client is able to set.
     */
    private function ownedConversation(string $conversationId): ?CopilotConversation
    {
        $user = Filament::auth()->user();
        $panelId = Filament::getCurrentPanel()?->getId();

        if (! $user || ! $panelId) {
            return null;
        }

        return CopilotConversation::query()
            ->forPanel($panelId)
            ->forParticipant($user)
            ->forTenant(Filament::getTenant())
            ->find($conversationId);
    }

    /**
     * Same lookup the stream controller resolves decisions with: only calls under the
     * latest user message can be resumed, and only with an SDK conversation to resume.
     *
     * @return list<array{id: string, tool: string, arguments: mixed, reason: null}>
     */
    private function pendingApprovals(CopilotConversation $conversation): array
    {
        if (! ($conversation->metadata['ai_conversation_id'] ?? null)) {
            return [];
        }

        $lastUserMessage = $conversation->messages()
            ->where('role', MessageRole::User)
            ->latest()
            ->first();

        if (! $lastUserMessage) {
            return [];
        }

        return $lastUserMessage->toolCalls()
            ->where('status', ToolCallStatus::Pending)
            ->where('requires_approval', true)
            ->whereNotNull('provider_id')
            ->get()
            ->map(fn (CopilotToolCall $toolCall): array => [
                'id' => $toolCall->provider_id,
                'tool' => $toolCall->tool_name,
                'arguments' => $toolCall->tool_input,
                'reason' => null,
            ])
            ->all();
    }
}
