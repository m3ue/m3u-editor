<?php

use App\Models\User;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Livewire\CopilotChat;
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
