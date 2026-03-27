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
- `schema`: host-managed plugin-owned table declarations
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
- capability-specific interfaces in `app/Plugins/Contracts`

## Field Types

Supported schema field types:

- `boolean`
- `number`
- `text`
- `textarea`
- `select`
- `model_select`

`model_select` supports:

- `model`
- `label_attribute`
- `scope: "owned"`

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
9. Run manual actions or let hooks/schedules invoke it.
10. If you remove the plugin later, choose whether uninstall should preserve or purge the declared plugin-owned data.

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
