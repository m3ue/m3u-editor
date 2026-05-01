# Trash Guide Naming PR Fixes

## TL;DR

> Fix 11 code review issues in the Trash Guide naming PR, add explicit opt-in toggle, remove release_group dependency, revert Series relationship regression, and extend stream probing to VOD/Series with opt-in strategy.
>
> **Deliverables**:
> - Safe migration strategy (nullable formats, boolean toggle, dropped release_group columns)
> - Reverted Series::episodes() to hasMany(Episode::class)
> - Unified StreamStatsService for shared normalization logic
> - Fixed VodFileNameService `{-group}` support and quality thresholds
> - Fixed SerieFileNameService helper text and quality thresholds
> - Fixed StrmPathBuilder double filename generation
> - Safe regex execution in VersionDetectionService
> - Restored legacy title-folder creation in SyncVodStrmFiles
> - Extended probing to VOD channels and episodes (manual + auto-sync opt-in)
> - Filament UI toggle for trash guide naming
>
> **Estimated Effort**: Large
> **Parallel Execution**: YES - 4 waves
> **Critical Path**: T1 (Migrations) → T2 (Models) → T3 (StreamStatsService) → T4-T7 (Services) → T8-T10 (Jobs/Probing/UI) → T11 (Tests) → F1-F4 (Verification)

---

## Context

### Original Request
Fix issues identified in the Trash Guide naming PR code review, plus extend probing to VOD/Series and make features fully modular/opt-in.

### Interview Summary
**Key Discussions**:
- Opt-out strategy: Explicit `trash_guide_naming_enabled` boolean toggle in StreamFileSetting + Filament UI
- `stream_stats` stays (populated by probing), `release_group` removed completely (no data = no feature)
- `Series::episodes()` reverted to `hasMany(Episode::class)` to avoid regression for episodes without season
- Probing: Manual trigger OR playlist-settings toggle (like Live TV). Unprobed channels auto-probed during sync.

**Research Findings**:
- `movie_format` has non-null default in migration → forces trash guide on ALL existing settings
- VodFileNameService only maps `{group}`, not `{-group}` (optional placeholder)
- SyncVodStrmFiles legacy branch missing title sub-folder creation
- All 3 migrations share identical timestamp `2026_04_30_092713`
- Episode has `stream_stats` field but NEVER populated by any code path
- Quality thresholds differ: VOD >=1000/>=700 vs Serie >=1080/>=720
- Duplicate stream stats normalization in both filename services

### Metis Review
**Identified Gaps** (self-reviewed):
- Migration strategy must handle existing data safely (default values, column drops)
- Need to check all call-sites of `release_group` before dropping columns
- Probing integration needs clear trigger points (sync job, manual action)
- Filament resource for StreamFileSetting needs UI update

---

## Work Objectives

### Core Objective
Make Trash Guide naming fully opt-in, fix all critical/high/medium PR issues, unify shared logic, extend probing to VOD/Series, and ensure zero regression.

### Concrete Deliverables
- Migration: `trash_guide_naming_enabled` boolean on `stream_file_settings`
- Migration: Make `movie_format` and `episode_format` nullable, remove defaults
- Migration: Drop `release_group` from `channels` and `episodes` tables
- Migration: Fix duplicate timestamps on existing migrations
- Model updates: StreamFileSetting, Channel, Episode, Series
- New StreamStatsService with unified normalization
- Updated VodFileNameService with `{-group}` support and unified thresholds
- Updated SerieFileNameService with unified thresholds and fixed helper text
- Fixed StrmPathBuilder double call
- Safe regex in VersionDetectionService
- Restored legacy title-folder in SyncVodStrmFiles
- Probing integration for VOD channels and episodes
- Filament UI toggle for trash guide naming
- Updated/expanded tests

### Definition of Done
- [ ] All critical/high/medium issues from PR review are resolved
- [ ] Trash Guide naming is explicitly opt-in (toggle defaults to false)
- [ ] Existing users are NOT forced into trash guide naming after migration
- [ ] All tests pass (`php artisan test --compact`)
- [ ] No regression in Series episode listing
- [ ] Probing works for VOD channels and episodes when enabled

### Must Have
- [ ] Explicit opt-in toggle (defaults to false)
- [ ] Safe migrations (no data loss, no forced features)
- [ ] `{-group}` support in VodFileNameService
- [ ] Legacy title-folder restoration
- [ ] Safe regex execution
- [ ] Reverted Series::episodes() relationship
- [ ] Unified stream stats normalization
- [ ] Consistent quality thresholds

### Must NOT Have (Guardrails)
- [ ] NO forced trash guide naming on existing settings
- [ ] NO breaking changes to episode listing behavior
- [ ] NO `release_group` fields or logic (provider data not guaranteed)
- [ ] NO always-on probing (performance impact)
- [ ] NO duplicate migration timestamps
- [ ] NO unsafe user-supplied regex execution

---

## Verification Strategy

### Test Decision
- **Infrastructure exists**: YES (Pest PHP tests exist for VodFileNameService, SerieFileNameService, StrmPathBuilder)
- **Automated tests**: Tests-after (update existing tests + add new ones for new features)
- **Framework**: Pest PHP
- **Agent-Executed QA**: MANDATORY for all tasks

### QA Policy
Every task MUST include agent-executed QA scenarios.
Evidence saved to `.sisyphus/evidence/task-{N}-{scenario-slug}.{ext}`.
- **Backend/Models**: Use Bash (`php artisan tinker --execute`) - Verify model attributes, relationships
- **API/Backend**: Use Bash (curl or artisan commands) - Verify behavior
- **Library/Module**: Use Bash (`php artisan test --compact --filter=...`) - Run tests
- **Migrations**: Use Bash (`php artisan migrate:status`, `php artisan migrate:fresh --seed`) - Verify schema

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Foundation - Schema + Models + Shared Service):
├── T1:  Migrations (toggle, nullable formats, drop release_group, fix timestamps)
├── T2:  Model updates (StreamFileSetting, Channel, Episode, Series)
├── T3:  Create StreamStatsService (unified normalization)
└── T4:  Update StreamFileSetting Filament Resource (UI toggle)

Wave 2 (Core Services - MAX PARALLEL):
├── T5:  Fix VodFileNameService ({-group}, thresholds, use StreamStatsService)
├── T6:  Fix SerieFileNameService (thresholds, helper text, use StreamStatsService)
├── T7:  Fix StrmPathBuilder (double generateMovieFileName call)
├── T8:  Fix VersionDetectionService (safe regex with try/catch)
└── T9:  Revert Series::episodes() to hasMany(Episode::class)

Wave 3 (Jobs + Probing Integration):
├── T10: Fix SyncVodStrmFiles (toggle check, legacy title-folder, probing)
├── T11: Extend probing to VOD channels and episodes
└── T12: Add playlist auto-probe toggle and sync integration

Wave 4 (Tests + Verification):
├── T13: Update VodFileNameService tests
├── T14: Update SerieFileNameService tests
├── T15: Update StrmPathBuilder tests
├── T16: Add StreamStatsService tests
└── T17: Add integration tests for probing + toggle behavior

Wave FINAL (After ALL tasks - 4 parallel reviews):
├── F1: Plan compliance audit (oracle)
├── F2: Code quality review (unspecified-high)
├── F3: Real manual QA (unspecified-high)
└── F4: Scope fidelity check (deep)
-> Present results -> Get explicit user okay

Critical Path: T1 → T2 → T3 → T5/T6 → T10 → T11 → T13-T17 → F1-F4
Parallel Speedup: ~60% faster than sequential
Max Concurrent: 5 (Wave 2)
```

---

## TODOs

- [ ] T1. **Migrations: Toggle, Nullable Formats, Drop release_group, Fix Timestamps**

  **What to do**:
  - Create migration to add `trash_guide_naming_enabled` (boolean, default: false) to `stream_file_settings`
  - Create migration to change `movie_format` and `episode_format` from `string` with default to `nullable string` (remove default) on `stream_file_settings`
  - Create migration to drop `release_group` column from `channels` table
  - Create migration to drop `release_group` column from `episodes` table
  - Rename existing migrations with duplicate timestamp `2026_04_30_092713` to unique timestamps (check current migration order and adjust)
  - Update `stream_file_settings` table: set `trash_guide_naming_enabled = false` for all existing rows
  - Update `stream_file_settings` table: set `movie_format = null` and `episode_format = null` for all existing rows where they hold the default value

  **Must NOT do**:
  - Do NOT drop any data other than `release_group` columns
  - Do NOT change behavior of existing non-default formats without user opt-in

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `laravel-best-practices`
    - Needed for migration conventions and safe schema changes

  **Parallelization**:
  - **Can Run In Parallel**: NO (must be sequential within T1)
  - **Parallel Group**: Wave 1 (with T2, T3, T4)
  - **Blocks**: T2, T4, T10
  - **Blocked By**: None

  **References**:
  - `database/migrations/2026_04_30_092713_add_trash_guide_fields_to_channels_table.php`
  - `database/migrations/2026_04_30_092713_add_trash_guide_fields_to_stream_file_settings_table.php`
  - `database/migrations/2026_04_30_092713_update_episodes_for_trash_guide_naming.php`
  - Laravel docs: Changing columns requires `doctrine/dbal` or Laravel 11+ built-in schema changes

  **Acceptance Criteria**:
  - [ ] `php artisan migrate:status` shows all migrations with unique timestamps
  - [ ] `php artisan migrate:fresh --seed` completes without errors
  - [ ] `SELECT trash_guide_naming_enabled FROM stream_file_settings` returns 0/false for all rows
  - [ ] `SELECT movie_format, episode_format FROM stream_file_settings` returns NULL for all pre-existing rows
  - [ ] `DESCRIBE channels` does NOT show `release_group` column
  - [ ] `DESCRIBE episodes` does NOT show `release_group` column

  **QA Scenarios**:
  ```
  Scenario: Fresh migration succeeds
    Tool: Bash
    Steps:
      1. php artisan migrate:fresh --seed
      2. php artisan db:seed (if needed)
    Expected Result: Exit code 0, no errors
    Evidence: .sisyphus/evidence/t1-migrate-fresh.log

  Scenario: Existing data safe after migration
    Tool: Bash (php artisan tinker)
    Preconditions: Create a StreamFileSetting with custom movie_format before running migration
    Steps:
      1. Run migrations
      2. Check setting still exists and trash_guide_naming_enabled is false
    Expected Result: Setting preserved, toggle false, format still set to custom value
    Evidence: .sisyphus/evidence/t1-existing-data-safe.log
  ```

  **Commit**: YES
  - Message: `fix(migrations): add trash guide toggle, nullable formats, drop release_group, fix timestamps`

- [ ] T2. **Model Updates: StreamFileSetting, Channel, Episode, Series**

  **What to do**:
  - `StreamFileSetting` model: Add `trash_guide_naming_enabled` to `$fillable` (or casts). Make `movie_format` and `episode_format` nullable in casts if needed.
  - `Channel` model: Remove `release_group` from `$fillable` / casts. Remove any `release_group` getter or logic.
  - `Episode` model: Remove `release_group` from `$fillable` / casts. Remove `release_group` getter that falls back to `info->release_group`. Keep `stream_stats` (array cast).
  - `Series` model: Revert `episodes()` relationship from `hasManyThrough(Episode::class, Season::class)` back to `hasMany(Episode::class)`
  - Check ALL models for any `release_group` references and remove them
  - Check ALL models for any `edition` or `year` on Channel - keep them (they're populated by VersionDetectionService)

  **Must NOT do**:
  - Do NOT change `enabled_episodes()` on Series
  - Do NOT remove `stream_stats`, `edition`, `year` from Channel
  - Do NOT remove `stream_stats` from Episode

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `laravel-best-practices`
    - Needed for model conventions and safe relationship changes

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T3, T4, but not with T1 since models depend on schema)
  - **Parallel Group**: Wave 1
  - **Blocks**: T5, T6, T9, T10, T11
  - **Blocked By**: T1

  **References**:
  - `app/Models/StreamFileSetting.php`
  - `app/Models/Channel.php:release_group, edition, year, stream_stats`
  - `app/Models/Episode.php:release_group, stream_stats`
  - `app/Models/Series.php:episodes(), enabled_episodes()`

  **Acceptance Criteria**:
  - [ ] `StreamFileSetting::first()->trash_guide_naming_enabled` returns false by default
  - [ ] `Channel` model has no `release_group` attribute access
  - [ ] `Episode` model has no `release_group` attribute access
  - [ ] `Series::episodes()` returns Builder for hasMany relationship
  - [ ] `Series::episodes()->getQuery()->toSql()` contains `series_id` not `season_id` join

  **QA Scenarios**:
  ```
  Scenario: Series episodes relationship restored
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $series = \App\Models\Series::first();
      2. $series->episodes()->toRawSql();
    Expected Result: SQL contains `where "episodes"."series_id" = ?` (hasMany), NOT hasManyThrough with seasons
    Evidence: .sisyphus/evidence/t2-series-relationship.log

  Scenario: No release_group on models
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $channel = \App\Models\Channel::first();
      2. isset($channel->release_group);
    Expected Result: false
    Evidence: .sisyphus/evidence/t2-no-release-group.log
  ```

  **Commit**: YES
  - Message: `fix(models): add toggle, remove release_group, revert series relationship`

- [ ] T3. **Create StreamStatsService (Unified Normalization)**

  **What to do**:
  - Create `app/Services/StreamStatsService.php`
  - Extract and unify the stream stats normalization logic from both `VodFileNameService` and `SerieFileNameService`
  - Service should accept raw ffprobe/stream_stats array and return normalized structure with:
    - `video`: array with codec, width, height, quality (1080p/720p/SD), hdr (bool)
    - `audio`: array with codec, channels, language
    - `flat`: flat array for backward compatibility (if needed)
  - Use consistent quality thresholds: >=1080 width = 1080p, >=720 width = 720p, else SD
  - Support common codecs: H.264/AVC, H.265/HEVC, AV1, VP9, AAC, AC3, EAC3, DTS
  - Support HDR detection from color transfer (smpte2084, arib-std-b67) or color primaries
  - Make it stateless / pure function where possible
  - Add proper type hints and PHPDoc

  **Must NOT do**:
  - Do NOT change the return structure of existing services yet (do that in T5/T6)
  - Do NOT introduce new dependencies

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `php-best-practices`
    - Needed for clean service design and type safety

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T2, T4)
  - **Parallel Group**: Wave 1
  - **Blocks**: T5, T6
  - **Blocked By**: None (new file, no dependencies)

  **References**:
  - `app/Services/VodFileNameService.php:normalizeStreamStats()`
  - `app/Services/SerieFileNameService.php:normaliseStreamStats()`
  - `app/Models/Channel.php:stream_stats` structure (populated by ffprobe)
  - `app/Models/Episode.php:stream_stats` structure (same format)

  **Acceptance Criteria**:
  - [ ] Service file created at `app/Services/StreamStatsService.php`
  - [ ] `normalize(array $stats): array` method exists and returns consistent structure
  - [ ] Quality detection: width 1920 → 1080p, 1280 → 720p, 640 → SD
  - [ ] HDR detection works for smpte2084 and arib-std-b67 color transfers
  - [ ] Service is dependency-injectable (no constructor required, or empty constructor)

  **QA Scenarios**:
  ```
  Scenario: Normalize 1080p HEVC HDR stream
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\StreamStatsService();
      2. $result = $service->normalize(['video' => ['width' => 1920, 'codec' => 'hevc', 'color_transfer' => 'smpte2084'], 'audio' => ['codec' => 'aac']]);
    Expected Result: $result['video']['quality'] === '1080p', $result['video']['hdr'] === true, $result['video']['codec'] === 'HEVC'
    Evidence: .sisyphus/evidence/t3-normalize-hevc.log

  Scenario: Normalize 720p AVC non-HDR stream
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\StreamStatsService();
      2. $result = $service->normalize(['video' => ['width' => 1280, 'codec' => 'h264'], 'audio' => ['codec' => 'ac3']]);
    Expected Result: $result['video']['quality'] === '720p', $result['video']['hdr'] === false
    Evidence: .sisyphus/evidence/t3-normalize-avc.log
  ```

  **Commit**: YES
  - Message: `feat(services): add unified StreamStatsService`

- [ ] T4. **Update StreamFileSetting Filament Resource (UI Toggle)**

  **What to do**:
  - Find the Filament resource for `StreamFileSetting` (likely `app/Filament/Resources/StreamFileSettingResource.php` or similar)
  - Add a `Toggle` form component for `trash_guide_naming_enabled` BEFORE the format fields
  - Make `movie_format` and `episode_format` fields conditionally visible or clearly indicate they only apply when toggle is on
  - Update helper text for format fields to correctly document available placeholders:
    - Vod: `{title}`, `{year}`, `{edition}`, `{quality}`, `{hdr}`, `{video_codec}`, `{audio_codec}`, `{group}`, `{-group}` (optional)
    - Serie: `{serie}`, `{season}`, `{episode}`, `{title}`, `{quality}`, `{hdr}`, `{video_codec}`, `{audio_codec}`, `{group}`, `{-group}` (optional)
  - Fix `{-ep_title}` to `{-title}` in Serie helper text
  - Ensure the form follows existing Filament patterns in the app (use `make()`, livewire, etc.)

  **Must NOT do**:
  - Do NOT add release_group related fields
  - Do NOT change unrelated form fields

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: `laravel-best-practices`, `tailwindcss-development`
    - Needed for Filament form components and Tailwind styling

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T2, T3)
  - **Parallel Group**: Wave 1
  - **Blocks**: T10 (indirectly, UI should match backend behavior)
  - **Blocked By**: T1 (schema must exist first)

  **References**:
  - `app/Filament/Resources/StreamFileSettingResource.php` (find exact file)
  - `app/Models/StreamFileSetting.php` (for fillable/casts)
  - Existing Filament resources for Toggle pattern (search app/Filament/Resources for `Toggle::make`)

  **Acceptance Criteria**:
  - [ ] Filament form shows `Trash Guide Naming Enabled` toggle
  - [ ] Toggle defaults to OFF for new records
  - [ ] Format fields show correct placeholder documentation (no `{-ep_title}`)
  - [ ] `movie_format` and `episode_format` are present and editable

  **QA Scenarios**:
  ```
  Scenario: Filament form renders correctly
    Tool: Bash (curl to Filament panel or Playwright if available)
    Steps:
      1. Navigate to StreamFileSetting create/edit page in Filament
      2. Verify toggle is present and unchecked by default
      3. Verify format fields show correct helper text
    Expected Result: Toggle visible, helper text contains {-title} not {-ep_title}
    Evidence: .sisyphus/evidence/t4-filament-form.png
  ```

  **Commit**: YES
  - Message: `feat(filament): add trash guide naming toggle and fix helper text`

- [ ] T5. **Fix VodFileNameService ({-group}, Thresholds, StreamStatsService)**

  **What to do**:
  - Add support for `{-group}` optional placeholder (like `{-title}` and `{-group}` in SerieFileNameService)
  - Replace internal `normalizeStreamStats()` with `StreamStatsService::normalize()`
  - Update quality thresholds to match unified standard: >=1080 width = 1080p, >=720 width = 720p
  - Ensure `{group}` still works (non-optional) alongside new `{-group}` (optional - only includes if value present)
  - Update any internal references to removed `release_group` field (use release group from stream_stats or remove entirely)
  - Keep backward compatibility with existing format strings

  **Must NOT do**:
  - Do NOT break existing `{group}` placeholder behavior
  - Do NOT change the public API signature without updating callers

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `php-best-practices`
    - Needed for safe refactoring of existing service

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T6, T7, T8, T9)
  - **Parallel Group**: Wave 2
  - **Blocks**: T7 (StrmPathBuilder uses this service)
  - **Blocked By**: T2 (models updated), T3 (StreamStatsService created)

  **References**:
  - `app/Services/VodFileNameService.php`
  - `app/Services/StreamStatsService.php` (from T3)
  - `app/Services/SerieFileNameService.php` (pattern for {-group} implementation)

  **Acceptance Criteria**:
  - [ ] `generateMovieFileName()` supports `{-group}` placeholder
  - [ ] `{-group}` omits group prefix when no group value present
  - [ ] `{group}` still works as before
  - [ ] Quality thresholds: 1920→1080p, 1280→720p, 640→SD
  - [ ] Uses StreamStatsService for normalization

  **QA Scenarios**:
  ```
  Scenario: Optional group placeholder works
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\VodFileNameService();
      2. $service->generateMovieFileName(['title' => 'Test', 'group' => 'SPARKS', 'format' => '{title} {-group}']);
    Expected Result: "Test [SPARKS]" (or similar format based on actual implementation)
    Evidence: .sisyphus/evidence/t5-optional-group.log

  Scenario: Optional group omitted when empty
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\VodFileNameService();
      2. $service->generateMovieFileName(['title' => 'Test', 'group' => null, 'format' => '{title} {-group}']);
    Expected Result: "Test" (no trailing space or group)
    Evidence: .sisyphus/evidence/t5-empty-group.log

  Scenario: Quality thresholds consistent
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\VodFileNameService();
      2. $result = $service->generateMovieFileName(['title' => 'Test', 'stream_stats' => ['video' => ['width' => 1280]]]);
    Expected Result: Contains "720p" (not old >=700 threshold behavior)
    Evidence: .sisyphus/evidence/t5-quality-thresholds.log
  ```

  **Commit**: YES
  - Message: `fix(vod): add {-group} support, unify thresholds, use StreamStatsService`

- [ ] T6. **Fix SerieFileNameService (Thresholds, Helper Text, StreamStatsService)**

  **What to do**:
  - Replace internal `normaliseStreamStats()` with `StreamStatsService::normalize()`
  - Update quality thresholds to unified standard: >=1080 width = 1080p, >=720 width = 720p (if different)
  - Verify `{-title}` and `{-group}` already work correctly (they should per review)
  - Remove any `release_group` references (use group from stream_stats or parameter)
  - Ensure `stream_stats` handling matches new unified format from StreamStatsService

  **Must NOT do**:
  - Do NOT change `{-title}` or `{-group}` behavior (they already work)
  - Do NOT change public API without updating callers

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `php-best-practices`
    - Needed for safe refactoring of existing service

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T5, T7, T8, T9)
  - **Parallel Group**: Wave 2
  - **Blocks**: None (no direct dependents)
  - **Blocked By**: T2 (models updated), T3 (StreamStatsService created)

  **References**:
  - `app/Services/SerieFileNameService.php`
  - `app/Services/StreamStatsService.php` (from T3)

  **Acceptance Criteria**:
  - [ ] Uses StreamStatsService for normalization
  - [ ] Quality thresholds match unified standard
  - [ ] No `release_group` field references
  - [ ] `stream_stats` structure compatible with unified format

  **QA Scenarios**:
  ```
  Scenario: Episode filename with stream stats
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\SerieFileNameService();
      2. $result = $service->generateEpisodeFileName([...]);
    Expected Result: Correct quality, hdr, codec info from StreamStatsService
    Evidence: .sisyphus/evidence/t6-episode-filename.log
  ```

  **Commit**: YES
  - Message: `fix(serie): unify thresholds, use StreamStatsService`

- [ ] T7. **Fix StrmPathBuilder (Double generateMovieFileName Call)**

  **What to do**:
  - Open `app/Services/StrmPathBuilder.php`
  - In `buildVodPath()`, lines 39 and 44 both call `generateMovieFileName()`
  - Refactor to call it ONCE, store result in variable, reuse for both path construction and filename
  - Verify no other methods have similar double-call patterns
  - Ensure the generated filename is identical in both usages

  **Must NOT do**:
  - Do NOT change the path building logic itself
  - Do NOT change method signatures

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `php-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T5, T6, T8, T9)
  - **Parallel Group**: Wave 2
  - **Blocks**: None
  - **Blocked By**: T5 (VodFileNameService must be stable, though signature won't change)

  **References**:
  - `app/Services/StrmPathBuilder.php:buildVodPath()` (lines 39, 44)

  **Acceptance Criteria**:
  - [ ] `generateMovieFileName()` called exactly once per `buildVodPath()` invocation
  - [ ] No behavioral change in output paths

  **QA Scenarios**:
  ```
  Scenario: Path builder generates correct path
    Tool: Bash (php artisan test --compact --filter=StrmPathBuilderTest)
    Steps:
      1. Run StrmPathBuilder tests
    Expected Result: All tests pass
    Evidence: .sisyphus/evidence/t7-pathbuilder-tests.log
  ```

  **Commit**: YES
  - Message: `fix(strm): eliminate double filename generation in buildVodPath`

- [ ] T8. **Fix VersionDetectionService (Safe Regex Execution)**

  **What to do**:
  - Open `app/Services/VersionDetectionService.php`
  - Find `detectEditionWithPattern()` method
  - Wrap the `preg_match()` call in try/catch (or validate regex first with `@preg_match(...)`)
  - On invalid regex, log warning and return null (or empty string) instead of crashing
  - Consider adding regex validation before execution (e.g., `@preg_match($pattern, '') !== false`)
  - Ensure the method still works correctly for valid patterns

  **Must NOT do**:
  - Do NOT change the method signature
  - Do NOT suppress all errors indiscriminately

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `php-best-practices`
    - Needed for safe error handling patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T5, T6, T7, T9)
  - **Parallel Group**: Wave 2
  - **Blocks**: None
  - **Blocked By**: None

  **References**:
  - `app/Services/VersionDetectionService.php:detectEditionWithPattern()`

  **Acceptance Criteria**:
  - [ ] Invalid regex does NOT throw uncaught exception
  - [ ] Valid regex still works correctly
  - [ ] Method returns null/empty for invalid regex

  **QA Scenarios**:
  ```
  Scenario: Invalid regex handled gracefully
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\VersionDetectionService();
      2. $service->detectEditionWithPattern('Test Movie', '[invalid(');
    Expected Result: No exception thrown, returns null or empty string
    Evidence: .sisyphus/evidence/t8-invalid-regex.log

  Scenario: Valid regex still works
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $service = new \App\Services\VersionDetectionService();
      2. $service->detectEditionWithPattern('Test Movie (Extended)', '/\(([^)]+)\)/');
    Expected Result: Returns "Extended"
    Evidence: .sisyphus/evidence/t8-valid-regex.log
  ```

  **Commit**: YES
  - Message: `fix(versions): add safe regex execution with error handling`

- [ ] T9. **Revert Series::episodes() to hasMany(Episode::class)**

  **What to do**:
  - Open `app/Models/Series.php`
  - Change `episodes()` method from `hasManyThrough(Episode::class, Season::class)` back to `hasMany(Episode::class)`
  - Verify `enabled_episodes()` is NOT changed (it should remain `hasMany(Episode::class)` or whatever it currently is)
  - Check if any code depends on the hasManyThrough behavior (eager loading `seasons` through episodes) and document if found
  - Run tests that exercise Series relationships

  **Must NOT do**:
  - Do NOT change `enabled_episodes()`
  - Do NOT introduce new relationship methods without need

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T5, T6, T7, T8)
  - **Parallel Group**: Wave 2
  - **Blocks**: None
  - **Blocked By**: T2 (model analysis complete)

  **References**:
  - `app/Models/Series.php:episodes(), enabled_episodes()`

  **Acceptance Criteria**:
  - [ ] `Series::episodes()` returns `HasMany` relationship
  - [ ] Episodes without season still accessible via `$series->episodes`
  - [ ] No test failures in Series-related tests

  **QA Scenarios**:
  ```
  Scenario: Episodes without season accessible
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $series = \App\Models\Series::first();
      2. $count = $series->episodes()->count();
    Expected Result: Count includes all episodes with matching series_id, regardless of season
    Evidence: .sisyphus/evidence/t9-episodes-accessible.log
  ```

  **Commit**: YES
  - Message: `fix(models): revert Series::episodes() to hasMany to avoid regression`

- [ ] T10. **Fix SyncVodStrmFiles (Toggle Check, Legacy Title-Folder, Probing)**

  **What to do**:
  - Open `app/Jobs/SyncVodStrmFiles.php`
  - Change trash guide detection from `filled($streamFileSetting?->movie_format ?? null)` to check `$streamFileSetting?->trash_guide_naming_enabled === true`
  - In the legacy branch (when trash guide is NOT enabled), restore the title sub-folder creation that was removed
  - Find the old code for title-folder creation (check git history before the PR) and restore it in the `else` branch
  - Integrate probing logic: before generating filename, if trash guide is enabled AND channel has no stream_stats, trigger probing
  - Ensure the job handles missing stream_stats gracefully (falls back to basic naming)

  **Must NOT do**:
  - Do NOT change the overall job flow dramatically
  - Do NOT force probing on all channels (only when toggle is on and stats missing)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`
    - Needed for safe job refactoring and queue handling

  **Parallelization**:
  - **Can Run In Parallel**: NO (depends on T2 for model changes)
  - **Parallel Group**: Wave 3
  - **Blocks**: None
  - **Blocked By**: T1 (migrations), T2 (models), T3 (StreamStatsService), T5 (VodFileNameService)

  **References**:
  - `app/Jobs/SyncVodStrmFiles.php` (lines with trash guide detection and legacy branch)
  - Git history for title-folder creation code (before PR)
  - `app/Models/Channel.php:probeStreamStats(), ensureStreamStats()`

  **Acceptance Criteria**:
  - [ ] Trash guide detection uses `trash_guide_naming_enabled` boolean
  - [ ] Legacy branch creates title sub-folders correctly
  - [ ] Job does NOT crash when stream_stats are missing
  - [ ] Probing triggered for unprobed channels when toggle is on

  **QA Scenarios**:
  ```
  Scenario: Legacy branch creates title folder
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. Dispatch SyncVodStrmFiles with trash_guide_naming_enabled = false
      2. Check filesystem for title sub-folders
    Expected Result: Title folders exist under appropriate paths
    Evidence: .sisyphus/evidence/t10-legacy-folder.log

  Scenario: Toggle off uses legacy behavior
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. Set stream_file_setting.trash_guide_naming_enabled = false
      2. Run sync job
    Expected Result: Uses legacy naming (not trash guide format)
    Evidence: .sisyphus/evidence/t10-toggle-off.log
  ```

  **Commit**: YES
  - Message: `fix(sync): use toggle for trash guide, restore legacy title folders, add probing`

- [ ] T11. **Extend Probing to VOD Channels and Episodes**

  **What to do**:
  - Create `app/Traits/ProbesStreamStats.php` or extend existing probing logic
  - Add `probeStreamStats()` and `ensureStreamStats()` methods to `Channel` model for VOD context (if not already present, they are but verify)
  - Add `probeStreamStats()` and `ensureStreamStats()` methods to `Episode` model
  - Episode probing should work similarly to Channel: accept URL, run ffprobe via Shell, parse JSON, store in `stream_stats` JSON column
  - Make probing conditional: only probe if the model has a stream URL and doesn't already have recent stats
  - Add `last_probed_at` timestamp? (Check if this exists or if we need it - if not, skip to keep it simple)
  - Ensure probing doesn't block the main thread (use queue or timeout)

  **Must NOT do**:
  - Do NOT probe every time a model is accessed
  - Do NOT add `release_group` extraction from probing (feature removed)
  - Do NOT change Live TV probing behavior

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`
    - Needed for model traits and shell command execution

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T10, T12)
  - **Parallel Group**: Wave 3
  - **Blocks**: T12 (probing toggle depends on probing working)
  - **Blocked By**: T2 (models updated)

  **References**:
  - `app/Models/Channel.php:probeStreamStats(), ensureStreamStats()`
  - `app/Models/Episode.php` (no probing currently)
  - `app/Models/Episode.php:stream_stats` cast

  **Acceptance Criteria**:
  - [ ] `Episode::probeStreamStats($url)` exists and returns parsed ffprobe array
  - [ ] `Episode::ensureStreamStats()` exists and populates `stream_stats` column
  - [ ] Channel probing behavior unchanged for Live TV
  - [ ] Probing handles ffprobe failures gracefully (empty array, no crash)

  **QA Scenarios**:
  ```
  Scenario: Episode probing works
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $episode = \App\Models\Episode::first();
      2. $episode->probeStreamStats();
    Expected Result: Returns array with video/audio info, or empty array if ffprobe fails
    Evidence: .sisyphus/evidence/t11-episode-probe.log

  Scenario: Episode ensureStreamStats persists
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. $episode = \App\Models\Episode::first();
      2. $episode->ensureStreamStats();
      3. $episode->fresh()->stream_stats;
    Expected Result: stream_stats is populated (non-null) after probing
    Evidence: .sisyphus/evidence/t11-episode-persist.log
  ```

  **Commit**: YES
  - Message: `feat(models): extend stream probing to VOD channels and episodes`

- [ ] T12. **Add Playlist Auto-Probe Toggle and Sync Integration**

  **What to do**:
  - Find the Playlist model and its Filament resource (where Live TV channel probing toggle exists)
  - Add a similar `auto_probe_vod` or `auto_probe_streams` boolean toggle to Playlist model + migration + Filament form
  - In `SyncVodStrmFiles`, check the playlist's auto-probe toggle before probing during sync
  - If toggle is ON and channel/episode has no stream_stats, call `ensureStreamStats()` before generating filenames
  - Ensure the toggle defaults to OFF (opt-in)
  - Document the behavior in the Filament helper text

  **Must NOT do**:
  - Do NOT auto-probe without explicit toggle
  - Do NOT change Live TV auto-probe behavior

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`
    - Needed for model/migration/Filament integration

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T10, T11)
  - **Parallel Group**: Wave 3
  - **Blocks**: None
  - **Blocked By**: T11 (probing must work first)

  **References**:
  - Playlist model and resource (find in `app/Models/Playlist.php`, `app/Filament/Resources/PlaylistResource.php`)
  - `app/Jobs/SyncVodStrmFiles.php` (integration point)
  - Existing auto-probe pattern for Live TV channels

  **Acceptance Criteria**:
  - [ ] Playlist model has `auto_probe_vod` boolean (default: false)
  - [ ] Filament form shows toggle for auto-probing VOD/Series
  - [ ] Sync job respects toggle (only probes when ON)
  - [ ] No probing occurs when toggle is OFF

  **QA Scenarios**:
  ```
  Scenario: Auto-probe toggle off
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. Set playlist.auto_probe_vod = false
      2. Run sync for channel without stream_stats
    Expected Result: stream_stats remains null, basic naming used
    Evidence: .sisyphus/evidence/t12-toggle-off.log

  Scenario: Auto-probe toggle on
    Tool: Bash (php artisan tinker --execute)
    Steps:
      1. Set playlist.auto_probe_vod = true
      2. Run sync for channel without stream_stats
    Expected Result: stream_stats populated after sync, trash guide naming used
    Evidence: .sisyphus/evidence/t12-toggle-on.log
  ```

  **Commit**: YES
  - Message: `feat(playlist): add auto-probe toggle for VOD/Series streams`

- [ ] T13. **Update VodFileNameService Tests**

  **What to do**:
  - Open `tests/Unit/Services/VodFileNameServiceTest.php` (or Feature test)
  - Update/add tests for `{-group}` optional placeholder (both present and absent cases)
  - Update/add tests for unified quality thresholds (1080p, 720p, SD boundaries)
  - Update/add tests for StreamStatsService integration
  - Remove any `release_group` related tests
  - Ensure all existing tests still pass with changes from T5

  **Must NOT do**:
  - Do NOT delete existing test coverage for unchanged features

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `pest-testing`
    - Needed for Pest test syntax and patterns

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T14, T15, T16, T17)
  - **Parallel Group**: Wave 4
  - **Blocks**: None
  - **Blocked By**: T3, T5

  **References**:
  - `tests/Unit/Services/VodFileNameServiceTest.php` (find exact path)
  - `app/Services/VodFileNameService.php` (from T5)

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact --filter=VodFileNameService` passes
  - [ ] Tests cover `{-group}` present and absent
  - [ ] Tests cover quality thresholds at boundaries

  **QA Scenarios**:
  ```
  Scenario: VodFileNameService tests pass
    Tool: Bash
    Steps:
      1. php artisan test --compact --filter=VodFileNameService
    Expected Result: All tests pass, 0 failures
    Evidence: .sisyphus/evidence/t13-vod-tests.log
  ```

  **Commit**: YES
  - Message: `test(vod): update tests for {-group}, thresholds, StreamStatsService`

- [ ] T14. **Update SerieFileNameService Tests**

  **What to do**:
  - Open `tests/Unit/Services/SerieFileNameServiceTest.php`
  - Update/add tests for unified quality thresholds
  - Update/add tests for StreamStatsService integration
  - Remove any `release_group` related tests
  - Ensure all existing tests still pass with changes from T6

  **Must NOT do**:
  - Do NOT delete existing test coverage

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `pest-testing`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T13, T15, T16, T17)
  - **Parallel Group**: Wave 4
  - **Blocks**: None
  - **Blocked By**: T3, T6

  **References**:
  - `tests/Unit/Services/SerieFileNameServiceTest.php`

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact --filter=SerieFileNameService` passes
  - [ ] Tests cover unified quality thresholds

  **QA Scenarios**:
  ```
  Scenario: SerieFileNameService tests pass
    Tool: Bash
    Steps:
      1. php artisan test --compact --filter=SerieFileNameService
    Expected Result: All tests pass, 0 failures
    Evidence: .sisyphus/evidence/t14-serie-tests.log
  ```

  **Commit**: YES
  - Message: `test(serie): update tests for thresholds, StreamStatsService`

- [ ] T15. **Update StrmPathBuilder Tests**

  **What to do**:
  - Open `tests/Unit/Services/StrmPathBuilderTest.php`
  - Verify tests still pass after T7 fix (double call elimination should not change behavior)
  - Add test to verify `generateMovieFileName()` is called exactly once (mock/spy if possible)
  - Add test for toggle behavior (legacy vs trash guide paths)

  **Must NOT do**:
  - Do NOT change existing test expectations unless behavior changed

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `pest-testing`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T13, T14, T16, T17)
  - **Parallel Group**: Wave 4
  - **Blocks**: None
  - **Blocked By**: T7

  **References**:
  - `tests/Unit/Services/StrmPathBuilderTest.php`

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact --filter=StrmPathBuilder` passes
  - [ ] New test verifies single filename generation call

  **QA Scenarios**:
  ```
  Scenario: StrmPathBuilder tests pass
    Tool: Bash
    Steps:
      1. php artisan test --compact --filter=StrmPathBuilder
    Expected Result: All tests pass, 0 failures
    Evidence: .sisyphus/evidence/t15-pathbuilder-tests.log
  ```

  **Commit**: YES
  - Message: `test(strm): add test for single filename generation call`

- [ ] T16. **Add StreamStatsService Tests**

  **What to do**:
  - Create `tests/Unit/Services/StreamStatsServiceTest.php`
  - Test normalization for various inputs:
    - 1080p HEVC HDR
    - 720p AVC non-HDR
    - 480p unknown codec
    - Missing video/audio arrays
    - Null/empty input
  - Test quality detection boundaries (exactly 1080, 720, etc.)
  - Test HDR detection for various color transfers
  - Test audio codec/channel extraction

  **Must NOT do**:
  - Do NOT test external ffprobe integration (test normalization only)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `pest-testing`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T13, T14, T15, T17)
  - **Parallel Group**: Wave 4
  - **Blocks**: None
  - **Blocked By**: T3

  **References**:
  - `app/Services/StreamStatsService.php`

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact --filter=StreamStatsService` passes
  - [ ] Tests cover all major codec/quality/HDR combinations

  **QA Scenarios**:
  ```
  Scenario: StreamStatsService tests pass
    Tool: Bash
    Steps:
      1. php artisan test --compact --filter=StreamStatsService
    Expected Result: All tests pass, 0 failures
    Evidence: .sisyphus/evidence/t16-streamstats-tests.log
  ```

  **Commit**: YES
  - Message: `test(stats): add comprehensive StreamStatsService tests`

- [ ] T17. **Add Integration Tests for Probing + Toggle Behavior**

  **What to do**:
  - Create `tests/Feature/Jobs/SyncVodStrmFilesTest.php` or add to existing
  - Test that sync job respects `trash_guide_naming_enabled` toggle:
    - Toggle ON → uses trash guide format
    - Toggle OFF → uses legacy format + title folders
  - Test auto-probe toggle behavior:
    - Toggle ON + missing stats → probes and uses trash guide naming
    - Toggle OFF + missing stats → uses basic naming without probing
  - Test Episode probing integration
  - Mock ffprobe Shell execution to avoid external dependencies

  **Must NOT do**:
  - Do NOT make real HTTP requests or run real ffprobe in tests

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `pest-testing`, `laravel-best-practices`
    - Needed for complex integration test setup with mocking

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T13, T14, T15, T16)
  - **Parallel Group**: Wave 4
  - **Blocks**: None
  - **Blocked By**: T10, T11, T12

  **References**:
  - `app/Jobs/SyncVodStrmFiles.php`
  - `app/Models/Channel.php`
  - `app/Models/Episode.php`
  - `tests/Unit/Services/VodFileNameServiceTest.php` (for mocking patterns)

  **Acceptance Criteria**:
  - [ ] Integration tests for toggle behavior pass
  - [ ] Integration tests for auto-probe pass
  - [ ] No real ffprobe calls during tests

  **QA Scenarios**:
  ```
  Scenario: Integration tests pass
    Tool: Bash
    Steps:
      1. php artisan test --compact --filter=SyncVodStrmFiles
    Expected Result: All tests pass, 0 failures
    Evidence: .sisyphus/evidence/t17-integration-tests.log
  ```

  **Commit**: YES
  - Message: `test(integration): add tests for toggle and auto-probe behavior`

---

## Final Verification Wave

> 4 review agents run in PARALLEL. ALL must APPROVE. Present consolidated results to user and get explicit "okay" before completing.

- [ ] F1. **Plan Compliance Audit** — `oracle`
  Read the plan end-to-end. For each "Must Have": verify implementation exists (read file, run command). For each "Must NOT Have": search codebase for forbidden patterns — reject with file:line if found. Check evidence files exist in `.sisyphus/evidence/`. Compare deliverables against plan.
  Output: `Must Have [N/N] | Must NOT Have [N/N] | Tasks [N/N] | VERDICT: APPROVE/REJECT`

- [ ] F2. **Code Quality Review** — `unspecified-high`
  Run `php artisan test --compact` + `vendor/bin/pint --dirty --format agent`. Review all changed files for: unsafe types, empty catches, commented-out code, unused imports. Check AI slop: excessive comments, over-abstraction, generic names.
  Output: `Tests [PASS/FAIL] | Pint [PASS/FAIL] | Files [N clean/N issues] | VERDICT`

- [ ] F3. **Real Manual QA** — `unspecified-high`
  Start from clean state. Execute EVERY QA scenario from EVERY task — follow exact steps, capture evidence. Test cross-task integration (toggle + probing + sync working together). Test edge cases: empty stream_stats, invalid regex, missing season.
  Save to `.sisyphus/evidence/final-qa/`.
  Output: `Scenarios [N/N pass] | Integration [N/N] | Edge Cases [N tested] | VERDICT`

- [ ] F4. **Scope Fidelity Check** — `deep`
  For each task: read "What to do", read actual diff (`git diff`). Verify 1:1 — everything in spec was built (no missing), nothing beyond spec was built (no creep). Check "Must NOT do" compliance. Detect cross-task contamination.
  Output: `Tasks [N/N compliant] | Contamination [CLEAN/N issues] | Unaccounted [CLEAN/N files] | VERDICT`

---

## Commit Strategy

- **T1-T4** (Wave 1): `fix(migrations): add trash guide toggle, nullable formats, drop release_group, fix timestamps`
- **T2**: `fix(models): add toggle, remove release_group, revert series relationship`
- **T3**: `feat(services): add unified StreamStatsService`
- **T4**: `feat(filament): add trash guide naming toggle and fix helper text`
- **T5**: `fix(vod): add {-group} support, unify thresholds, use StreamStatsService`
- **T6**: `fix(serie): unify thresholds, use StreamStatsService`
- **T7**: `fix(strm): eliminate double filename generation in buildVodPath`
- **T8**: `fix(versions): add safe regex execution with error handling`
- **T9**: `fix(models): revert Series::episodes() to hasMany to avoid regression`
- **T10**: `fix(sync): use toggle for trash guide, restore legacy title folders, add probing`
- **T11**: `feat(models): extend stream probing to VOD channels and episodes`
- **T12**: `feat(playlist): add auto-probe toggle for VOD/Series streams`
- **T13**: `test(vod): update tests for {-group}, thresholds, StreamStatsService`
- **T14**: `test(serie): update tests for thresholds, StreamStatsService`
- **T15**: `test(strm): add test for single filename generation call`
- **T16**: `test(stats): add comprehensive StreamStatsService tests`
- **T17**: `test(integration): add tests for toggle and auto-probe behavior`

---

## Success Criteria

### Verification Commands
```bash
# Run all tests
php artisan test --compact

# Check code style
vendor/bin/pint --dirty --format agent

# Verify migrations
php artisan migrate:status

# Verify schema
php artisan db:show --database=mysql

# Check for release_group references (should be empty)
grep -r "release_group" app/ --include="*.php" | grep -v "\.git"
```

### Final Checklist
- [ ] All critical/high/medium PR issues resolved
- [ ] `trash_guide_naming_enabled` toggle exists and defaults to false
- [ ] Existing settings NOT forced into trash guide naming
- [ ] `{-group}` works in VodFileNameService
- [ ] Legacy title-folder creation restored
- [ ] Series::episodes() reverted to hasMany
- [ ] `release_group` completely removed from codebase
- [ ] StreamStatsService unifies normalization logic
- [ ] Quality thresholds consistent across VOD and Series
- [ ] Regex execution safe with try/catch
- [ ] Probing extended to VOD channels and episodes
- [ ] Auto-probe toggle in playlist settings
- [ ] All tests pass
- [ ] Code style passes Pint
