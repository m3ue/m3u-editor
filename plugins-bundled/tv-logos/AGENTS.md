# AGENTS.md

This repository builds the `tv-logos` plugin for `m3u-editor`.

## Guardrails

- Keep `plugin.json` and `Plugin.php` as the runtime source of truth.
- Do not add top-level executable code outside the plugin class.
- Keep runtime files reviewable and minimal.
- Do not widen manifest permissions without updating the README and release notes.
- Package only runtime files for release artifacts.

## Security

- GitHub CI is a quality signal, not a trust boundary.
- The host still performs reviewed install, ClamAV scanning, explicit trust, and integrity verification.
- Treat `network_egress`, `filesystem_write`, and `schema_manage` as high-risk permissions.
