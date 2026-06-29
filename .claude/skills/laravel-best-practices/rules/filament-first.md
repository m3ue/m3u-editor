# Filament-First UI Rules

This project uses Filament v5. All UI in Blade views must use Filament components when one exists. Never write hand-rolled HTML equivalents. When in doubt, check `search-docs` for the Filament component.

---

## Blade UI Components

### Badges / Status Labels

**Use** `<x-filament::badge color="success|warning|danger|gray|primary|info">Label</x-filament::badge>`

**Never** hand-roll `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full ...">`.

---

### Icons

**Use** `<x-filament::icon icon="heroicon-o-name" class="w-4 h-4" />`

**Never** embed raw `<svg>...</svg>` blocks or use `<x-heroicon-*>` in views under Filament pages/panels.

Note: `<x-heroicon-*>` components are acceptable **only** inside non-Filament Livewire views (e.g., results grids) where `<x-filament::icon>` would add overhead — but prefer `<x-filament::icon>` everywhere by default.

---

### Buttons

**Use** `<x-filament::button color="primary|gray|danger" icon="heroicon-o-name">Label</x-filament::button>`

**Use** `<x-filament::icon-button icon="heroicon-o-name" :label="__('...')" />` for icon-only buttons.

**Never** write `<button class="inline-flex items-center justify-center ... rounded-lg ...">`.

---

### Loading Spinners

**Use** `<x-filament::loading-indicator class="h-5 w-5 text-primary-500" />`

Standard size is **`h-5 w-5`**. Never use `h-6`, `h-7`, `h-8` etc. for inline/results-area spinners.

**Never** write `<div class="animate-spin rounded-full border-4 ...">`.

For Livewire `placeholder()` methods, return `view()` (not a raw HTML string heredoc) so Blade components render correctly:

```php
// Good
public function placeholder(): \Illuminate\Contracts\View\View
{
    return view('livewire.partials.my-placeholder');
}

// Bad — Blade components like <x-filament::*> do not work in heredoc strings
public function placeholder(): string
{
    return <<<'HTML'
    <div class="animate-spin ..."></div>
    HTML;
}
```

---

### Dropdowns / Kebab Menus

**Use** `<x-filament::dropdown placement="top-end">` with `<x-filament::dropdown.list.item>` items.

**Never** build Alpine.js dropdown menus from scratch.

Avoid `content-visibility: auto` on card divs that contain dropdowns — this CSS property implicitly applies `contain: paint`, which clips dropdown overflow even when `overflow-visible` is set on the container.

---

### Collapsible Sections

**Use** `<x-filament::section :collapsible="true" compact heading="...">` for collapsible panels.

**Never** build `x-data="{ open: false }"` accordion patterns from scratch when a section component exists.

---

### Form Inputs (in Blade, not schema)

**Use** `<x-filament::input.wrapper>` + `<x-filament::input type="text" wire:model="..." />`

**Use** `<x-filament::input.select wire:model="...">` for selects.

**Never** write raw `<input class="border rounded ...">` or `<select class="...">`.

---

## Actions / Modals

### Slide-over Panels

Replace hand-rolled Alpine slide-overs with Filament Actions:

```php
public function showDetailAction(): Action
{
    return Action::make('showDetail')
        ->slideOver()
        ->modalHeading(false)          // suppress if content has its own visual header
        ->modalContent(fn () => view('livewire.partials.my-detail', [
            'item' => $this->selectedItem,  // always pass data explicitly — see gotcha below
        ]))
        ->modalSubmitAction(false)
        ->modalCancelAction(false);
}
```

Trigger from a Livewire method with `$this->mountAction('showDetail')`.

**For plain Livewire components** (not Filament Pages), add to the class:

```php
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;

class MyComponent extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
}
```

And add to the blade view: `<x-filament-actions::modals />`

---

### Confirmations

Replace `window.confirm()` / Alpine `x-on:click="if (confirm(...)) ..."` with `->requiresConfirmation()`:

```php
public function confirmDeleteAction(): Action
{
    return Action::make('confirmDelete')
        ->requiresConfirmation()
        ->modalHeading(__('Delete?'))
        ->modalDescription(__('This cannot be undone.'))
        ->color('danger')
        ->action(fn () => $this->delete());
}
```

---

## Critical Gotchas

### Modal content views are rendered on every render, not just when open

`->modalContent(fn () => view(...))` is evaluated on **every** Livewire component render cycle, including the initial page load before any action is mounted. This means the view must:

1. **Always receive data explicitly** — Filament renders the view outside Livewire's property injection, so public properties are NOT automatically available as `$variable`:

```php
// Good
->modalContent(fn () => view('livewire.partials.my-detail', [
    'item' => $this->selectedItem,
    'guestMode' => $this->guestMode,
]))

// Bad — $item will be undefined when modal is closed
->modalContent(fn () => view('livewire.partials.my-detail'))
```

2. **Guard against null** at the top of the view, since data is null before the action mounts:

```blade
@if ($item)
    {{-- render content --}}
@endif
```

### Deferred loading in modal content

To trigger a Livewire method when a modal opens (e.g., load episodes after a slide-over appears), use `x-init` in the modal content view:

```blade
<div x-init="$wire.call('loadEpisodes')">
    ...
</div>
```

Do NOT rely on Alpine `$watch('$wire.showDetail', ...)` patterns after migrating to Filament actions.
