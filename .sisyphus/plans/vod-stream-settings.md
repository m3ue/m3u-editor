# Trash Guide VOD/Serien Stream-Datei Einstellungen

## TL;DR

> **Ziel**: VOD und Serien .strm Dateinamen an Trash Guide (TRaSH-Guides) Konventionen anpassen, mit Multi-Version Support und automatischer Attribut-Erkennung aus stream_stats.
>
> **Deliverables**:
> - `TrashGuideNamingService` - Baut Dateinamen aus Channel + stream_stats
> - `VersionDetectionService` - Erkennt Versionen aus Title/Gruppe
> - Erweiterte `Channel` Migration mit manuellen Override-Feldern
> - Angepasster `SyncVodStrmFiles` Job mit Trash Guide Format
> - Neuer `SyncSeriesStrmFiles` Job für Serien
> - Filament UI für manuelle Override-Eingaben
> - Tests für alle neuen Services
>
> **Estimated Effort**: Medium-Large
> **Parallel Execution**: YES - 3 Waves
> **Critical Path**: Migration → Services → Jobs → UI → Tests

---

## Context

### Original Request
Anpassung der Stream-Datei-Einstellungen für VOD und Serien im m3u-editor, damit die Namensgebung dem Trash Guide entspricht. Mehrere VOD-Versionen sollen unterstützt werden, die ein Media Server (Plex/Jellyfin/Emby) als Editionen erkennt.

### Interview Summary
**Key Discussions**:
- Attribute kommen primär aus `stream_stats` (ffprobe), mit manuellem Fallback
- Gruppenname kann in Dateinamen aufgenommen werden
- Multi-Version Erkennung via Title-Parsing + Gruppen-Parsing
- Wenn keine Versions-Info erkannt wird: KEINE Annahmen treffen
- Serien: Komplette Unterstützung mit Staffel-Ordnern

**Research Findings**:
- Trash Guide Movie Format: `{Movie CleanTitle} {(Release Year)} - {{Edition Tags}} {[Custom Formats]}{[Quality Full]}{[Mediainfo AudioCodec}{ Mediainfo AudioChannels]}{[MediaInfo VideoDynamicRangeType]}{[Mediainfo VideoCodec]}{-Release Group}`
- Multi-Version: `{edition-Name}` oder `- VersionInfo` Suffixe
- Serien: `Show Name/Season 01/Show Name - S01E01 - Episode Title`
- Projekt hat bereits: `StreamFileSetting`, `SyncVodStrmFiles`, ffprobe-Integration

### Metis Review
**Identified Gaps** (addressed):
- Manuelle Override-Felder nötig für fehlende stream_stats
- Version Detection muss robust sein (keine falschen Annahmen)
- Serien-Struktur muss existierende Episode/Season Models nutzen
- Settings müssen konfigurierbar bleiben

---

## Work Objectives

### Core Objective
VOD und Serien .strm Dateien generieren, die Trash Guide Konventionen folgen, mit automatischer Attribut-Erkennung aus stream_stats, manuellem Fallback, und Multi-Version Support für Media Server.

### Concrete Deliverables
- `app/Services/TrashGuideNamingService.php`
- `app/Services/VersionDetectionService.php`
- Migration: Erweiterte `channels` Tabelle (manuelle Override-Felder)
- Migration: Erweiterte `stream_file_settings` Tabelle (Trash Guide Konfiguration)
- Angepasster `app/Jobs/SyncVodStrmFiles.php`
- Neuer `app/Jobs/SyncSeriesStrmFiles.php`
- Filament Form-Schema Erweiterungen in `VodResource`
- Tests für alle neuen Services

### Definition of Done
- [ ] VOD .strm Dateien folgen Trash Guide Format
- [ ] Serien .strm Dateien haben korrekte Ordnerstruktur
- [ ] Multi-Versionen werden korrekt gruppiert
- [ ] Manuelle Overrides funktionieren
- [ ] Alle Tests passen

### Must Have
- Trash Guide konforme Dateinamen für VODs
- Automatische Attribut-Erkennung aus stream_stats
- Manuelle Override-Möglichkeit
- Multi-Version Support (gleiche TMDB/IMDB ID)
- Serien Staffel-Ordner Struktur
- Media Server kompatible Edition-Erkennung

### Must NOT Have (Guardrails)
- KEINE Annahmen bei fehlenden Daten (leer lassen statt Defaults)
- Keine Breaking Changes an bestehendem Sync-Verhalten
- Keine zusätzlichen externen APIs (nur bestehende ffprobe)
- Keine manuelle Pflege von Datenbank-Beziehungen für Versionen

---

## Verification Strategy

> **ZERO HUMAN INTERVENTION** - ALL verification is agent-executed.

### Test Decision
- **Infrastructure exists**: YES (Pest PHP v4)
- **Automated tests**: YES (Tests after implementation)
- **Framework**: Pest PHP

### QA Policy
Every task MUST include agent-executed QA scenarios.

- **Backend/Services**: Use Bash (php artisan tinker, phpunit)
- **API**: Use Bash (curl)
- **UI**: Use Playwright (Filament Forms validieren)

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Foundation - can all start immediately):
├── Task 1: Migration für manuelle Override-Felder [quick]
├── Task 2: StreamFileSetting Erweiterung [quick]
├── Task 3: TrashGuideNamingService erstellen [unspecified-high]
├── Task 4: VersionDetectionService erstellen [unspecified-high]
└── Task 5: StrmPathBuilder Helper erstellen [quick]

Wave 2 (Core Implementation - depends on Wave 1):
├── Task 6: SyncVodStrmFiles Job anpassen [unspecified-high]
├── Task 7: SyncSeriesStrmFiles Job erstellen [unspecified-high]
├── Task 8: VodResource UI erweitern [visual-engineering]
├── Task 9: StreamFileSetting Resource erweitern [visual-engineering]
└── Task 10: Settings/Konfiguration hinzufügen [quick]

Wave 3 (Tests & Integration - depends on Wave 2):
├── Task 11: Tests für TrashGuideNamingService [unspecified-high]
├── Task 12: Tests für VersionDetectionService [unspecified-high]
├── Task 13: Integration Tests für Jobs [unspecified-high]
└── Task 14: End-to-End Verifikation [unspecified-high]

Wave FINAL (After ALL tasks):
├── Task F1: Plan Compliance Audit (oracle)
├── Task F2: Code Quality Review (unspecified-high)
├── Task F3: Real Manual QA (unspecified-high)
└── Task F4: Scope Fidelity Check (deep)

Critical Path: T1-T5 → T6-T10 → T11-T14 → F1-F4
```

### Dependency Matrix
- **T1-T5**: No dependencies (Wave 1)
- **T6**: Depends on T1, T3, T4, T5
- **T7**: Depends on T1, T3, T5
- **T8**: Depends on T1
- **T9**: Depends on T2
- **T10**: No dependencies (can run parallel to T6-T9)
- **T11-T14**: Depend on T6-T10

### Agent Dispatch Summary
- **Wave 1**: 5 tasks → mix of quick + unspecified-high
- **Wave 2**: 5 tasks → mix of unspecified-high + visual-engineering + quick
- **Wave 3**: 4 tasks → unspecified-high
- **FINAL**: 4 tasks → oracle + unspecified-high + deep

---

## TODOs

- [ ] 1. Migration: Manuelle Override-Felder für Channel

  **What to do**:
  - Erstelle Migration die `channels` Tabelle erweitert um:
    - `edition` (string, nullable) - z.B. "Director's Cut", "Extended"
    - `quality` (string, nullable) - z.B. "4K", "1080p", "720p"
    - `release_group` (string, nullable) - z.B. "STRiFE", "NTb"
    - `video_dynamic_range` (string, nullable) - z.B. "HDR", "HDR10+", "DV"
    - `custom_format_tags` (json, nullable) - Array von Custom Format Tags
    - `edition_tags` (json, nullable) - Array von Edition Tags
  - Füge Cast-Definitionen zum Channel Model hinzu
  - Alle Felder sind nullable (keine Defaults!)

  **Must NOT do**:
  - Keine bestehenden Felder ändern oder löschen
  - Keine NOT NULL Constraints ohne Default
  - Keine Datenmigration nötig (neue Felder sind leer)

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `laravel-best-practices`
    - Laravel Migrationen und Model Konventionen

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1
  - **Blocks**: T6, T8
  - **Blocked By**: None

  **References**:
  - `app/Models/Channel.php` - Bestehende $casts Definition
  - `database/migrations/xxxx_create_channels_table.php` - Bestehende Migration als Vorlage

  **Acceptance Criteria**:
  - [ ] Migration läuft erfolgreich: `php artisan migrate`
  - [ ] Channel Model hat neue Attribute in $casts
  - [ ] `php artisan migrate:rollback` funktioniert
  - [ ] Datenbank-Schema zeigt neue nullable Spalten

  **QA Scenarios**:
  ```
  Scenario: Migration läuft erfolgreich
    Tool: Bash
    Preconditions: Clean database state
    Steps:
      1. Run: php artisan migrate --path=database/migrations/xxxx_add_trash_guide_fields_to_channels.php
      2. Run: php artisan tinker --execute 'echo \App\Models\Channel::first()?->edition ?? "OK";'
    Expected Result: Migration completed without errors, new fields are nullable
    Evidence: .sisyphus/evidence/task-1-migration-success.txt
  ```

  **Commit**: YES
  - Message: `feat(vod): add manual override fields to channels table`
  - Files: `database/migrations/xxxx_add_trash_guide_fields_to_channels.php`, `app/Models/Channel.php`

- [ ] 2. Migration: StreamFileSetting Erweiterung für Trash Guide

  **What to do**:
  - Erstelle Migration die `stream_file_settings` Tabelle erweitert um:
    - `use_trash_guide_format` (boolean, default: false) - Opt-in für Trash Guide
    - `trash_guide_pattern` (string, nullable) - Custom Pattern Override
    - `include_group_in_name` (boolean, default: false) - Gruppenname einfügen
    - `group_position` (string, default: 'suffix') - 'prefix' oder 'suffix'
  - Füge Cast-Definitionen zum StreamFileSetting Model hinzu
  - Erstelle Enum oder Konstanten für group_position Werte

  **Must NOT do**:
  - Kein forced Opt-in (Default: false)
  - Keine bestehenden Settings überschreiben

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1
  - **Blocks**: T9, T10
  - **Blocked By**: None

  **References**:
  - Suche nach `StreamFileSetting` Model
  - Bestehende Migrationen als Vorlage

  **Acceptance Criteria**:
  - [ ] Migration läuft erfolgreich
  - [ ] Neue Felder haben korrekte Defaults
  - [ ] Settings bleiben backward compatible

  **QA Scenarios**:
  ```
  Scenario: Neue Settings sind backward compatible
    Tool: Bash
    Steps:
      1. Run: php artisan migrate
      2. Run: php artisan tinker --execute '$s = \App\Models\StreamFileSetting::first(); echo $s->use_trash_guide_format ?? "default_OK";'
    Expected Result: Default false, keine Fehler bei bestehenden Records
    Evidence: .sisyphus/evidence/task-2-settings-migration.txt
  ```

  **Commit**: YES
  - Message: `feat(vod): extend stream file settings for trash guide`

- [ ] 3. Service: TrashGuideNamingService erstellen

  **What to do**:
  - Erstelle `app/Services/TrashGuideNamingService.php`
  - Hauptmethode: `generateMovieFilename(Channel $channel): string`
  - Format: `{CleanTitle} ({Year}) - {{Edition Tags}} {[Custom Formats]}{[Quality]}{[AudioCodec AudioChannels]}{[HDR]}{[VideoCodec]}{-ReleaseGroup}.{ext}`
  - Attribute-Quelle (in Reihenfolge):
    1. Manuelle Override-Felder (wenn gesetzt)
    2. stream_stats (ffprobe Daten)
    3. Title/Gruppe Parsing (für Quality/Edition)
    4. Leer lassen wenn nichts gefunden
  - Helper: `cleanTitle(string $title): string`
  - Helper: `formatEditionTags(array $tags): string`
  - Helper: `formatCustomFormats(array $formats): string`
  - Helper: `detectQualityFromStreamStats(array $stats): ?string`
  - Helper: `detectHdrFromStreamStats(array $stats): ?string`
  - Qualitäts-Erkennung aus stream_stats:
    - 3840x2160 oder höher → "2160p"
    - 1920x1080 → "1080p"
    - 1280x720 → "720p"
    - Unter 720p → Resolution als "WxH"
  - HDR-Erkennung aus stream_stats:
    - bits_per_raw_sample > 8 → "HDR" (oder spezifischer wenn erkennbar)
    - codec_profile mit "10" → "HDR10"

  **Must NOT do**:
  - KEINE Defaults bei fehlenden Daten (leer lassen!)
  - Keine komplexe AI/ML Logik
  - Keine externen API Calls

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`, `php-best-practices`
    - Service-Architektur, Type-Safety, Clean Code

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1
  - **Blocks**: T6, T7
  - **Blocked By**: None

  **References**:
  - `app/Services/PlaylistService.php:329-363` - `makeFilesystemSafe()` Methode
  - `app/Models/Channel.php:420-498` - `getEmbyStreamStats()` Methode zeigt stream_stats Struktur
  - `app/Models/Channel.php:39-67` - $casts Definition

  **Acceptance Criteria**:
  - [ ] Service existiert und ist testbar
  - [ ] Methode `generateMovieFilename()` gibt korrekten String zurück
  - [ ] Fehlende Daten werden ignoriert (nicht mit Defaults gefüllt)
  - [ ] Manuelle Overrides haben Priorität über automatische Erkennung

  **QA Scenarios**:
  ```
  Scenario: Vollständiger Dateiname mit allen Attributen
    Tool: Bash (php artisan tinker)
    Preconditions: Channel mit title="Test Movie", year=2023, stream_stats mit 4K/HDR
    Steps:
      1. Create test channel with complete data
      2. Call TrashGuideNamingService::generateMovieFilename($channel)
    Expected Result: "Test Movie (2023) - [2160p][HDR][HEVC].strm" (format may vary based on exact stats)
    Evidence: .sisyphus/evidence/task-3-full-filename.txt

  Scenario: Dateiname mit fehlenden Daten
    Tool: Bash (php artisan tinker)
    Preconditions: Channel mit nur title und year
    Steps:
      1. Create test channel with minimal data
      2. Call TrashGuideNamingService::generateMovieFilename($channel)
    Expected Result: "Test Movie (2023).strm" - keine erfundenen Attribute!
    Evidence: .sisyphus/evidence/task-3-minimal-filename.txt
  ```

  **Commit**: YES
  - Message: `feat(vod): add TrashGuideNamingService`

- [ ] 4. Service: VersionDetectionService erstellen

  **What to do**:
  - Erstelle `app/Services/VersionDetectionService.php`
  - Methode: `detectVersion(Channel $channel): ?string`
  - Erkennt Version/Qualität aus:
    1. `channel.title` / `channel.name` (Parsing)
    2. `channel.group` (Gruppenname Parsing)
    3. `channel.quality` (manuell gesetzt)
  - Keywords die erkannt werden:
    - "4K", "2160p", "UHD" → "4K"
    - "1080p", "FHD" → "1080p"
    - "720p", "HD" → "720p"
    - "HDR", "HDR10", "HDR10+", "DV", "Dolby Vision" → "HDR"
    - "Director's Cut", "Extended", "Uncut" → Edition Info
  - Methode: `findRelatedVersions(Channel $channel): Collection`
  - Findet Channels mit gleicher TMDB/IMDB ID
  - Gruppiert Versionen für Multi-Version Ordner
  - Methode: `generateEditionSuffix(?string $version): string`
  - Gibt `{edition-Version}` oder `- Version` zurück

  **Must NOT do**:
  - KEINE Annahmen wenn keine Keywords gefunden werden (return null)
  - Keine komplexe NLP/AI Logik
  - Keine Datenbank-Änderungen (nur read-only)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`, `php-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1
  - **Blocks**: T6
  - **Blocked By**: None

  **References**:
  - `app/Models/Channel.php:677-702` - `getTmdbId()`, `getImdbId()`, `hasMovieId()`
  - `app/Models/Channel.php:704-752` - `scopeHasMovieId()`, `scopeMissingMovieId()`

  **Acceptance Criteria**:
  - [ ] Erkennt "4K" aus Titel "Movie Name 4K"
  - [ ] Erkennt "HDR" aus Gruppe "4K HDR Movies"
  - [ ] Gibt null zurück wenn nichts erkannt wird
  - [ ] Findet verwandte Versionen über TMDB/IMDB ID

  **QA Scenarios**:
  ```
  Scenario: Version aus Titel erkennen
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create channel with title="Awesome Movie 4K HDR"
      2. Call VersionDetectionService::detectVersion($channel)
    Expected Result: "4K" or "HDR" (specific to implementation)
    Evidence: .sisyphus/evidence/task-4-title-version.txt

  Scenario: Keine Version erkannt
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create channel with title="Simple Movie"
      2. Call VersionDetectionService::detectVersion($channel)
    Expected Result: null (nicht "1080p" oder ähnliches!)
    Evidence: .sisyphus/evidence/task-4-no-version.txt

  Scenario: Verwandte Versionen finden
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create 2 channels with same tmdb_id
      2. Call VersionDetectionService::findRelatedVersions($channel1)
    Expected Result: Collection containing channel2
    Evidence: .sisyphus/evidence/task-4-related-versions.txt
  ```

  **Commit**: YES
  - Message: `feat(vod): add VersionDetectionService`

- [ ] 5. Helper: StrmPathBuilder erstellen

  **What to do**:
  - Erstelle `app/Services/StrmPathBuilder.php`
  - Methode: `buildVodPath(Channel $channel, StreamFileSetting $setting): string`
  - Methode: `buildSeriesPath(Series $series, Episode $episode, StreamFileSetting $setting): string`
  - Nutzt `PlaylistService::makeFilesystemSafe()` für Dateinamen
  - VOD Pfad: `{base_path}/{Movie Name} ({Year})/{filename}.strm`
  - Multi-Version VOD Pfad: `{base_path}/{Movie Name} ({Year})/{filename}{edition}.strm`
  - Serie Pfad: `{base_path}/{Show Name}/Season {NN}/{Show Name} - S{NN}E{NN} - {Episode Title}.strm`
  - Saison-Nummer immer 2-stellig: "01", "02", etc.
  - Episode-Nummer immer 2-stellig: "01", "02", etc.

  **Must NOT do**:
  - Keine hartkodierten Pfade
  - Keine Annahmen über existierende Ordnerstruktur

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1
  - **Blocks**: T6, T7
  - **Blocked By**: None

  **References**:
  - `app/Services/PlaylistService.php:329-363` - `makeFilesystemSafe()`
  - `app/Services/PlaylistService.php:365-402` - `getVodExample()`, `getEpisodeExample()`

  **Acceptance Criteria**:
  - [ ] VOD Pfad enthält Film-Ordner
  - [ ] Serie Pfad enthält Staffel-Ordner
  - [ ] Dateinamen sind filesystem-safe

  **QA Scenarios**:
  ```
  Scenario: VOD Pfad bauen
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create channel with title="Test Movie", year=2023
      2. Call StrmPathBuilder::buildVodPath($channel, $setting)
    Expected Result: ".../Test Movie (2023)/Test Movie (2023).strm"
    Evidence: .sisyphus/evidence/task-5-vod-path.txt

  Scenario: Serie Pfad bauen
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create series with name="Test Show"
      2. Create episode with season=1, episode=5, title="Pilot"
      3. Call StrmPathBuilder::buildSeriesPath($series, $episode, $setting)
    Expected Result: ".../Test Show/Season 01/Test Show - S01E05 - Pilot.strm"
    Evidence: .sisyphus/evidence/task-5-series-path.txt
  ```

  **Commit**: YES
  - Message: `feat(vod): add StrmPathBuilder helper`

- [ ] 6. Job: SyncVodStrmFiles anpassen für Trash Guide

  **What to do**:
  - Passe `app/Jobs/SyncVodStrmFiles.php` an
  - Nutze `TrashGuideNamingService` für Dateinamen (wenn Setting aktiviert)
  - Nutze `VersionDetectionService` für Multi-Version Gruppierung
  - Nutze `StrmPathBuilder` für Pfad-Konstruktion
  - Logik:
    1. Prüfe `streamFileSetting->use_trash_guide_format`
    2. Wenn true: Nutze TrashGuideNamingService
    3. Wenn false: Behalte bestehendes Verhalten bei
    4. Prüfe auf verwandte Versionen (gleiche TMDB/IMDB ID)
    5. Generiere .strm Datei mit korrektem Inhalt (Stream URL)
  - Multi-Version Logik:
    - Wenn verwandte Versionen existieren: Gleicher Ordner
    - Jede Version bekommt eindeutigen Dateinamen (via Edition/Quality Suffix)
    - Ordnername: `{Movie Name} ({Year})`

  **Must NOT do**:
  - Kein forced Trash Guide (nur wenn Setting aktiviert)
  - Keine bestehenden .strm Dateien löschen (nur neue generieren)
  - Keine Versions-Annahmen wenn keine Daten vorhanden

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T7, T8, T9, T10)
  - **Parallel Group**: Wave 2
  - **Blocks**: T11, T13
  - **Blocked By**: T1, T3, T4, T5

  **References**:
  - `app/Jobs/SyncVodStrmFiles.php` - Bestehender Job
  - `app/Services/TrashGuideNamingService.php` - Neu (Task 3)
  - `app/Services/VersionDetectionService.php` - Neu (Task 4)
  - `app/Services/StrmPathBuilder.php` - Neu (Task 5)

  **Acceptance Criteria**:
  - [ ] Job generiert Trash Guide konforme Dateinamen
  - [ ] Multi-Versionen werden im selben Ordner gruppiert
  - [ ] Bestehendes Verhalten bleibt erhalten (wenn Setting false)

  **QA Scenarios**:
  ```
  Scenario: VOD .strm mit Trash Guide Format
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create channel with complete data and enabled trash guide setting
      2. Dispatch SyncVodStrmFiles job
      3. Check generated file path
    Expected Result: File exists at correct path with Trash Guide format name
    Evidence: .sisyphus/evidence/task-6-vod-strm.txt

  Scenario: Multi-Version VOD .strm
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create 2 channels with same tmdb_id but different qualities
      2. Dispatch SyncVodStrmFiles for both
      3. Check file paths
    Expected Result: Both files in same folder with different names
    Evidence: .sisyphus/evidence/task-6-multi-version.txt
  ```

  **Commit**: YES
  - Message: `feat(vod): adapt SyncVodStrmFiles for trash guide format`

- [ ] 7. Job: SyncSeriesStrmFiles erstellen

  **What to do**:
  - Erstelle `app/Jobs/SyncSeriesStrmFiles.php`
  - Ähnliche Struktur wie SyncVodStrmFiles
  - Nutzt `StrmPathBuilder` für Serien-Pfade
  - Generiert .strm Dateien für Episoden
  - Format: `{Show Name}/Season {NN}/{Show Name} - S{NN}E{NN} - {Episode Title}.strm`
  - Stream URL in .strm Datei: Episode Stream URL
  - Behandelt Episoden ohne Titel (nur Episode-Nummer)
  - Erstellt Staffel-Ordner wenn nicht vorhanden

  **Must NOT do**:
  - Keine Episoden-Titel erfinden wenn nicht vorhanden
  - Keine Staffel-Nummern erfinden

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T6, T8, T9, T10)
  - **Parallel Group**: Wave 2
  - **Blocks**: T13
  - **Blocked By**: T1, T3, T5

  **References**:
  - `app/Jobs/SyncVodStrmFiles.php` - Als Vorlage
  - `app/Services/StrmPathBuilder.php` - Neu (Task 5)
  - `app/Models/Series.php`, `app/Models/Episode.php` - Bestehende Models

  **Acceptance Criteria**:
  - [ ] Job generiert Episoden .strm Dateien
  - [ ] Korrekte Ordnerstruktur (Show/Season/Episoden)
  - [ ] Stream URL ist in .strm Datei enthalten

  **QA Scenarios**:
  ```
  Scenario: Serie .strm Generierung
    Tool: Bash (php artisan tinker)
    Steps:
      1. Create series with seasons and episodes
      2. Dispatch SyncSeriesStrmFiles job
      3. Check generated files
    Expected Result: Files exist at .../Show Name/Season 01/Show Name - S01E01 - Title.strm
    Evidence: .sisyphus/evidence/task-7-series-strm.txt
  ```

  **Commit**: YES
  - Message: `feat(series): add SyncSeriesStrmFiles job`

- [ ] 8. UI: VodResource erweitern für manuelle Overrides

  **What to do**:
  - Erweitere `VodResource` Form-Schema
  - Füge neue Felder hinzu in der Edit-Ansicht:
    - `edition` - TextInput (nullable)
    - `quality` - TextInput (nullable)
    - `release_group` - TextInput (nullable)
    - `video_dynamic_range` - TextInput (nullable)
    - `custom_format_tags` - TagsInput (JSON Array)
    - `edition_tags` - TagsInput (JSON Array)
  - Felder sollten in einem eigenen Section "Trash Guide Overrides" gruppiert sein
  - Felder sind alle optional
  - Hilfetexte erklären: "Leer lassen für automatische Erkennung"

  **Must NOT do**:
  - Keine required Felder
  - Keine komplexe Validierung

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: `tailwindcss-development`, `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T6, T7, T9, T10)
  - **Parallel Group**: Wave 2
  - **Blocked By**: T1

  **References**:
  - `app/Filament/Resources/Vods/VodResource.php` - Bestehende Form-Definition
  - Filament Docs: Form Components

  **Acceptance Criteria**:
  - [ ] Neue Felder sind in Vod Edit sichtbar
  - [ ] Felder sind optional
  - [ ] Daten werden korrekt gespeichert

  **QA Scenarios**:
  ```
  Scenario: Manuelle Overrides speichern
    Tool: Playwright oder manuell via Browser
    Steps:
      1. Open VOD edit page
      2. Fill in manual override fields
      3. Save
      4. Re-open and verify values persisted
    Expected Result: Values are saved and displayed correctly
    Evidence: .sisyphus/evidence/task-8-ui-overrides.png
  ```

  **Commit**: YES
  - Message: `feat(vod): add trash guide override fields to VodResource`

- [ ] 9. UI: StreamFileSetting Resource erweitern

  **What to do**:
  - Erweitere StreamFileSetting Resource/Form
  - Füge Trash Guide Einstellungen hinzu:
    - `use_trash_guide_format` - Toggle
    - `include_group_in_name` - Toggle
    - `group_position` - Select (prefix/suffix)
  - Erklärender Hilfetext für jede Einstellung

  **Must NOT do**:
  - Keine Breaking Changes an bestehenden Settings

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: `tailwindcss-development`, `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T6, T7, T8, T10)
  - **Parallel Group**: Wave 2
  - **Blocked By**: T2

  **References**:
  - Suche nach `StreamFileSetting` Resource

  **Acceptance Criteria**:
  - [ ] Neue Settings sind verfügbar
  - [ ] Toggle funktioniert korrekt

  **QA Scenarios**:
  ```
  Scenario: Trash Guide Setting aktivieren
    Tool: Playwright oder Browser
    Steps:
      1. Open StreamFileSetting edit
      2. Enable "Use Trash Guide Format"
      3. Save
    Expected Result: Setting is persisted
    Evidence: .sisyphus/evidence/task-9-settings-ui.png
  ```

  **Commit**: YES
  - Message: `feat(vod): add trash guide settings to StreamFileSetting`

- [ ] 10. Settings: Globale Konfiguration für Trash Guide

  **What to do**:
  - Erweitere `GeneralSettings` oder erstelle `TrashGuideSettings`
  - Füge globale Defaults hinzu:
    - Default Pattern Override
    - Default Quality Detection (an/aus)
    - Default HDR Detection (an/aus)
  - Settings sind über Filament Settings Page erreichbar

  **Must NOT do**:
  - Keine forced Settings

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T6, T7, T8, T9)
  - **Parallel Group**: Wave 2
  - **Blocked By**: None (independent)

  **References**:
  - `app/Settings/GeneralSettings.php`
  - Bestehende Settings Pages

  **Acceptance Criteria**:
  - [ ] Settings Page existiert
  - [ ] Defaults sind konfigurierbar

  **QA Scenarios**:
  ```
  Scenario: Globale Settings anpassen
    Tool: Bash (php artisan tinker)
    Steps:
      1. Update settings via code or UI
      2. Verify settings are persisted
    Expected Result: Settings are saved and retrievable
    Evidence: .sisyphus/evidence/task-10-global-settings.txt
  ```

  **Commit**: YES
  - Message: `feat(vod): add global trash guide configuration`

- [ ] 11. Tests: TrashGuideNamingService Tests

  **What to do**:
  - Erstelle `tests/Unit/Services/TrashGuideNamingServiceTest.php`
  - Teste folgende Szenarien:
    - Vollständiger Dateiname mit allen Attributen
    - Minimaler Dateiname (nur Titel + Jahr)
    - Manuelle Overrides haben Priorität
    - stream_stats Erkennung funktioniert
    - Fehlende Daten werden ignoriert (keine Defaults)
    - Qualitäts-Erkennung aus Resolution
    - HDR-Erkennung aus Bit-Tiefe
    - Gruppenname-Einbindung (wenn aktiviert)
  - Nutze Pest PHP Syntax

  **Must NOT do**:
  - Keine Tests die von externen Daten abhängen
  - Keine Integration Tests (Unit Tests only)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `pest-testing`, `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T12, T13, T14)
  - **Parallel Group**: Wave 3
  - **Blocked By**: T3

  **References**:
  - `app/Services/TrashGuideNamingService.php`
  - Pest PHP Docs

  **Acceptance Criteria**:
  - [ ] Alle Test-Szenarien sind abgedeckt
  - [ ] Tests laufen erfolgreich: `php artisan test --compact --filter=TrashGuideNamingServiceTest`

  **QA Scenarios**:
  ```
  Scenario: Tests laufen erfolgreich
    Tool: Bash
    Steps:
      1. Run: php artisan test --compact --filter=TrashGuideNamingServiceTest
    Expected Result: All tests pass
    Evidence: .sisyphus/evidence/task-11-tests-pass.txt
  ```

  **Commit**: YES
  - Message: `test(vod): add TrashGuideNamingService tests`

- [ ] 12. Tests: VersionDetectionService Tests

  **What to do**:
  - Erstelle `tests/Unit/Services/VersionDetectionServiceTest.php`
  - Teste folgende Szenarien:
    - Version aus Titel erkennen ("4K", "1080p", etc.)
    - Version aus Gruppenname erkennen
    - Keine Version erkannt (null)
    - Verwandte Versionen finden (gleiche TMDB ID)
    - Edition Tags erkennen ("Director's Cut", etc.)
    - Edge Cases: Titel mit Jahreszahl aber ohne Qualität

  **Must NOT do**:
  - Keine Annahme-Tests (wenn keine Daten → null)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `pest-testing`, `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T11, T13, T14)
  - **Parallel Group**: Wave 3
  - **Blocked By**: T4

  **References**:
  - `app/Services/VersionDetectionService.php`

  **Acceptance Criteria**:
  - [ ] Alle Test-Szenarien sind abgedeckt
  - [ ] Tests laufen erfolgreich

  **QA Scenarios**:
  ```
  Scenario: VersionDetection Tests
    Tool: Bash
    Steps:
      1. Run: php artisan test --compact --filter=VersionDetectionServiceTest
    Expected Result: All tests pass
    Evidence: .sisyphus/evidence/task-12-tests-pass.txt
  ```

  **Commit**: YES
  - Message: `test(vod): add VersionDetectionService tests`

- [ ] 13. Tests: Integration Tests für Jobs

  **What to do**:
  - Erstelle `tests/Feature/Jobs/SyncVodStrmFilesTest.php`
  - Erstelle `tests/Feature/Jobs/SyncSeriesStrmFilesTest.php`
  - Teste:
    - Job generiert korrekte .strm Dateien
    - Multi-Version Gruppierung funktioniert
    - Serien Ordnerstruktur ist korrekt
    - Bestehendes Verhalten bleibt erhalten
  - Nutze temporäres Dateisystem für Tests

  **Must NOT do**:
  - Keine echten Dateisystem-Operationen (nutze Storage::fake())
  - Keine externen API Calls in Tests

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `pest-testing`, `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T11, T12, T14)
  - **Parallel Group**: Wave 3
  - **Blocked By**: T6, T7

  **References**:
  - `app/Jobs/SyncVodStrmFiles.php`
  - `app/Jobs/SyncSeriesStrmFiles.php`
  - Laravel Storage Fake Docs

  **Acceptance Criteria**:
  - [ ] Integration Tests für beide Jobs
  - [ ] Tests laufen erfolgreich

  **QA Scenarios**:
  ```
  Scenario: Job Integration Tests
    Tool: Bash
    Steps:
      1. Run: php artisan test --compact --filter=SyncVodStrmFilesTest
      2. Run: php artisan test --compact --filter=SyncSeriesStrmFilesTest
    Expected Result: All tests pass
    Evidence: .sisyphus/evidence/task-13-integration-tests.txt
  ```

  **Commit**: YES
  - Message: `test(vod): add job integration tests`

- [ ] 14. End-to-End Verifikation

  **What to do**:
  - Führe alle Tests aus: `php artisan test --compact`
  - Prüfe Code-Qualität: `vendor/bin/pint --dirty --format agent`
  - Manuelle Verifikation:
    - Erstelle Test-VOD mit verschiedenen Attributen
    - Sync .strm Dateien
    - Prüfe Dateinamen und Ordnerstruktur
    - Teste Serien-Episoden
  - Dokumentiere Ergebnisse

  **Must NOT do**:
  - Keine Änderungen an Produktions-Daten

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: `laravel-best-practices`

  **Parallelization**:
  - **Can Run In Parallel**: YES (with T11, T12, T13)
  - **Parallel Group**: Wave 3
  - **Blocked By**: T6-T13

  **Acceptance Criteria**:
  - [ ] Alle Tests passen
  - [ ] Code Style ist korrekt
  - [ ] Manuelle Verifikation erfolgreich

  **QA Scenarios**:
  ```
  Scenario: End-to-End Test
    Tool: Bash
    Steps:
      1. Run: php artisan test --compact
      2. Run: vendor/bin/pint --dirty --format agent
      3. Run: php artisan tinker --execute '/* create test data and sync */'
    Expected Result: All green, files generated correctly
    Evidence: .sisyphus/evidence/task-14-e2e-results.txt
  ```

  **Commit**: YES
  - Message: `chore(vod): final e2e verification and polish`

---

## Final Verification Wave

> 4 review agents run in PARALLEL. ALL must APPROVE.

- [ ] F1. **Plan Compliance Audit** — `oracle`
  Read the plan end-to-end. For each "Must Have": verify implementation exists (read file, curl endpoint, run command). For each "Must NOT Have": search codebase for forbidden patterns — reject with file:line if found. Check evidence files exist in .sisyphus/evidence/. Compare deliverables against plan.
  Output: `Must Have [N/N] | Must NOT Have [N/N] | Tasks [N/N] | VERDICT: APPROVE/REJECT`

- [ ] F2. **Code Quality Review** — `unspecified-high`
  Run `tsc --noEmit` + linter + `bun test`. Review all changed files for: `as any`/`@ts-ignore`, empty catches, console.log in prod, commented-out code, unused imports. Check AI slop: excessive comments, over-abstraction, generic names (data/result/item/temp).
  Output: `Build [PASS/FAIL] | Lint [PASS/FAIL] | Tests [N pass/N fail] | Files [N clean/N issues] | VERDICT`

- [ ] F3. **Real Manual QA** — `unspecified-high` (+ `playwright` skill if UI)
  Start from clean state. Execute EVERY QA scenario from EVERY task — follow exact steps, capture evidence. Test cross-task integration (features working together, not isolation). Test edge cases: empty state, invalid input, rapid actions. Save to `.sisyphus/evidence/final-qa/`.
  Output: `Scenarios [N/N pass] | Integration [N/N] | Edge Cases [N tested] | VERDICT`

- [ ] F4. **Scope Fidelity Check** — `deep`
  For each task: read "What to do", read actual diff (git log/diff). Verify 1:1 — everything in spec was built (no missing), nothing beyond spec was built (no creep). Check "Must NOT do" compliance. Detect cross-task contamination: Task N touching Task M's files. Flag unaccounted changes.
  Output: `Tasks [N/N compliant] | Contamination [CLEAN/N issues] | Unaccounted [CLEAN/N files] | VERDICT`

---

## Commit Strategy

- **Wave 1**: `feat(vod): add trash guide foundation - migrations and services`
- **Wave 2**: `feat(vod): implement trash guide sync jobs and UI`
- **Wave 3**: `test(vod): add trash guide service tests`
- **FINAL**: `refactor(vod): final qa and polish`

---

## Success Criteria

### Verification Commands
```bash
php artisan test --compact --filter="TrashGuide"
php artisan test --compact --filter="VersionDetection"
php artisan test --compact --filter="SyncVodStrmFiles"
php artisan test --compact --filter="SyncSeriesStrmFiles"
```

### Final Checklist
- [ ] Alle Must-Have Anforderungen erfüllt
- [ ] Keine Must-NOT-Have Regeln verletzt
- [ ] Alle Tests passen
- [ ] Code folgt Laravel/Filament Konventionen
- [ ] Keine Breaking Changes an bestehendem Verhalten
