# AI Copilot

The AI Copilot is an in-app chat assistant powered by [Laravel AI](https://github.com/laravel/ai). It appears as a chat icon in the top navigation bar and allows admins to interact with the application using natural language — searching records, creating or editing data, navigating pages, and looking up documentation.

---

## Table of Contents

- [How It Works](#how-it-works)
- [Enabling & Configuring the Copilot](#enabling--configuring-the-copilot)
- [Supported AI Providers](#supported-ai-providers)
- [Global Tools](#global-tools)
- [Quick Actions](#quick-actions)
- [Management Mode](#management-mode)
- [Architecture Overview](#architecture-overview)
- [How to Add Copilot Support to a Resource](#how-to-add-copilot-support-to-a-resource)
- [How to Build a Custom Tool](#how-to-build-a-custom-tool)
- [Patched OpenAI Gateway](#patched-openai-gateway)

---

## How It Works

When the Copilot is enabled, the `FilamentCopilotPlugin` is registered with the Filament admin panel. The plugin injects a chat interface into the UI and wires it to a configured AI provider (OpenAI, Anthropic, Gemini, etc.).

The AI can call **tools** to take real actions — listing database records, creating or editing entries, fetching documentation pages, and more. Tools are PHP classes that implement a typed interface; the AI receives their descriptions and JSON schemas, then calls them autonomously during a conversation.

Settings (provider, model, API key, enabled tools, quick actions) are stored in the database via `GeneralSettings` and loaded at runtime, so no deployment is required to change them.

---

## Enabling & Configuring the Copilot

Configuration is done through **Preferences → AI Copilot** (admin only).

| Setting | Description |
|---|---|
| **Enable AI Copilot** | Master toggle. Shows/hides the chat icon in the navbar. |
| **Enable AI Copilot Management** | Enables conversation history, audit log, and rate-limit management. |
| **AI Provider** | Which AI backend to use (OpenAI, Anthropic, Gemini, etc.). |
| **Model** | Model name. Leave blank to use the provider default (see table below). |
| **API Key** | Your provider API key. Not required for Ollama. |
| **System Prompt** | Custom instructions prepended to every conversation. |
| **Global Tools** | Which built-in tools the assistant can use on any page. |
| **Quick Actions** | Pre-defined prompts shown as buttons in the chat window. |

Settings are persisted in the `settings` table under `GeneralSettings`. The API key is injected into the `config/ai.php` provider config at boot time via `AppServiceProvider::applyCopilotApiKeyFromSettings()`, so it takes effect without restarting the server.

> The Copilot is only accessible to users where `$user->isAdmin()` returns `true`.

---

## Supported AI Providers

| Provider key | Notes |
|---|---|
| `openai` | Default. Set `OPENAI_API_KEY` or enter via Preferences. |
| `anthropic` | Set `ANTHROPIC_API_KEY`. |
| `gemini` | Set `GEMINI_API_KEY`. |
| `mistral` | Set `MISTRAL_API_KEY`. |
| `ollama` | Self-hosted. Set `OLLAMA_BASE_URL` (default `http://localhost:11434`). No API key needed. |
| `azure` | Azure OpenAI. Requires `AZURE_OPENAI_API_KEY`, `AZURE_OPENAI_URL`, `AZURE_OPENAI_DEPLOYMENT`. |
| `groq` | Set `GROQ_API_KEY`. |
| `openrouter` | Set `OPENROUTER_API_KEY`. |
| `deepseek` | Set `DEEPSEEK_API_KEY`. |
| `xai` | Set `XAI_API_KEY`. |

**Default models** (used when the Model field is left blank):

| Provider | Default model |
|---|---|
| `openai` | `gpt-4o` |
| `anthropic` | `claude-sonnet-4` |
| `gemini` | `gemini-2.0-flash` |
| `mistral` | `mistral-large-latest` |
| `ollama` | `llama3` |

All provider and model options are defined in `config/ai.php` and `AdminPanelProvider::COPILOT_DEFAULT_MODELS`.

The default system prompt tells the AI that it can look up the live TV guide (what is on right now / later today / tomorrow / this week, what is airing around a specific show, and full channel schedules). It also instructs the AI to call **Get Available Tools** whenever a user asks for something it cannot do, identify which tool is missing, and tell the user exactly which checkbox to enable in **Preferences → AI Copilot → Global Tools**. For example, if `DvrScheduleTool` is disabled and the user asks about TV listings, the AI will respond: *"I do not have the TV schedule tool enabled. Enable DVR: Schedule in Preferences → AI Copilot → Global Tools and try again."* Custom system prompts override this default.

---

## Global Tools

Global tools are available to the assistant on every page. They can be toggled on/off individually in Preferences.

| Tool class | Label | Description |
|---|---|---|
| `GetToolsTool` | Get Available Tools | Lists all tools currently available to the assistant in the current context. |
| `RunToolTool` | Run Tool | Lets the AI execute another tool by name. |
| `ListResourcesTool` | List Resources | Lists all registered Filament resources. |
| `ListPagesTool` | List Pages | Lists all registered Filament pages. |
| `ListWidgetsTool` | List Widgets | Lists all registered Filament widgets. |
| `RememberTool` | Remember | Stores a piece of information in the assistant's memory for the current user. |
| `RecallTool` | Recall Memories | Retrieves stored memories for the current user. |
| `SearchDocsTool` | Search Documentation | Searches `https://m3ue.sparkison.dev/docs` for relevant pages and returns excerpts. |
| `DvrScheduleTool` | DVR: Schedule | Browse the EPG TV guide and schedule recordings. **Default-enabled.** |

The first eight tools (`GetToolsTool` through `RecallTool`) come from the `filament-copilot` package. `SearchDocsTool` and `DvrScheduleTool` are custom tools defined in `app/Filament/CopilotTools/`.

### DVR Schedule Tool

`DvrScheduleTool` answers questions about what is on TV on the user's mapped channels. The default system prompt explicitly tells the AI it can look up the live TV guide, so questions like *"what is on WE TV around Love After Lockup later today"* are answered without the user having to enable the tool manually.

**Actions:**

| Action | Purpose |
|---|---|
| `now_playing` | What is currently airing on a specific channel. |
| `search` | Upcoming programmes by title/keyword, optionally filtered by channel and time window. |
| `around` | Programmes airing before and after a specific show on a channel (e.g. *"what is on WE TV around Love After Lockup later today"*). |
| `channel_schedule` | Full programme schedule for a channel within a time window. |
| `schedule_once` | Record a specific programme once. |
| `schedule_series` | Create a series recording rule. |
| `delete_rule` | Delete a recording rule. |
| `remind` | Create a one-shot recording (used as a reminder). |

**Time window filter** (`today` / `tomorrow` / `this_week`): the `search`, `around`, and `channel_schedule` actions accept a `time_window` parameter so results can be focused. `today` is from now to end of day; `tomorrow` is the full next day; `this_week` is the next 7 days (default, preserves back-compat).

The `around` action additionally accepts `airing_time` (ISO 8601 datetime) to anchor the match to a specific time — useful for *"what is on around 8 PM tonight"* — plus `context_before` and `context_after` (defaults 2 and 3) to control how many neighbours of the matched programme are returned.

---

## Quick Actions

Quick Actions are pre-defined prompts that appear as clickable buttons in the chat window. They are useful for common workflows you want users to trigger with a single click.

To add a Quick Action, go to **Preferences → AI Copilot → Quick Actions** and click **Add Quick Action**. Each action has:

- **Label** — The button text (e.g. `Find a channel`)
- **Prompt** — The message sent to the AI when clicked (e.g. `Help me find a channel by name`)

Quick actions are stored in `GeneralSettings::$copilot_quick_actions` as an array of `{label, prompt}` objects.

---

## Management Mode

When **Enable AI Copilot Management** is toggled on, the plugin exposes additional admin features:

- Conversation history browser
- Per-user usage audit log
- Configurable rate limits

Management features use the `admin` guard and are powered by the `filament-copilot` package internals. The management guard is set in `AdminPanelProvider::buildCopilotPlugin()`:

```php
->managementEnabled($s['copilot_mgmt_enabled'] ?? false)
->managementGuard('admin')
```

---

## Architecture Overview

```
Preferences (UI)
    └── GeneralSettings (DB: settings table)
            └── AppServiceProvider::applyCopilotApiKeyFromSettings()
                    └── config("ai.providers.{provider}.key")

AdminPanelProvider::panel()
    └── buildCopilotPlugin()
            └── FilamentCopilotPlugin::make()
                    ├── provider / model / systemPrompt
                    ├── globalTools([...])          ← from settings
                    └── quickActions([...])         ← from settings

FilamentCopilotPlugin
    └── Uses Laravel AI (PatchedAiManager → PatchedOpenAiGateway)
            └── Tool calls dispatched to:
                    ├── Global tools (GetToolsTool, SearchDocsTool, …)
                    └── Resource tools (ListRecordsTool, CreateRecordTool, …)
                            └── Registered via HasCopilotSupport trait on each Resource
```

Key files:

| File | Purpose |
|---|---|
| `app/Providers/Filament/AdminPanelProvider.php` | Registers the plugin; `buildCopilotPlugin()` constructs it from settings |
| `app/Providers/AppServiceProvider.php` | Injects the API key into the AI config at boot |
| `app/AI/PatchedAiManager.php` | Replaces the default AI manager to use the patched gateway |
| `app/AI/PatchedOpenAiGateway.php` | Fixes OpenAI strict-mode bugs (see below) |
| `app/Filament/CopilotTools/` | All custom tool classes |
| `app/Filament/Concerns/HasCopilotSupport.php` | Trait that wires a Resource to CRUD tools |
| `config/ai.php` | Provider config (keys, URLs, models) |
| `config/filament-copilot.php` | Plugin-level defaults (provider, model, agent timeout, chat settings, rate limits) |
| `app/Settings/GeneralSettings.php` | Runtime settings including all `copilot_*` fields |

---

## How to Add Copilot Support to a Resource

To make a Filament Resource available to the Copilot (list, search, view, create, edit, delete), add the `HasCopilotSupport` trait:

```php
use App\Filament\Concerns\HasCopilotSupport;

class MyResource extends Resource
{
    use HasCopilotSupport;

    // ...
}
```

The trait automatically generates all six CRUD tools bound to the resource's model. Optionally override `copilotResourceDescription()` to customise how the AI describes the resource:

```php
public static function copilotResourceDescription(): ?string
{
    return 'Manages live stream channels in the application.';
}
```

Or override `copilotTools()` to restrict or extend the default tool set:

```php
public static function copilotTools(): array
{
    $resource = static::class;

    // Read-only: no create / edit / delete
    return [
        new ListRecordsTool($resource),
        new SearchRecordsTool($resource),
        new ViewRecordTool($resource),
    ];
}
```

The tools are registered with the Filament Copilot plugin via the package's resource-discovery mechanism. No additional registration is required.

---

## How to Build a Custom Tool

All custom tools live in `app/Filament/CopilotTools/`. To create a new tool:

### 1 — Extend the right base class

For a **standalone** tool (not tied to a resource), extend `BaseTool` from the package:

```php
use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;

class MyCustomTool extends BaseTool
{
    // ...
}
```

For a **resource-aware** tool that needs model/label/column introspection, extend `AbstractResourceTool` instead:

```php
use App\Filament\CopilotTools\AbstractResourceTool;

class MyResourceTool extends AbstractResourceTool
{
    // ...
}
```

### 2 — Implement the three required methods

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class MyCustomTool extends BaseTool
{
    /**
     * A short description of what this tool does.
     * The AI uses this to decide when to call it.
     */
    public function description(): Stringable|string
    {
        return 'Fetch the current server status.';
    }

    /**
     * Define the tool's input parameters as a JSON Schema.
     * Return an empty array if the tool takes no arguments.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'target' => $schema->string()
                ->description('The service to check (e.g. "redis", "queue")')
                ->required(),
        ];
    }

    /**
     * Execute the tool and return a plain-text result.
     * The AI includes this text in its response.
     */
    public function handle(Request $request): Stringable|string
    {
        $target = (string) $request['target'];

        // … do work …

        return "Service '{$target}' is running normally.";
    }
}
```

Key points:

- `description()` — Keep it concise and action-oriented. The AI reads this to decide when to invoke the tool.
- `schema()` — Use `$schema->string()`, `$schema->integer()`, `$schema->boolean()` etc. Chain `->description()` and `->required()` per field. Return `[]` for no-argument tools.
- `handle()` — Access arguments via `$request['key']`. Return a plain string; the AI will relay it to the user. Avoid throwing exceptions; return an error string instead.

### 3 — Register the tool

**As a Global Tool** — add it to the global tools checkbox list in `Preferences.php`:

```php
CheckboxList::make('copilot_global_tools')
    ->options([
        // existing tools …
        MyCustomTool::class => __('My Custom Tool'),
    ])
```

Then add it to the default selection so it is enabled out of the box:

```php
->default([
    // existing defaults …
    MyCustomTool::class,
])
```

**As a Resource Tool** — return an instance from `copilotTools()` in the resource:

```php
public static function copilotTools(): array
{
    $resource = static::class;
    return [
        new ListRecordsTool($resource),
        new MyResourceTool($resource),   // ← add here
    ];
}
```

### 4 — Helpers available in AbstractResourceTool

When extending `AbstractResourceTool` your tool receives the resource class in its constructor. The following helpers are available:

| Method | Returns |
|---|---|
| `getModelClass()` | Fully-qualified Eloquent model class name |
| `getModelLabel()` | Singular human label (e.g. `"Channel"`) |
| `getPluralLabel()` | Plural human label (e.g. `"Channels"`) |
| `searchableAttributes()` | Columns used in global search; falls back to `['name']` |
| `writableColumns()` | All DB columns excluding protected fields (`id`, `password`, etc.) |
| `formatRecord(Model $record)` | Returns a compact `key: value` string for a record |
| `stripProtectedFields(array $data)` | Strips sensitive keys from user-supplied data before write operations |

---

## Patched OpenAI Gateway

The standard Laravel AI library has two bugs with the OpenAI provider that affect tools with optional parameters:

1. `strict: true` is hardcoded, but OpenAI rejects optional properties (not in `required`) under strict mode.
2. When no schema is defined and `strict: true`, the `parameters` key is omitted entirely — OpenAI requires it to be present.

These are fixed by `app/AI/PatchedOpenAiGateway.php`, which overrides `mapTool()` to drop strict mode and always emit a `parameters` object. The patched gateway is wired in via `app/AI/PatchedAiManager.php`, which is bound in place of the default `AiManager` in `AppServiceProvider`.

No action is required to benefit from these fixes — they are always active.
