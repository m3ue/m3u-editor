# Asset upload and preview policy

The asset library's public URLs are used by streaming clients as logos/covers/media. They remain on the `public` disk under `/storage/assets/library/`; this change does not move, delete or rewrite existing files or references.

## New uploads

All asset upload forms validate the file's actual MIME type and its original filename extension on the server. Raster images also need readable image dimensions. Merely renaming a document to an image extension is not accepted. New files receive generated names; user-controlled original filenames are not preserved.

- **Library:** JPEG (`jpg`, `jpeg`), PNG, GIF, WebP, AVIF, BMP; MP3, Ogg (`ogg`, `oga`, `ogv`), WAV, M4A, AAC, FLAC; MP4 (`mp4`, `m4v`), WebM and QuickTime (`mov`). MIME/container aliases are explicitly mapped in `AssetFilePolicy`.
- **Logo/cover picker and placeholder settings:** the same raster formats only.
- **Maximum per new upload:** 12 MiB, matching Livewire's existing default temporary-upload limit. A smaller configured temporary-upload/proxy limit still applies.
- **Not accepted as new uploads:** SVG, HTML, scripts, archives, fonts or other documents. The remote logo cache's existing SVG sanitization remains unchanged; it is not a generic SVG upload sanitizer.

Existing settings can retain their current stored image path without a new upload. The upload widgets reject arbitrary replacement paths; only newly uploaded files or an already-authorized field value are accepted.

## Existing assets and preview responses

The inventory still includes pre-existing media and documents. Unsupported/legacy files are not deleted or hidden by a rescan. Existing public SVG/document URLs remain public: this policy does not retroactively sanitize or secure their separate static storage delivery. Review old public files separately if that is required.

The authenticated preview endpoint preserves the existing administrator / `use_tools` permission boundary and rechecks the actual stored bytes and extension on every request, rather than trusting inventory MIME metadata or an extension-based MIME fallback.

- Known raster image MIME/extension pairs are delivered inline.
- Audio/video, SVG, documents and unknown files are downloaded as `application/octet-stream` attachments, not embedded as active documents. The preview modal provides a download button for those files.
- Responses explicitly use private caching, `X-Content-Type-Options: nosniff` and a restrictive sandbox Content Security Policy.

Caddy/Nginx still need their normal safe static-file configuration. Controller headers do not govern the separate public `/storage/` URLs.

## Regression checks

```sh
php artisan test --compact tests/Unit/AssetFilePolicyTest.php tests/Unit/AssetInventoryServiceTest.php tests/Feature/AssetPreviewControllerTest.php tests/Feature/AssetUploadPolicyTest.php
```

The upload tests submit real PNG, generated WAV and generated MP4 files through the actual Filament/Livewire library action, channel-logo picker and all three placeholder fields. Preview tests cover guests, ordinary users, tools users and administrators, stale MIME metadata, private cache headers, safe downloads and missing files.
