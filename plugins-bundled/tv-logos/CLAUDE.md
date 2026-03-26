# CLAUDE.md

Work on `tv-logos` as a reviewable plugin artifact for `m3u-editor`.

## Expectations

- Keep the runtime surface centered on `plugin.json` and `Plugin.php`.
- Prefer small, explicit manifest changes over hidden behavior.
- Avoid top-level side effects in PHP files.
- Keep release artifacts reproducible with `bash scripts/package-plugin.sh`.
- Update the published checksum whenever the release zip changes.
