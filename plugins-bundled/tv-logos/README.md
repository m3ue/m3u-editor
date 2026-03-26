# TV Logos

`tv-logos` is a trusted-local plugin for `m3u-editor`.

Automatically enriches channel logos from the open-source [tv-logo/tv-logos](https://github.com/tv-logo/tv-logos) repository via the jsDelivr CDN. Logos are matched by normalising channel names into hyphenated slugs and probing the CDN for known filename patterns.

## Runtime files

- `plugin.json`
- `Plugin.php`

## Declared capabilities

- channel_processor

## Declared hooks

- playlist.synced

## Settings

| ID | Type | Default | Description |
|----|------|---------|-------------|
| `country_code` | text | `us` | ISO 3166-1 alpha-2 code (e.g. `us`, `gb`, `de`) |
| `overwrite_existing` | boolean | `false` | Overwrite channels that already have a custom logo |
| `skip_vod` | boolean | `true` | Skip VOD channels |
| `cache_ttl_days` | number | `7` | How long to cache CDN probe results (0 = never expire) |

## How it works

1. After each successful playlist sync (`playlist.synced` hook), the plugin queries enabled live channels with no custom logo (or all channels when `overwrite_existing` is on).
2. Each channel name is normalised: lowercased, quality tags stripped, brackets removed, `&` → `and`, non-alphanumeric removed, then hyphenated.
3. Three filename candidates are tried against the CDN in order:
   - `{slug}-{cc}.png` (e.g. `bbc-one-gb.png`)
   - `{slug}.png` (e.g. `bbc-one.png`)
   - `{shortened-slug}-{cc}.png` (last word removed, e.g. `bbc-gb.png`)
4. The first successful HEAD response wins. The resolved URL is written to `logo_custom` on the channel.
5. CDN probe results are cached in `plugin-data/tv-logos/matches.json` for `cache_ttl_days` days to avoid redundant network calls on subsequent syncs.

## Release workflow

1. Update `plugin.json` and `Plugin.php`.
2. Run `php scripts/validate-plugin.php`.
3. Run `bash scripts/package-plugin.sh`.
4. Publish the generated zip and its `.sha256` file as a GitHub release asset.
5. Install it into `m3u-editor` with reviewed install, scan, and trust.

## Private installs

Private plugins do not need GitHub. Operators can stage the local plugin directory directly:

```bash
php artisan plugins:stage-directory /absolute/path/to/tv-logos
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

For Docker deployments, "local" means a path the host/container can already read. It is not a browser-upload flow.

For private plugins in the UI, the recommended path is `Extensions -> Installs -> Upload Extension Archive`.

## GitHub release installs

For GitHub-distributed plugins, publish a release asset checksum and stage it with:

```bash
php artisan plugins:stage-github-release \
  https://github.com/<owner>/<repo>/releases/download/<tag>/tv-logos.zip \
  --sha256=<published-sha256>
```
