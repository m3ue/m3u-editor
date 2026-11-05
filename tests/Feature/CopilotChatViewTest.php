<?php

use App\Livewire\CopilotChat as AppCopilotChat;
use App\Models\User;
use EslamRedaDiv\FilamentCopilot\Enums\MessageRole;
use EslamRedaDiv\FilamentCopilot\Enums\ToolCallStatus;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Livewire\CopilotChat;
use EslamRedaDiv\FilamentCopilot\Models\CopilotConversation;
use EslamRedaDiv\FilamentCopilot\Models\CopilotToolCall;
use Filament\Facades\Filament;
use Livewire\Livewire;

function renderCopilotMessage(array $msg): string
{
    return view('filament-copilot::components.chat-message', ['msg' => $msg])->render();
}

it('skips assistant messages with nothing to show', function (string $content): void {
    $html = renderCopilotMessage(['role' => 'assistant', 'id' => '01TEST', 'content' => $content]);

    expect(trim($html))->toBe('');
})->with([
    'empty turn after a rejected approval' => '',
    'reasoning only' => '<think>checking the guide</think>',
]);

it('renders assistant markdown without reasoning, raw HTML, or script links', function (): void {
    $html = renderCopilotMessage([
        'role' => 'assistant',
        'content' => '<think>secret plan</think>Done: **mapped** [open](javascript:alert(1)) <img src=x onerror=alert(1)>',
    ]);

    expect($html)
        ->toContain('<strong>mapped</strong>')
        ->not->toContain('secret plan')
        ->not->toContain('javascript:')
        ->not->toContain('onerror');
});

it('renders a pending tool call as waiting, not successful (issue #1779)', function (): void {
    // ConversationManager::getMessagesForChat() sets success => !($status === 'failed'),
    // so a pending/undecided call carries success=true until status is checked too.
    $html = renderCopilotMessage([
        'role' => 'tool_call',
        'tool_name' => 'map_epg_channel',
        'success' => true,
        'status' => 'pending',
        'result' => null,
        'error' => null,
    ]);

    expect($html)
        ->toContain('Waiting for approval')
        ->not->toContain('text-success-500')
        ->not->toContain('text-danger-500');
});

it('renders an executed tool call as successful', function (): void {
    $html = renderCopilotMessage([
        'role' => 'tool_call',
        'tool_name' => 'map_epg_channel',
        'success' => true,
        'status' => 'executed',
        'result' => 'ok',
        'error' => null,
    ]);

    expect($html)
        ->toContain('text-success-500')
        ->not->toContain('Waiting for approval');
});

it('renders a failed tool call as an error', function (): void {
    $html = renderCopilotMessage([
        'role' => 'tool_call',
        'tool_name' => 'map_epg_channel',
        'success' => false,
        'status' => 'failed',
        'result' => null,
        'error' => 'Tool execution failed.',
    ]);

    expect($html)
        ->toContain('text-danger-500')
        ->toContain('Tool execution failed.')
        ->not->toContain('Waiting for approval');
});

it('renders the approval prompt as a Filament callout', function (): void {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::getPanel('admin')->plugin(FilamentCopilotPlugin::make()->provider('openai'));

    Livewire::test(CopilotChat::class)
        ->assertSeeHtml('fi-callout')
        ->assertSee(__('Approval required'))
        ->assertSeeHtml("submitApprovals('approve')")
        ->assertSeeHtml("submitApprovals('reject')");
});

it('sizes the chat panel from the plugin sidebar width', function (): void {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::getPanel('admin')->plugin(FilamentCopilotPlugin::make()->provider('openai')->sidebarWidth('42rem'));

    Livewire::test(CopilotChat::class)
        ->assertSeeHtml('max-width: 42rem;');
});

function copilotPendingConversation(User $user, array $metadata = ['ai_conversation_id' => 'sdk-1']): CopilotConversation
{
    $conversation = CopilotConversation::create([
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'panel_id' => 'admin',
        'title' => 'Pending',
        'metadata' => $metadata,
    ]);

    $message = $conversation->messages()->create(['role' => MessageRole::User, 'content' => 'Map it']);
    $message->toolCalls()->create([
        'provider_id' => 'call_synthetic',
        'tool_name' => 'EpgMappingApplyTool',
        'tool_input' => ['epg_map_id' => 7],
        'status' => ToolCallStatus::Pending,
        'requires_approval' => true,
    ]);

    return $conversation;
}

describe('switching conversations (issues #1797, #1798)', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->plugin(FilamentCopilotPlugin::make()->provider('openai'));
    });

    it('replaces the package chat component', function (): void {
        Livewire::test('filament-copilot-chat')->assertSeeHtml('copilot-approvals-restored');

        expect(Livewire::test('filament-copilot-chat')->instance())->toBeInstanceOf(AppCopilotChat::class);
    });

    it('restores the original pending call without deciding it', function (): void {
        $conversation = copilotPendingConversation($this->user);

        Livewire::test(AppCopilotChat::class)
            ->call('loadConversation', $conversation->id)
            ->assertDispatched(
                'copilot-approvals-restored',
                conversationId: $conversation->id,
                approvals: [[
                    'id' => 'call_synthetic',
                    'tool' => 'EpgMappingApplyTool',
                    'arguments' => ['epg_map_id' => 7],
                    'reason' => null,
                ]],
            );

        expect(CopilotToolCall::sole()->status)->toBe(ToolCallStatus::Pending);
    });

    it('restores nothing once the call is decided, superseded or unresumable', function (string $case): void {
        $conversation = copilotPendingConversation($this->user, $case === 'unresumable' ? [] : ['ai_conversation_id' => 'sdk-1']);

        if ($case === 'decided') {
            CopilotToolCall::sole()->update(['status' => ToolCallStatus::Executed]);
        }
        if ($case === 'superseded') {
            $this->travel(1)->minutes();
            $conversation->messages()->create(['role' => MessageRole::User, 'content' => 'Never mind']);
        }

        Livewire::test(AppCopilotChat::class)
            ->call('loadConversation', $conversation->id)
            ->assertDispatched('copilot-approvals-restored', conversationId: $conversation->id, approvals: []);
    })->with(['decided', 'superseded', 'unresumable']);

    it('never restores another user\'s approvals, even with the id set from the client', function (): void {
        $conversation = copilotPendingConversation(User::factory()->create());

        Livewire::test(AppCopilotChat::class)
            ->set('conversationId', $conversation->id)
            ->call('loadConversation', $conversation->id)
            ->assertNotDispatched('copilot-approvals-restored');
    });

    it('drops a late completion from a conversation that is no longer selected', function (): void {
        $selected = copilotPendingConversation($this->user);

        Livewire::test(AppCopilotChat::class)
            ->call('loadConversation', $selected->id)
            ->call('handleStreamComplete', 'old reply', 'some-other-conversation')
            ->assertSet('conversationId', $selected->id)
            ->assertDontSee('old reply')
            ->call('handleStreamComplete', 'current reply', $selected->id)
            ->assertSee('current reply');
    });
});
