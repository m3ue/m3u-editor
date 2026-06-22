# Plugin System

`m3u-editor` now ships with a trusted-local plugin kernel for extension work on this fork.
Normal use should go through the reviewed-install flow for private local plugins or archives.

Install review currently starts from either:

- a filesystem path the host/container can already read, or
- a browser-uploaded archive stored by the host, or
- a published GitHub release asset URL

In Docker deployments, "local" means a path that is visible inside the container.

## Principles

- Plugins extend published capabilities instead of reaching into arbitrary internals.
- Discovery is local and explicit.
- Normal mode supports reviewed local directories and reviewed archives.
- Dev mode keeps direct folder discovery for plugin authors.
- Long-running work runs through queued invocations.
- Validation happens before a plugin can be trusted.
- Pre-trust validation is static: the host inspects the manifest and entrypoint without executing plugin PHP.
- Trust is explicit. Discovery does not imply trust.
- Execution requires: installed + enabled + valid + trusted + integrity verified.

## Directory Layout

Plugins live in `plugins/<plugin-id>/`.

Required files:

- `plugin.json`
- entrypoint file referenced by `plugin.json`, usually `Plugin.php`

To scaffold a new local plugin:

```bash
php artisan make:plugin "Acme XML Tools"
```

Useful options:

```bash
php artisan make:plugin "Acme XML Tools" \
  --capability=channel_processor \
  --capability=scheduled \
  --hook=playlist.synced \
  --lifecycle
```

The scaffold creates:

- `plugins/<plugin-id>/plugin.json`
- `plugins/<plugin-id>/Plugin.php`
- `plugins/<plugin-id>/README.md`
- `plugins/<plugin-id>/.github/workflows/plugin-ci.yml`
- `plugins/<plugin-id>/scripts/package-plugin.sh`
- `plugins/<plugin-id>/scripts/validate-plugin.php`
- `plugins/<plugin-id>/AGENTS.md`
- `plugins/<plugin-id>/CLAUDE.md`

The generated plugin is designed to validate immediately and includes a simple `health_check` action so operators can exercise it right away from `Extensions -> Extensions`.
Use `--bare` when you only want the runtime plugin files without the author starter kit.

## Install Modes

- `normal`: reviewed local directories and reviewed archives are the supported install path
- `dev`: keeps direct folder discovery for configured author directories only

Self-hosted private plugins do not need dev mode if they go through reviewed install.
`dev` mode is for local authoring only and should not be used on production servers.

Dev mode limitations:

- only plugins under configured `PLUGIN_DEV_DIRECTORIES` qualify as `local_dev`
- it is meant for local authoring convenience, not production installs
- private production plugins should still use reviewed install in `normal` mode

## Manifest

Example:

```json
{
  "id": "sample-plugin",
  "name": "Sample Plugin",
  "version": "1.0.0",
  "api_version": "1.0.0",
  "description": "Demonstrates a reviewed plugin install with one health check action.",
  "entrypoint": "Plugin.php",
  "class": "AppLocalPlugins\\SamplePlugin\\Plugin",
  "capabilities": ["scheduled"],
  "hooks": [],
  "permissions": ["queue_jobs", "scheduled_runs"],
  "schema": {
    "tables": []
  },
  "data_ownership": {
    "directories": ["plugin-reports/sample-plugin"],
    "default_cleanup_policy": "preserve"
  },
  "settings": [],
  "actions": []
}
```

Required fields:

- `id`
- `name`
- `entrypoint`
- `class`

Important fields:

- `api_version`: must match the host plugin API version
- `capabilities`: determines which contract interfaces the plugin class must implement
- `hooks`: optional lifecycle hooks the plugin wants to receive
- `permissions`: explicit host-facing declaration of what the plugin expects to do
- `schema`: host-managed plugin-owned table declarations; `tables` defines the physical schema, `ui_tables` declares admin CRUD UIs rendered by the host
- `settings`: operator-configurable schema
- `actions`: manual actions exposed in the plugin edit page
- `data_ownership`: plugin-owned tables, files, and directories that uninstall may preserve or purge

## Trust And Integrity

Plugins are **trusted-local**, not sandboxed.

Current trust lifecycle:

- `pending_review`: discovered but not yet trusted
- `trusted`: admin reviewed and pinned the current plugin hashes
- `blocked`: admin explicitly blocked execution

Current integrity states:

- `unknown`: discovered but not yet trusted
- `verified`: current files match the trusted hash snapshot
- `changed`: files changed after trust and need review again
- `missing`: plugin files are missing on disk

Admin review should check:

- manifest permissions
- owned schema
- owned storage paths
- intended hooks/schedules
- current file integrity state
- ClamAV result for reviewed installs

## Reviewed Install Flow

Phase 1 source types:

- `local_directory`
- `staged_archive`
- `github_release`
- `uploaded_archive`
- `local_dev`

Typical local-directory review flow:

```bash
php artisan plugins:stage-directory /absolute/path/to/my-plugin
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

If you use the UI for a local directory or local archive, enter a path that the host/container can already read.
For private plugins in Docker, the recommended path is the browser upload action instead of a container-visible filesystem path.

Typical archive review flow:

```bash
php artisan plugins:stage-archive /absolute/path/to/my-plugin.zip
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

Typical browser upload review flow:

1. Open `Extensions -> Plugin Installs`.
2. Choose `Upload Plugin Archive`.
3. Upload the plugin `.zip`, `.tar`, `.tar.gz`, or `.tgz` archive from your browser.
4. Run the scan and approve/trust actions from the review page.

Browser upload stores the archive on the host's private local disk just long enough to stage the review.
The host computes the archive checksum itself, then moves the archive into review staging and applies the same validation, ClamAV scan, approval, trust, and integrity rules as any other reviewed install source.
In Docker, keep `storage/app` on persistent storage if reviewers need uploaded or staged plugin reviews to survive container restarts.
If a reviewed install has the same plugin id as an already-installed plugin, approving it updates the existing plugin files in place. `Install And Trust` re-trusts the updated files and restores the enabled state if the plugin was already active.

Typical GitHub release review flow:

```bash
php artisan plugins:stage-github-release \
  https://github.com/<owner>/<repo>/releases/download/<tag>/plugin.zip \
  --sha256=<published-sha256>
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

Review statuses:

- `staged`
- `scanned`
- `review_ready`
- `approved`
- `rejected`
- `installed`
- `discarded`

Scan statuses:

- `pending`
- `clean`
- `infected`
- `scan_failed`
- `scanner_unavailable`

ClamAV is required to trust reviewed installs in normal mode.

### Docker scanner modes

The dev Docker stack defaults to the fake scanner so normal UI work stays fast.

To run a real local ClamAV scan in Docker, rebuild the dev image with ClamAV enabled and switch the driver to `clamav`:

```bash
INSTALL_CLAMAV=true \
PLUGIN_SCAN_DRIVER=clamav \
CLAMAV_UPDATE_DEFINITIONS=true \
docker compose -f docker-compose.dev.yml up --build
```

Notes:

- real scan uses `clamscan`, not `clamd`
- ClamAV signatures are stored in the Docker volume mounted at `/var/lib/clamav`
- if signatures are missing, startup will try to initialize them before the app boots
- production or review environments should keep real scanning enabled before trust

## Lifecycle

The host treats plugin lifecycle states explicitly:

- `Enable`: plugin can run manual actions, hooks, and schedules
- `Disable`: plugin stays installed, but nothing runs
- `Uninstall`: plugin is marked uninstalled and optionally purges plugin-owned data
- `Forget Registry Record`: removes only the `extension_plugins` row; local files remain and discovery can register the plugin again

`Disable` is reversible.

`Uninstall` is the action that changes lifecycle state and drives cleanup.

## Data Ownership

Plugins that persist their own data must declare it in `data_ownership`.

Supported keys:

- `tables`
- `directories`
- `files`
- `default_cleanup_policy`

Cleanup policies:

- `preserve`
- `purge`

### Table naming rules

Plugin-owned tables must start with:

```text
plugin_<plugin_id_with_dashes_replaced_by_underscores>_
```

Example for `sample-plugin`:

```text
plugin_sample_plugin_events
```

### Storage path rules

Plugin-owned files and directories must live under approved storage roots and remain namespaced by plugin id.

Current approved roots:

- `plugin-data/<plugin-id>/...`
- `plugin-reports/<plugin-id>/...`

Examples:

- `plugin-reports/sample-plugin`
- `plugin-data/sample-plugin/cache/state.json`

Invalid examples:

- `/tmp/sample-plugin`
- `storage/app/plugin-reports/sample-plugin`
- `plugin-reports/shared`
- `../reports/sample-plugin`

The host uses these declarations during uninstall so it can safely preserve or purge plugin-owned artifacts without guessing.

## Permissions

Supported permissions:

- `db_read`
- `db_write`
- `schema_manage`
- `filesystem_read`
- `filesystem_write`
- `network_egress`
- `queue_jobs`
- `hook_subscriptions`
- `scheduled_runs`

Rules:

- hooks require `hook_subscriptions`
- scheduled capability requires `scheduled_runs`
- declared schema requires `schema_manage`
- declared files/directories require `filesystem_write`

`network_egress` is declarative only in this phase. It is shown during review, but not OS-sandboxed.

## Host-Managed Schema

Plugins do not run arbitrary migrations.

Instead, they declare owned tables in `schema.tables`, and the host creates or purges them.

Current supported column types:

- `id`
- `foreignId`
- `string`
- `text`
- `boolean`
- `integer`
- `bigInteger`
- `decimal`
- `json`
- `timestamp`
- `timestamps`

Current supported index types:

- `index`
- `unique`

## UI Tables

Plugins can declare admin CRUD UIs for their owned tables via `schema.ui_tables`. The host renders these in a dedicated **Data** tab on the plugin edit page. Each entry links to a full create/edit/delete table page — no plugin PHP is required.

Example:

```json
"schema": {
  "tables": [
    {
      "name": "plugin_sample_plugin_profiles",
      "columns": [
        { "type": "id", "name": "id" },
        { "type": "foreignId", "name": "extension_plugin_id", "references": "extension_plugins", "on_delete": "cascade" },
        { "type": "string", "name": "name" },
        { "type": "boolean", "name": "enabled", "default": true },
        { "type": "timestamps" }
      ],
      "indexes": []
    }
  ],
  "ui_tables": [
    {
      "id": "profiles",
      "label": "Profiles",
      "model_label": "Profile",
      "table": "plugin_sample_plugin_profiles",
      "description": "Reusable configuration profiles.",
      "export_formats": [],
      "columns": [
        { "name": "name", "label": "Name", "searchable": true, "sortable": true },
        { "name": "enabled", "label": "Enabled", "type": "boolean", "editable": true }
      ],
      "fields": [
        { "id": "name", "label": "Name", "type": "text", "required": true },
        { "id": "enabled", "label": "Enabled", "type": "boolean", "default": true }
      ]
    }
  ]
}
```

### ui_table definition

| Field | Required | Description |
|---|---|---|
| `id` | yes | Unique identifier within this plugin (used in the URL) |
| `label` | yes | Page heading |
| `table` | yes | Physical table name — must be declared in `schema.tables` |
| `model_label` | no | Singular noun used in "New …" button (defaults to singular of `label`) |
| `description` | no | Subheading shown on the table page |
| `create` | no | Set `false` to hide the create action (default: `true`) |
| `edit` | no | Set `false` to hide per-row edit action (default: `true`) |
| `delete` | no | Set `false` to hide per-row delete action (default: `true`) |
| `delete_behavior` | no | Set to `"clear"` to update the row with `delete_payload` instead of deleting it |
| `delete_payload` | no | Object of values to save when `delete_behavior` is `"clear"`; dot-notation keys (e.g. `settings.key`) write the **entire** parent JSON column — include every key you want to preserve |
| `delete_label` | no | Label used for a clear-style delete action |
| `delete_icon` | no | Icon used for a clear-style delete action (default: `heroicon-o-x-mark`) |
| `delete_color` | no | Button color used for a clear-style delete action (default: `gray`) |
| `delete_description` | no | Modal body text for a clear-style delete action |
| `delete_submit_label` | no | Modal submit button label for a clear-style delete action (default: `Clear`) |
| `delete_success_message` | no | Success notification title after a clear action completes |
| `export_formats` | no | Inline table download formats. Use `["csv"]`, `["json"]`, `["csv", "json"]`, or `[]` to disable exports. Omitted defaults to both CSV and JSON. |
| `columns` | no | Column definitions for the list view |
| `fields` | no | Field definitions for the create/edit form — uses the same field types as `settings` |
| `prefill` | no | Auto-populate rows from a source table on page mount |

Inline table downloads are generated by the host from the current database rows at request time. The plugin controls only which declared formats are available for each `ui_table`. Downloaded files include only the columns declared in `columns`, in declaration order, using the declared `label` as the CSV header.

### Run result tables

When a `ui_table`'s physical table includes an `extension_plugin_run_id` column, the host treats it as a run result table and surfaces it on the plugin run detail page automatically. No extra declaration is required — the column presence is the signal.

```json
{ "type": "foreignId", "name": "extension_plugin_run_id", "references": "extension_plugin_runs", "on_delete": "cascade" }
```

When the run detail page loads, the table is scoped to that run. If the run's payload includes `playlist_id`, the table is also scoped to that playlist. Filters for run and playlist appear automatically when the scope is not already fixed by the page context.

Typical result table pattern:

```json
{
  "id": "run_results",
  "label": "Run Results",
  "table": "plugin_example_run_results",
  "create": false,
  "edit": false,
  "delete": false,
  "export_formats": ["csv"],
  "columns": [
    { "name": "created_at", "label": "Created", "type": "datetime", "sortable": true },
    { "name": "result_type", "label": "Type", "sortable": true },
    { "name": "decision", "label": "Decision", "sortable": true },
    { "name": "title", "label": "Title", "searchable": true }
  ],
  "fields": []
}
```

### Column definitions

| Field | Description |
|---|---|
| `name` | Column name in the table; dot-notation supported for `json` columns (e.g. `settings.mode`) |
| `label` | Column header |
| `type` | `boolean` renders a check/cross icon; `datetime` formats via the user's date format; omit for plain text |
| `editable` | `true` makes the column inline-editable: booleans become a toggle, selects become an inline dropdown |
| `searchable` | Enable full-text search on this column (plain columns only, no dot-notation) |
| `sortable` | Enable column sort (plain columns only, no dot-notation) |
| `options` | Static `{ "value": "Label" }` map used for display in a text column |
| `options_provider` | Provider name for dynamic select options via `PluginSelectOptionsProviderInterface` (see below) |
| `depends_on` | List of column names whose current row values are passed to the `options_provider` as context |
| `lookup` | Resolve a foreign-key value to a display label (see below) |
| `limit` | Character truncation limit for text columns (default: `80`) |
| `required` | Required rule for inline-editable select columns |
| `placeholder` | Placeholder for inline-editable select columns |

#### options_provider

When `options_provider` is set, the column's select options are loaded dynamically at render time via the plugin's `PluginSelectOptionsProviderInterface` implementation rather than a static `options` map.

```json
{
  "name": "settings.source",
  "label": "Source",
  "editable": true,
  "options_provider": "fixture_sources",
  "depends_on": ["settings.provider", "settings.country"]
}
```

The host calls `selectOptions($provider, $context)` on your plugin class, where `$context` is a `PluginSelectOptionsContext` carrying the current row values for every field listed in `depends_on`. Return an associative array of `value => label` pairs.

`depends_on` without `options_provider` has no effect (the validator will warn).

#### lookup

`lookup` resolves a stored ID/key to a human-readable label from another table.

```json
{ "table": "playlists", "label_column": "name", "key_column": "id", "scope_plugin": false, "enabled_only": false, "limit": 500 }
```

- `table`: the table to look up from — may be a plugin-owned table or a host table (e.g. `playlists`)
- `label_column`: the column to display (default: `name`)
- `key_column`: the column to match against the stored value (default: `id`)
- `source_column`: column on the current record to read the FK value from (defaults to `name`)
- `scope_plugin`: if `true` and the lookup table has `extension_plugin_id`, filter to this plugin's rows
- `enabled_only`: if `true` and the lookup table has `enabled`, filter to enabled rows
- `limit`: maximum options returned (capped at `500`)

### Prefill

`prefill` auto-inserts one row per source-table record when the table page loads. Rows that already exist (matched by `target_column`) are skipped.

```json
"prefill": {
  "source": {
    "table": "playlists",
    "key_column": "id",
    "user_column": "user_id",
    "order_column": "name",
    "scope": "owned"
  },
  "target_column": "playlist_id",
  "defaults": {
    "enabled": false,
    "settings.run_sync": true
  }
}
```

- `source.table`: source table to read from — may be a host table
- `source.key_column`: column whose value is written into `target_column` (default: `id`)
- `source.user_column`: user ownership column on the source table (default: `user_id`)
- `source.order_column`: sort column for source rows (default: `key_column`)
- `source.scope`: `"owned"` filters source rows to the authenticated user
- `target_column`: column on the target table to populate with the source key value
- `defaults`: static defaults to write on each new row; dot-notation sets nested `json` keys

The prefill source is capped at `config('plugins.prefill_max_source_rows')` rows (default: 1000).

For prefilled tables where the source row should remain visible, use `delete_behavior: "clear"` to make the row action reset configuration instead of deleting the physical row:

```json
"delete_behavior": "clear",
"delete_label": "Clear Assignment",
"delete_payload": {
  "extension_plugin_profile_id": null,
  "enabled": false,
  "settings.run_sync": true
}
```

## Capabilities

Current capabilities:

- `epg_processor`
- `channel_processor`
- `stream_analysis`
- `scheduled`

## Hooks

Current hook names:

- `playlist.synced`
- `epg.synced`
- `epg.cache.generated`
- `before.epg.map`
- `after.epg.map`
- `before.epg.output.generate`
- `after.epg.output.generate`

## Contracts

Base contract:

```php
interface PluginInterface
{
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult;
}
```

Optional contracts:

- `HookablePluginInterface`
- `ScheduledPluginInterface`
- `PluginSelectOptionsProviderInterface` — required to use `options_provider` on UI table columns
- capability-specific interfaces in `app/Plugins/Contracts`

### PluginSelectOptionsProviderInterface

Implement this interface to serve dynamic select options for columns that declare `options_provider`.

```php
use App\Plugins\Contracts\PluginSelectOptionsProviderInterface;
use App\Plugins\Support\PluginSelectOptionsContext;

class Plugin implements PluginInterface, PluginSelectOptionsProviderInterface
{
    public function selectOptions(string $provider, PluginSelectOptionsContext $context): array
    {
        if ($provider === 'fixture_sources') {
            $country = $context->value('settings.country', 'us');

            return [
                'source_a' => "Source A ({$country})",
                'source_b' => "Source B ({$country})",
            ];
        }

        return [];
    }
}
```

`$context->value($key, $default)` returns the current row value for a field listed in `depends_on`. Use this to build context-aware option lists that change based on related column values in the same row.

## Field Types

Field types apply to both `settings` and `ui_tables[*].fields`.

- `boolean` — toggle
- `number` — numeric input
- `text` — single-line text input
- `textarea` — multi-line text input
- `tags` — free-entry tag list (split on Tab or Return)
- `select` — static option list
- `model_select` — Eloquent model-backed select
- `table_select` — plugin-table-backed select
- `section` — layout grouping; wraps child fields in a collapsible section, not persisted itself

Common field keys:

- `id` — field identifier (used as the form key and settings key)
- `label` — display label
- `type` — one of the types above
- `default` — default value
- `required` — validation rule
- `helper_text` — hint shown below the field
- `multiple` — allow multiple selections (`select`, `model_select`, `table_select`)
- `placeholder` — select placeholder text

### select

```json
{ "id": "mode", "type": "select", "options": { "a": "Option A", "b": "Option B" } }
```

### model_select

```json
{ "id": "playlist_id", "type": "model_select", "model": "App\\Models\\Playlist", "label_attribute": "name", "scope": "owned" }
```

Supported options:

- `model`: fully-qualified Eloquent model class
- `label_attribute`: attribute to display (default: `name`)
- `scope: "owned"`: filter to records owned by the authenticated user

### table_select

Selects from a plugin-declared table. The table must be declared in `schema.tables`.

```json
{ "id": "profile_id", "type": "table_select", "table": "plugin_sample_plugin_profiles", "value_column": "id", "label_column": "name", "enabled_only": true, "scope_plugin": true }
```

Supported options:

- `table`: plugin-owned table to select from
- `value_column`: stored value column (default: `id`)
- `label_column`: display label column (default: `name`)
- `enabled_only`: filter to rows where `enabled = true` (default: `true`)
- `scope_plugin`: filter to rows where `extension_plugin_id` matches this plugin (default: `false`)

### section

Groups child fields visually. The `id` is optional for sections. Children are declared in `fields`.

```json
{
  "type": "section",
  "label": "Advanced",
  "icon": "heroicon-m-cog-6-tooth",
  "collapsible": true,
  "collapsed": true,
  "compact": false,
  "columns": 2,
  "fields": [ ... ]
}
```

## Commands

- `php artisan plugins:discover`
- `php artisan plugins:validate`
- `php artisan plugins:validate <plugin-id>`
- `php artisan plugins:stage-directory <path>`
- `php artisan plugins:stage-archive <archive>`
- `php artisan plugins:stage-github-release <url> --sha256=<hash>`
- `php artisan plugins:scan-install <review-id>`
- `php artisan plugins:approve-install <review-id> [--trust]`
- `php artisan plugins:reject-install <review-id>`
- `php artisan plugins:discard-install <review-id>`
- `php artisan plugins:verify-integrity`
- `php artisan plugins:trust <plugin-id>`
- `php artisan plugins:block <plugin-id>`
- `php artisan make:plugin "Acme XML Tools"`
- `php artisan plugins:doctor`
- `php artisan plugins:uninstall <plugin-id> --cleanup=preserve`
- `php artisan plugins:reinstall <plugin-id>`
- `php artisan plugins:forget <plugin-id>`
- `php artisan plugins:run-scheduled`

## Host Operations

The host now exposes plugin lifecycle operations both in the UI and in Artisan.

- `plugins:doctor`: checks registry integrity, lifecycle state drift, and leftover plugin-owned resources after purge uninstall
- `plugins:uninstall`: marks the plugin uninstalled and optionally preserves or purges declared plugin-owned data
- `plugins:reinstall`: returns an uninstalled plugin to the installed state so it can be enabled again
- `plugins:forget`: removes only the registry row, saved settings, and run history

Operational rule:

- use `forget` only when you intentionally want discovery to recreate the plugin later from disk
- use `uninstall` when you want lifecycle state and cleanup semantics

## Admin Workflow

1. Run plugin discovery.
2. Open `Extensions -> Overview`.
3. Validate a plugin.
4. If needed, stage the current plugin files for review or use Plugin Installs.
5. Review permissions, schema, integrity, and ClamAV result.
6. Trust it.
7. Configure settings.
8. Enable it.
9. If the plugin declares `ui_tables`, open the **Data** tab on the plugin edit page to manage plugin-owned table records.
10. Run manual actions or let hooks/schedules invoke it.
11. If you remove the plugin later, choose whether uninstall should preserve or purge the declared plugin-owned data.

## Scaffold Workflow

1. Run `php artisan make:plugin "Your Plugin Name"`.
2. Edit the generated `plugin.json` capabilities, hooks, settings, and ownership declarations.
3. Replace the generated `health_check` behavior with the real plugin logic in `Plugin.php`.
4. Update the generated README, package script, and GitHub workflow to match your release process.
5. Stage it through reviewed install or use dev mode only while authoring from a configured dev directory.
6. Run discovery, validation, scan, and trust.
7. Open `Extensions -> Extensions` and test the scaffold from the UI.

## Quick Start For Reviewers

Create a plugin:

```bash
php artisan make:plugin "Acme XML Tools"
```

Package it:

```bash
cd plugins/acme-xml-tools
bash scripts/package-plugin.sh
```

Install it privately from a local zip:

```bash
php artisan plugins:stage-archive /absolute/path/to/acme-xml-tools.zip
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

Install it privately from the UI:

1. Open `Extensions -> Plugin Installs`.
2. Click `Upload Plugin Archive`.
3. Upload the packaged archive from `dist/`.
4. Run the review scan.
5. Install and trust it.

Install it from GitHub:

```bash
php artisan plugins:stage-github-release \
  https://github.com/<owner>/<repo>/releases/download/<tag>/acme-xml-tools.zip \
  --sha256=<published-sha256>
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

## Execution Model

- Manual actions are queued through `ExecutePluginInvocation`
- Hook invocations are queued through `PluginHookDispatcher`
- Runs are persisted in `extension_plugin_runs`
- Uninstalled plugins cannot execute until they are explicitly reinstalled
- Untrusted or integrity-changed plugins cannot execute until they are reviewed again
- Uninstall cleanup only touches plugin-owned data declared in the manifest

## Sample Plugin

Use `php artisan make:plugin "Acme XML Tools"` to generate a sample plugin repo skeleton.

The generated starter kit demonstrates:

- manifest-driven registration
- reviewed install packaging
- CI-ready release workflow
- optional AI helper files for plugin-author repos
- scheduled invocation
- dry-run versus apply behavior
