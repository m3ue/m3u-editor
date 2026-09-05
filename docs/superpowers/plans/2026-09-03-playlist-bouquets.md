# Playlist Bouquets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reusable, named group selections ("bouquets") per playlist, attachable to playlist aliases, whose selections union with the alias's manual `group_filter` (issue #1391).

**Architecture:** Two new tables (`bouquets` + `bouquet_playlist_alias` pivot). Bouquets store provider-stable **name arrays** in a `group_selections` jsonb column shaped exactly like `playlist_aliases.group_filter`. Resolution happens ONLY inside the four `PlaylistAlias` accessors (`getAllowedLiveGroupNames` / `getAllowedVodGroupNames` / `getAllowedCategoryNames` / `hasGroupFilter`), memoized per instance, so every consumer (M3U, EPG, Xtream, guest panel, stream-time validation) inherits automatically with zero call-site changes. Sync hooks propagate provider renames and tag renames into bouquets and manual filters.

**Tech Stack:** Laravel 13, PHP 8.4, Filament v5, Livewire v4, Pest 5, Spatie laravel-tags. Postgres in production, SQLite `:memory:` in tests.

**Spec:** `docs/superpowers/specs/2026-09-03-playlist-bouquets-design.md` — read it before starting any task. Where this plan and the spec disagree, the plan documents the deviation inline (search for "Deviation from spec").

## Global Constraints

- **Run PHP through Herd's binary, never plain `php`** (plain `php` is a crippled vanilla install). In PowerShell: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact <file>`. Referred to below as `herd-php`. Redis must be reachable for tests that create playlists via factory events; if port 6379 is closed run `wsl -e sh -c "redis-server --daemonize yes"`.
- **Never run the whole test suite.** Scope every run to the file(s) named in the step.
- **No infra faking to force green**: no `Bus::fake()`/`Queue::fake()`/`Event::fake()` except where an existing harness already uses it for isolation (e.g. `ProcessM3uImportReimportTest`'s `Bus::fake()` isolates the import phase from chunk jobs — that pattern is allowed). `Http::preventStrayRequests()` in tests touching Playlist models.
- **Test DB gotcha:** `Playlist::factory()->create()` fires `PlaylistCreated` → `ProcessM3uImport` dispatch (needs Redis). Existing alias tests use plain factories successfully; import-related tests wrap creation in `Playlist::withoutEvents(...)`. Follow whichever pattern the referenced existing test file uses.
- **R1 hard constraint (from spec §5):** bouquet resolution must NEVER be an Eloquent attribute accessor/cast on `group_filter`. The Filament form binds `group_filter.*` directly; an attribute-level union would be persisted into the manual filter on save. Resolution lives only inside the named methods.
- **Identity fast path (spec §5):** with no attached bouquets, the accessors return the manual arrays **unchanged** (no re-index / `array_unique`) — existing tests assert exact arrays.
- **Migration safety:** only `Schema::create` for new tables. No ALTER/index DDL on `groups`, `channels`, `source_groups`, `playlists`, `playlist_aliases`.
- **Pint:** after modifying PHP files run `vendor/bin/pint --dirty --format agent` — if it fails on the project `pint.json` (stale vendor Pint), use a scratch config with `{"preset":"laravel"}` per the known workaround.
- **Language files:** every new user-facing `__('...')` string must be added to `lang/en.json` (key = value = the English literal). Task 11 consolidates; run `& herd-php artisan lang:merge-conflicts` before the final commit.
- **Commits:** conventional prefixes (`feat:`, `test:`, `docs:`); **no Co-Authored-By or Claude attribution footers** (project setting).
- Branch: work on `feat/issue-1391-playlist-bouquets` (already exists, spec committed). PRs target `dev`.

---

### Task 1: Migrations, Bouquet model, factory, lifecycle guards

**Files:**
- Create: `database/migrations/2026_09_03_100000_create_bouquets_table.php`
- Create: `database/migrations/2026_09_03_100001_create_bouquet_playlist_alias_table.php`
- Create: `app/Models/Bouquet.php`
- Create: `database/factories/BouquetFactory.php`
- Modify: `app/Providers/AppServiceProvider.php` (register Bouquet model hooks — insert a new `// Bouquets` block directly after the `PlaylistAlias::deleting(...)` registration that ends around line 783)
- Test: `tests/Feature/BouquetModelTest.php`

**Interfaces:**
- Consumes: existing `Playlist`, `CustomPlaylist`, `User` models.
- Produces: `App\Models\Bouquet` with: casts (`group_selections` → array, both `auto_include_*` → boolean); relations `user(): BelongsTo`, `playlist(): BelongsTo`, `customPlaylist(): BelongsTo`; accessors `getSelectedLiveGroupNames(): array`, `getSelectedVodGroupNames(): array`, `getSelectedCategoryNames(): array`; `Bouquet::creating` (auto `user_id`) and `Bouquet::saving` (exactly-one-target guard + auto-include normalization) hooks. Later tasks add more methods to this model.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/BouquetModelTest.php`:

```php
<?php

use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

describe('Bouquet target guard', function () {
    it('creates a standard-target bouquet', function () {
        $bouquet = Bouquet::create([
            'name' => 'Sports',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports HD']],
        ]);

        expect($bouquet->playlist_id)->toBe($this->playlist->id)
            ->and($bouquet->custom_playlist_id)->toBeNull()
            ->and($bouquet->getSelectedLiveGroupNames())->toBe(['Sports HD']);
    });

    it('creates a custom-target bouquet and normalizes auto-include to false', function () {
        $custom = CustomPlaylist::factory()->for($this->user)->create();

        $bouquet = Bouquet::create([
            'name' => 'Family',
            'user_id' => $this->user->id,
            'custom_playlist_id' => $custom->id,
            'auto_include_new_live' => true,
            'auto_include_new_vod' => true,
        ]);

        expect($bouquet->custom_playlist_id)->toBe($custom->id)
            ->and($bouquet->auto_include_new_live)->toBeFalse()
            ->and($bouquet->auto_include_new_vod)->toBeFalse();
    });

    it('rejects a bouquet with no target', function () {
        Bouquet::create(['name' => 'Orphan', 'user_id' => $this->user->id]);
    })->throws(InvalidArgumentException::class);

    it('rejects a bouquet with both targets', function () {
        $custom = CustomPlaylist::factory()->for($this->user)->create();

        Bouquet::create([
            'name' => 'Both',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => $custom->id,
        ]);
    })->throws(InvalidArgumentException::class);
});

describe('Bouquet uniqueness and cascade', function () {
    it('enforces unique name per standard playlist but allows reuse across playlists', function () {
        Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $other = Playlist::factory()->for($this->user)->create();
        $reused = Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $other->id]);
        expect($reused)->not->toBeNull();

        Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    })->throws(QueryException::class);

    it('allows the same name on a standard and a custom target', function () {
        $custom = CustomPlaylist::factory()->for($this->user)->create();

        Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
        $customBouquet = Bouquet::create(['name' => 'Sports', 'user_id' => $this->user->id, 'custom_playlist_id' => $custom->id]);

        expect($customBouquet->exists)->toBeTrue();
    });

    it('cascades bouquet deletion when the target playlist is deleted', function () {
        $bouquet = Bouquet::create(['name' => 'Doomed', 'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $this->playlist->delete();

        expect(Bouquet::find($bouquet->id))->toBeNull();
    });

    it('auto-assigns user_id from the authenticated user', function () {
        $this->actingAs($this->user);

        $bouquet = Bouquet::create(['name' => 'Mine', 'playlist_id' => $this->playlist->id]);

        expect($bouquet->user_id)->toBe($this->user->id);
    });
});
```

Note: if `CustomPlaylist::factory()` does not exist (check `database/factories/`), create rows with `CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id])` instead — the `creating` hook assigns the uuid.

- [ ] **Step 2: Run the test to verify it fails**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetModelTest.php`
Expected: FAIL — `Class "App\Models\Bouquet" not found` / missing table.

- [ ] **Step 3: Create the two migrations**

`database/migrations/2026_09_03_100000_create_bouquets_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User-defined, reusable selections of a playlist's groups (issue #1391).
     *
     * A bouquet targets exactly one of playlist_id / custom_playlist_id (enforced
     * at the application layer — SQLite cannot ALTER in a CHECK). Membership is
     * stored as provider-stable NAME arrays in group_selections, mirroring
     * playlist_aliases.group_filter, so selections survive the source_groups
     * hard-delete / groups soft-delete churn and union cleanly with manual filters.
     *
     * Both Postgres and SQLite treat NULLs as distinct in unique indexes, so each
     * of the two composite uniques constrains only rows of its own target type.
     */
    public function up(): void
    {
        Schema::create('bouquets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('custom_playlist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('group_selections')->nullable();
            $table->boolean('auto_include_new_live')->default(false);
            $table->boolean('auto_include_new_vod')->default(false);
            $table->timestamps();

            $table->unique(['playlist_id', 'name']);
            $table->unique(['custom_playlist_id', 'name']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bouquets');
    }
};
```

`database/migrations/2026_09_03_100001_create_bouquet_playlist_alias_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alias <-> bouquet assignment (many-to-many, union semantics). No sort
     * column: assignment order is meaningless under union composition. The
     * playlist_alias_id index serves the hot request-time direction
     * (alias -> bouquets); the unique covers bouquet -> aliases for the UI.
     */
    public function up(): void
    {
        Schema::create('bouquet_playlist_alias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bouquet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_alias_id')->constrained()->cascadeOnDelete();

            $table->unique(['bouquet_id', 'playlist_alias_id']);
            $table->index('playlist_alias_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bouquet_playlist_alias');
    }
};
```

- [ ] **Step 4: Create the model and factory**

`app/Models/Bouquet.php` (the `playlistAliases()` relation is added in Task 2):

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bouquet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'group_selections' => 'array',
        'auto_include_new_live' => 'boolean',
        'auto_include_new_vod' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    /**
     * @return array<string>
     */
    public function getSelectedLiveGroupNames(): array
    {
        return $this->group_selections['selected_groups'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getSelectedVodGroupNames(): array
    {
        return $this->group_selections['selected_vod_groups'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getSelectedCategoryNames(): array
    {
        return $this->group_selections['selected_categories'] ?? [];
    }
}
```

Check sibling models: if they use `$fillable` instead of `$guarded = []`, list the columns explicitly (`user_id`, `playlist_id`, `custom_playlist_id`, `name`, `description`, `group_selections`, `auto_include_new_live`, `auto_include_new_vod`) to match convention.

`database/factories/BouquetFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Bouquet;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BouquetFactory extends Factory
{
    protected $model = Bouquet::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'group_selections' => null,
            'auto_include_new_live' => false,
            'auto_include_new_vod' => false,
        ];
    }
}
```

- [ ] **Step 5: Register the lifecycle guards in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, add `use App\Models\Bouquet;` and `use InvalidArgumentException;` to the imports (check they aren't already present), then insert after the `PlaylistAlias::deleting(...)` block (ends ~line 783):

```php
            // Bouquets (issue #1391)
            Bouquet::creating(function (Bouquet $bouquet) {
                if (! $bouquet->user_id) {
                    $bouquet->user_id = auth()->id();
                }

                return $bouquet;
            });
            Bouquet::saving(function (Bouquet $bouquet) {
                $hasPlaylist = $bouquet->playlist_id !== null;
                $hasCustom = $bouquet->custom_playlist_id !== null;
                if ($hasPlaylist === $hasCustom) {
                    throw new InvalidArgumentException('A bouquet must target exactly one of playlist_id or custom_playlist_id.');
                }
                if ($hasCustom) {
                    // Auto-include is a provider-sync concept; custom playlists never sync.
                    $bouquet->auto_include_new_live = false;
                    $bouquet->auto_include_new_vod = false;
                }

                return $bouquet;
            });
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetModelTest.php`
Expected: PASS (all cases).

- [ ] **Step 7: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_03_100000_create_bouquets_table.php database/migrations/2026_09_03_100001_create_bouquet_playlist_alias_table.php app/Models/Bouquet.php database/factories/BouquetFactory.php app/Providers/AppServiceProvider.php tests/Feature/BouquetModelTest.php
git commit -m "feat: add Bouquet model, tables, and lifecycle guards (issue #1391)"
```

---

### Task 2: Pivot model, relations, attach invariant, EPG cache invalidation

**Files:**
- Create: `app/Pivots/BouquetPlaylistAlias.php`
- Modify: `app/Models/Bouquet.php` (add `playlistAliases()`)
- Modify: `app/Models/PlaylistAlias.php` (add `bouquets()`)
- Modify: `app/Models/Playlist.php` (add `bouquets()`)
- Modify: `app/Models/CustomPlaylist.php` (add `bouquets()`)
- Modify: `app/Providers/AppServiceProvider.php` (Bouquet updated/deleting → EPG cache clearing)
- Test: `tests/Feature/BouquetModelTest.php` (extend), `tests/Feature/EpgPlaylistAliasCacheInvalidationTest.php` (extend)

**Interfaces:**
- Consumes: Task 1's model/tables; `App\Services\EpgCacheService::clearPlaylistEpgCacheFile($playlist): bool` and `EpgCacheService::getPlaylistEpgCachePath($playlist, bool $gz)` (both exist).
- Produces: `PlaylistAlias::bouquets(): BelongsToMany` and `Bouquet::playlistAliases(): BelongsToMany`, both `->using(App\Pivots\BouquetPlaylistAlias::class)`. Attaching a mismatched pair throws `InvalidArgumentException`. Attach/detach/bouquet-edit/bouquet-delete all clear attached aliases' cached EPG files.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/BouquetModelTest.php` (add `use App\Models\PlaylistAlias;` and the `makeAlias`-equivalent inline helper — copy the exact helper from `tests/Feature/PlaylistAliasTest.php:14-23`, renamed `makeBouquetTestAlias` to avoid a global-function collision across test files):

```php
function makeBouquetTestAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Test Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ], $overrides));
}

describe('Bouquet attachment invariant', function () {
    it('attaches a standard-target bouquet to an alias of the same playlist', function () {
        $alias = makeBouquetTestAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $alias->bouquets()->attach($bouquet);

        expect($alias->bouquets()->count())->toBe(1)
            ->and($bouquet->playlistAliases()->count())->toBe(1);
    });

    it('rejects attaching a bouquet of a different playlist', function () {
        $alias = makeBouquetTestAlias($this->user, $this->playlist);
        $other = Playlist::factory()->for($this->user)->create();
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $other->id]);

        $alias->bouquets()->attach($bouquet);
    })->throws(InvalidArgumentException::class);

    it('rejects attaching a standard-target bouquet to a custom-playlist alias', function () {
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id]);
        $alias = makeBouquetTestAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
        ]);
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

        $alias->bouquets()->attach($bouquet);
    })->throws(InvalidArgumentException::class);

    it('attaches a custom-target bouquet to an alias of the same custom playlist', function () {
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id]);
        $alias = makeBouquetTestAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
        ]);

        $alias->bouquets()->attach($bouquet);

        expect($alias->bouquets()->count())->toBe(1);
    });

    it('rejects attaching anything to a merged-playlist alias', function () {
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
        $merged = \App\Models\MergedPlaylist::create(['name' => 'MP', 'user_id' => $this->user->id]);
        $alias = makeBouquetTestAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'merged_playlist_id' => $merged->id,
        ]);

        $alias->bouquets()->attach($bouquet);
    })->throws(InvalidArgumentException::class);

    it('cascades pivot rows when the bouquet is deleted', function () {
        $alias = makeBouquetTestAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($bouquet);

        $bouquet->delete();

        expect($alias->bouquets()->count())->toBe(0);
    });
});
```

If `MergedPlaylist::create` requires a uuid (check its `creating` hook in AppServiceProvider — it likely auto-generates), adjust to match the hook's behavior.

Then extend `tests/Feature/EpgPlaylistAliasCacheInvalidationTest.php` — mirror its existing "seed a fake cached file, act, assert file gone" pattern exactly (read the file first for its helpers), adding cases:

1. Bouquet `group_selections` update clears the attached alias's cached EPG file, and leaves an unattached alias's file alone.
2. Attaching a bouquet to an alias clears that alias's cached file.
3. Detaching clears it.
4. Deleting an attached bouquet clears it.
5. Regression: the existing target-change clearing cases still pass unmodified.

- [ ] **Step 2: Run to verify failure**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetModelTest.php`
Expected: FAIL — `Call to undefined method App\Models\PlaylistAlias::bouquets()`.

- [ ] **Step 3: Create the pivot model**

`app/Pivots/BouquetPlaylistAlias.php`:

```php
<?php

namespace App\Pivots;

use App\Models\Bouquet;
use App\Models\PlaylistAlias;
use App\Services\EpgCacheService;
use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

class BouquetPlaylistAlias extends Pivot
{
    protected $table = 'bouquet_playlist_alias';

    public $incrementing = true;

    public $timestamps = false;

    protected static function booted(): void
    {
        // Server-side attach invariant: a bouquet only ever applies to aliases of
        // its own playlist — its stored names are meaningless anywhere else. The
        // Filament form pre-filters options; this guard covers API/console writes.
        // Custom pivot classes make attach()/detach()/sync() fire these events.
        static::creating(function (self $pivot): void {
            $bouquet = Bouquet::find($pivot->bouquet_id);
            $alias = PlaylistAlias::find($pivot->playlist_alias_id);

            $matches = $bouquet && $alias && (
                ($bouquet->playlist_id !== null && $bouquet->playlist_id === $alias->playlist_id)
                || ($bouquet->custom_playlist_id !== null && $bouquet->custom_playlist_id === $alias->custom_playlist_id)
            );

            if (! $matches) {
                throw new InvalidArgumentException('A bouquet can only be attached to an alias of the same playlist.');
            }
        });

        // Attaching or detaching changes the alias's effective filter immediately,
        // so its cached EPG XML is stale.
        static::created(function (self $pivot): void {
            $alias = PlaylistAlias::find($pivot->playlist_alias_id);
            if ($alias) {
                EpgCacheService::clearPlaylistEpgCacheFile($alias);
            }
        });
        static::deleted(function (self $pivot): void {
            $alias = PlaylistAlias::find($pivot->playlist_alias_id);
            if ($alias) {
                EpgCacheService::clearPlaylistEpgCacheFile($alias);
            }
        });
    }
}
```

- [ ] **Step 4: Add the relations**

In `app/Models/Bouquet.php` (add imports for `BelongsToMany` and `App\Pivots\BouquetPlaylistAlias`):

```php
    public function playlistAliases(): BelongsToMany
    {
        return $this->belongsToMany(PlaylistAlias::class, 'bouquet_playlist_alias')
            ->using(BouquetPlaylistAlias::class);
    }
```

In `app/Models/PlaylistAlias.php` (import `App\Pivots\BouquetPlaylistAlias`), next to the other relationship methods (e.g. after `playlistViewers()` at the end of the relations):

```php
    public function bouquets(): BelongsToMany
    {
        return $this->belongsToMany(Bouquet::class, 'bouquet_playlist_alias')
            ->using(BouquetPlaylistAlias::class);
    }
```

In `app/Models/Playlist.php` (near `aliases()` ~line 354) and `app/Models/CustomPlaylist.php`:

```php
    public function bouquets(): HasMany
    {
        return $this->hasMany(Bouquet::class);
    }
```

- [ ] **Step 5: Add the EPG-cache hooks to the Bouquet block in AppServiceProvider**

Append inside the `// Bouquets` block from Task 1 (import `App\Services\EpgCacheService` if not present — it is already imported for the alias hooks):

```php
            Bouquet::updated(function (Bouquet $bouquet) {
                if ($bouquet->wasChanged('group_selections')) {
                    // Selections feed attached aliases' effective filters — their cached
                    // EPG XML was generated against the old selection.
                    $bouquet->playlistAliases->each(
                        fn (PlaylistAlias $alias) => EpgCacheService::clearPlaylistEpgCacheFile($alias)
                    );
                }
            });
            Bouquet::deleting(function (Bouquet $bouquet) {
                // Pivot rows cascade at the DB level (no pivot events fire there), so
                // clear the attached aliases' caches while they are still attached.
                $bouquet->playlistAliases->each(
                    fn (PlaylistAlias $alias) => EpgCacheService::clearPlaylistEpgCacheFile($alias)
                );

                return $bouquet;
            });
```

- [ ] **Step 6: Run both test files**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetModelTest.php`
Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/EpgPlaylistAliasCacheInvalidationTest.php`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add -A app/Pivots/BouquetPlaylistAlias.php app/Models app/Providers/AppServiceProvider.php tests/Feature/BouquetModelTest.php tests/Feature/EpgPlaylistAliasCacheInvalidationTest.php
git commit -m "feat: bouquet-alias pivot with attach invariant and EPG cache invalidation"
```

---

### Task 3: Accessor union resolution on PlaylistAlias

**Files:**
- Modify: `app/Models/PlaylistAlias.php:84-117` (the four accessors) + new private memo
- Test: `tests/Feature/PlaylistAliasBouquetResolutionTest.php`

**Interfaces:**
- Consumes: `PlaylistAlias::bouquets()` (Task 2), `Bouquet` accessors (Task 1).
- Produces: `getAllowedLiveGroupNames()` / `getAllowedVodGroupNames()` / `getAllowedCategoryNames()` return `manual ∪ bouquet` names; `hasGroupFilter()` is true when any effective list is non-empty. **Every downstream consumer (channels(), series(), Xtream, guest panel) inherits automatically — do not touch any of them.**

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PlaylistAliasBouquetResolutionTest.php`:

```php
<?php

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

function makeResolutionAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Test Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

describe('accessor union', function () {
    it('returns the manual arrays unchanged when no bouquets are attached (identity fast path)', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports', 'Sports']],  // duplicate on purpose
        ]);

        // Bit-for-bit: no dedupe, no re-index on the bouquet-less path.
        expect($alias->getAllowedLiveGroupNames())->toBe(['Sports', 'Sports'])
            ->and($alias->getAllowedVodGroupNames())->toBe([])
            ->and($alias->getAllowedCategoryNames())->toBe([]);
    });

    it('unions manual and bouquet selections with dedupe, per type independently', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports'], 'selected_categories' => ['Drama']],
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => [
                'selected_groups' => ['Sports', 'News'],
                'selected_vod_groups' => ['Movies 4K'],
            ],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        expect($alias->getAllowedLiveGroupNames())->toEqualCanonicalizing(['Sports', 'News'])
            ->and($alias->getAllowedVodGroupNames())->toBe(['Movies 4K'])
            ->and($alias->getAllowedCategoryNames())->toBe(['Drama']);
    });

    it('reports hasGroupFilter() true for a bouquet-only alias', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, ['group_filter' => null]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        expect($alias->hasGroupFilter())->toBeTrue();
    });

    it('fails open when every attached bouquet is empty and there is no manual filter', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, ['group_filter' => null]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => null,
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        expect($alias->getAllowedLiveGroupNames())->toBe([])
            ->and($alias->hasGroupFilter())->toBeFalse();
    });

    it('filters channels() through the union end to end', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports']],
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['News']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Sports', 'is_vod' => false, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'News', 'is_vod' => false, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Docs', 'is_vod' => false, 'enabled' => true,
        ]);

        $groups = $alias->channels()->pluck('channels.group_internal');
        expect($groups)->toContain('Sports')
            ->and($groups)->toContain('News')
            ->and($groups)->not->toContain('Docs');
    });

    it('a vanished bouquet name is harmless in the whereIn', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Ghost Group', 'Sports']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Sports', 'is_vod' => false, 'enabled' => true,
        ]);

        expect($alias->channels()->count())->toBe(1);
    });
});

describe('custom-target resolution', function () {
    it('unions bouquet tag names into the custom constraint path', function () {
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id]);
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
            'group_filter' => null,
        ]);

        $tagged = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => null,
        ]);
        $fallback = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => 'Provider News',
        ]);
        $excluded = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => 'Provider Docs',
        ]);
        $custom->channels()->attach([$tagged->id, $fallback->id, $excluded->id]);

        $tag = Tag::findOrCreate('My Custom Group', $custom->uuid);
        $tagged->attachTag($tag);

        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
            'group_selections' => ['selected_groups' => ['My Custom Group', 'Provider News']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        $ids = $alias->channels()->pluck('channels.id');
        expect($ids)->toContain($tagged->id)
            ->and($ids)->toContain($fallback->id)
            ->and($ids)->not->toContain($excluded->id);
    });
});

describe('query cost', function () {
    it('memoizes the bouquet lookup: one pivot query across repeated accessor calls', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias = PlaylistAlias::find($alias->id);

        DB::enableQueryLog();
        $alias->getAllowedLiveGroupNames();
        $alias->getAllowedVodGroupNames();
        $alias->getAllowedCategoryNames();
        $alias->hasGroupFilter();
        $bouquetQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'bouquet'));
        DB::disableQueryLog();

        expect($bouquetQueries)->toHaveCount(1);
    });

    it('runs zero bouquet queries for a merged-playlist alias', function () {
        $merged = MergedPlaylist::create(['name' => 'MP', 'user_id' => $this->user->id]);
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'merged_playlist_id' => $merged->id,
        ]);
        $alias = PlaylistAlias::find($alias->id);

        DB::enableQueryLog();
        $alias->getAllowedLiveGroupNames();
        $alias->hasGroupFilter();
        $bouquetQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'bouquet'));
        DB::disableQueryLog();

        expect($bouquetQueries)->toHaveCount(0);
    });
});
```

Note on the custom-target case: attaching channels to a custom playlist uses the `channel_custom_playlist` pivot (`$custom->channels()->attach([...])`). If `Channel::factory()` cascades imports in this context (see the factory-cascade memory), create channels with `'group_id' => null` explicitly in the attribute arrays above (already implied — no `->for($group)` used).

- [ ] **Step 2: Run to verify failure**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasBouquetResolutionTest.php`
Expected: FAIL — union cases fail (accessors return manual only), memoization count is 0.

- [ ] **Step 3: Implement the memoized union**

In `app/Models/PlaylistAlias.php`, add next to the existing memo properties (lines 25-29):

```php
    /** @var array{selected_groups: array<string>, selected_vod_groups: array<string>, selected_categories: array<string>}|null Memoised union of attached bouquets' selections. */
    private ?array $bouquetSelections = null;
```

Add this private method near the accessors:

```php
    /**
     * The merged selections of every attached bouquet, memoised per instance.
     *
     * NEVER surface this through a group_filter attribute accessor/cast: the
     * Filament alias form binds group_filter.* state paths directly, and an
     * attribute-level union would be hydrated into the form and persisted into
     * the manual filter on save (spec R1).
     *
     * @return array{selected_groups: array<string>, selected_vod_groups: array<string>, selected_categories: array<string>}
     */
    private function bouquetSelections(): array
    {
        if ($this->bouquetSelections !== null) {
            return $this->bouquetSelections;
        }

        $merged = ['selected_groups' => [], 'selected_vod_groups' => [], 'selected_categories' => []];

        // Merged-playlist (and orphaned) aliases have no bouquet support — zero queries.
        if (! $this->playlist_id && ! $this->custom_playlist_id) {
            return $this->bouquetSelections = $merged;
        }

        $bouquets = $this->relationLoaded('bouquets') ? $this->bouquets : $this->bouquets()->get();

        foreach ($bouquets as $bouquet) {
            foreach ($merged as $key => $existing) {
                $names = $bouquet->group_selections[$key] ?? [];
                if (! empty($names)) {
                    $merged[$key] = array_merge($existing, $names);
                }
            }
        }

        return $this->bouquetSelections = $merged;
    }
```

Replace the four accessors (currently at lines 84-117) with:

```php
    /**
     * Get the allowed live group names for this alias: the manual group_filter
     * selection unioned with every attached bouquet's selection (empty = no
     * restriction). With no bouquets attached the manual array is returned
     * unchanged so existing behavior stays bit-for-bit identical.
     *
     * @return array<string>
     */
    public function getAllowedLiveGroupNames(): array
    {
        return $this->allowedNamesFor('selected_groups');
    }

    /**
     * Get the allowed VOD group names for this alias (empty = no restriction).
     *
     * @return array<string>
     */
    public function getAllowedVodGroupNames(): array
    {
        return $this->allowedNamesFor('selected_vod_groups');
    }

    /**
     * Get the allowed series category names for this alias (empty = no restriction).
     *
     * @return array<string>
     */
    public function getAllowedCategoryNames(): array
    {
        return $this->allowedNamesFor('selected_categories');
    }

    /**
     * @return array<string>
     */
    private function allowedNamesFor(string $key): array
    {
        $manual = $this->group_filter[$key] ?? [];
        $bouquet = $this->bouquetSelections()[$key];

        if (empty($bouquet)) {
            return $manual;
        }

        return array_values(array_unique(array_merge($manual, $bouquet)));
    }

    /**
     * Whether this alias has any group/category filter applied, from its manual
     * selection or any attached bouquet.
     */
    public function hasGroupFilter(): bool
    {
        return ! empty($this->getAllowedLiveGroupNames())
            || ! empty($this->getAllowedVodGroupNames())
            || ! empty($this->getAllowedCategoryNames());
    }
```

- [ ] **Step 4: Run the new tests and the regression suite**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasBouquetResolutionTest.php`
Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasTest.php`
Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasCustomPlaylistFilterTest.php`
Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasLiveGroupSortTest.php`
Expected: ALL PASS. The three existing files passing unmodified is the no-regression proof — if any existing assertion fails, the fast path is broken; fix the implementation, never the existing test.

- [ ] **Step 5: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add app/Models/PlaylistAlias.php tests/Feature/PlaylistAliasBouquetResolutionTest.php
git commit -m "feat: resolve bouquet unions inside PlaylistAlias filter accessors"
```

---

### Task 4: Provider rename propagation and auto-include on sync

**Files:**
- Modify: `app/Models/Bouquet.php` (add `applyProviderRenames`, `appendNewGroupNames`)
- Modify: `app/Models/PlaylistAlias.php` (add `applyProviderGroupRenames`)
- Modify: `app/Jobs/ProcessM3uImport.php` (`syncSourceGroupType()`, the `if (! empty($renames))` block at ~1695-1702 and end of method ~1744)
- Test: `tests/Feature/BouquetRenamePropagationTest.php`

**Interfaces:**
- Consumes: Task 1-3 model APIs; `syncSourceGroupType(Collection $groups, string $type, string $selectedKey, array $currentSelected, Playlist $playlist): array` (private — tested via reflection).
- Produces: `Bouquet::applyProviderRenames(int $playlistId, string $type, array $renames): void`; `Bouquet::appendNewGroupNames(int $playlistId, string $type, array $newNames): void`; `PlaylistAlias::applyProviderGroupRenames(int $playlistId, string $type, array $renames): void`. `$type` is `'live'` or `'vod'`; `$renames` is `[oldName => newName]`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/BouquetRenamePropagationTest.php`. The wiring cases invoke the private method through reflection — this runs the real production code against real rows, no infra faked (the method takes everything it needs as parameters):

```php
<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Bouquet;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceGroup;
use App\Models\User;
use Illuminate\Support\Collection;

function runSyncSourceGroupType(Playlist $playlist, Collection $groups, string $type = 'live', array $currentSelected = []): array
{
    $job = new ProcessM3uImport($playlist, force: true, isNew: false);
    $method = new ReflectionMethod($job, 'syncSourceGroupType');

    $selectedKey = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

    return $method->invoke($job, $groups, $type, $selectedKey, $currentSelected, $playlist);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'import_prefs' => [],
    ]));
});

describe('provider rename propagation', function () {
    beforeEach(function () {
        SourceGroup::create([
            'name' => 'Sports', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 101, 'type' => 'live',
        ]);
    });

    it('rewrites bouquet selections when a tracked group is renamed', function () {
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports', 'News'], 'selected_vod_groups' => ['Sports']],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]));

        $bouquet->refresh();
        expect($bouquet->getSelectedLiveGroupNames())->toBe(['Sports HD', 'News'])
            // VOD selections are untouched by a live-type rename pass.
            ->and($bouquet->getSelectedVodGroupNames())->toBe(['Sports']);
    });

    it('rewrites alias manual group_filter and live_group_order (companion fix)', function () {
        $alias = PlaylistAlias::create([
            'name' => 'A', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id, 'xtream_config' => null,
            'group_filter' => [
                'selected_groups' => ['Sports'],
                'sort_live_groups_custom' => true,
                'live_group_order' => ['Sports'],
            ],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]));

        $alias->refresh();
        expect($alias->group_filter['selected_groups'])->toBe(['Sports HD'])
            ->and($alias->group_filter['live_group_order'])->toBe(['Sports HD']);
    });

    it('does not touch bouquets of other playlists or custom-target bouquets', function () {
        $otherPlaylist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create(['import_prefs' => []]));
        $otherBouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $otherPlaylist->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);
        $custom = \App\Models\CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id]);
        $customBouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]));

        expect($otherBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Sports'])
            ->and($customBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Sports']);
    });

    it('still propagates renames into import_prefs (existing behavior pinned)', function () {
        [$selected] = runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]), 'live', ['Sports']);

        expect($selected)->toBe(['Sports HD'])
            ->and($this->playlist->refresh()->import_prefs['selected_groups'])->toBe(['Sports HD']);
    });
});

describe('auto-include new groups', function () {
    it('appends genuinely new group names to flagged bouquets only', function () {
        SourceGroup::create([
            'name' => 'Existing', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 201, 'type' => 'live',
        ]);
        $flagged = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'auto_include_new_live' => true,
            'group_selections' => ['selected_groups' => ['Existing']],
        ]);
        $unflagged = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Existing']],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 201, 'category_name' => 'Existing'],
            ['category_id' => 202, 'category_name' => 'Brand New'],
        ]));

        expect($flagged->refresh()->getSelectedLiveGroupNames())->toBe(['Existing', 'Brand New'])
            ->and($unflagged->refresh()->getSelectedLiveGroupNames())->toBe(['Existing']);
    });

    it('does not treat a renamed group as new', function () {
        SourceGroup::create([
            'name' => 'Old Name', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 301, 'type' => 'live',
        ]);
        $flagged = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'auto_include_new_live' => true,
            'group_selections' => ['selected_groups' => []],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 301, 'category_name' => 'New Name'],
        ]));

        expect($flagged->refresh()->getSelectedLiveGroupNames())->toBe([]);
    });

    it('respects the vod flag independently', function () {
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'auto_include_new_live' => false,
            'auto_include_new_vod' => true,
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 401, 'category_name' => 'Fresh VOD'],
        ]), 'vod');

        expect($bouquet->refresh()->getSelectedVodGroupNames())->toBe(['Fresh VOD'])
            ->and($bouquet->getSelectedLiveGroupNames())->toBe([]);
    });
});
```

Check the `ProcessM3uImport` constructor signature before writing (`new ProcessM3uImport($playlist, force: true, isNew: false)` matches `ProcessM3uImportReimportTest.php:79`). If `SourceGroup::create` trips a guard, check `SourceGroup`'s fillable.

- [ ] **Step 2: Run to verify failure**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetRenamePropagationTest.php`
Expected: FAIL — bouquet/alias rewrite cases fail (methods don't exist / no wiring). The `import_prefs` pinning case should already PASS.

- [ ] **Step 3: Add the model methods**

In `app/Models/Bouquet.php`:

```php
    /**
     * Rewrite provider group renames (old name => new name) into every
     * standard-target bouquet of the playlist. Called from the sync pipeline's
     * rename-detection pass, the same one that already rewrites import_prefs, so
     * bouquets keep matching within the same sync. Saves through Eloquent so the
     * EPG-cache invalidation hook fires for attached aliases.
     *
     * @param  array<string, string>  $renames
     */
    public static function applyProviderRenames(int $playlistId, string $type, array $renames): void
    {
        $key = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

        self::where('playlist_id', $playlistId)->cursor()->each(function (self $bouquet) use ($key, $renames): void {
            $current = $bouquet->group_selections[$key] ?? [];
            if (empty($current)) {
                return;
            }

            $updated = array_values(array_unique(
                array_map(fn (string $name): string => $renames[$name] ?? $name, $current)
            ));

            if ($updated !== $current) {
                $bouquet->update([
                    'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                ]);
            }
        });
    }

    /**
     * Append newly-appeared provider group names to bouquets that opted in via
     * the per-type auto-include flag. Custom-target bouquets are structurally
     * excluded (playlist_id is NULL).
     *
     * @param  array<string>  $newNames
     */
    public static function appendNewGroupNames(int $playlistId, string $type, array $newNames): void
    {
        if (empty($newNames)) {
            return;
        }

        $flag = $type === 'vod' ? 'auto_include_new_vod' : 'auto_include_new_live';
        $key = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

        self::where('playlist_id', $playlistId)->where($flag, true)->cursor()->each(function (self $bouquet) use ($key, $newNames): void {
            $current = $bouquet->group_selections[$key] ?? [];
            $updated = array_values(array_unique(array_merge($current, $newNames)));

            if ($updated !== $current) {
                $bouquet->update([
                    'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                ]);
            }
        });
    }
```

In `app/Models/PlaylistAlias.php`:

```php
    /**
     * Companion fix to the bouquet rename propagation: rewrite provider group
     * renames into the playlist's aliases' manual group_filter (and, for live,
     * the custom sort order), which previously went silently stale. Quiet saves:
     * output is identical before and after (names track the provider), so no
     * EPG-cache invalidation or other update side effects are wanted.
     *
     * @param  array<string, string>  $renames
     */
    public static function applyProviderGroupRenames(int $playlistId, string $type, array $renames): void
    {
        $key = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

        self::where('playlist_id', $playlistId)->cursor()->each(function (self $alias) use ($key, $type, $renames): void {
            $filter = $alias->group_filter ?? [];
            $changed = false;

            $map = function (array $names) use ($renames): array {
                return array_values(array_unique(
                    array_map(fn (string $name): string => $renames[$name] ?? $name, $names)
                ));
            };

            $current = $filter[$key] ?? [];
            if (! empty($current) && ($updated = $map($current)) !== $current) {
                $filter[$key] = $updated;
                $changed = true;
            }

            if ($type === 'live') {
                $order = $filter['live_group_order'] ?? [];
                if (! empty($order) && ($updatedOrder = $map($order)) !== $order) {
                    $filter['live_group_order'] = $updatedOrder;
                    $changed = true;
                }
            }

            if ($changed) {
                $alias->updateQuietly(['group_filter' => $filter]);
            }
        });
    }
```

- [ ] **Step 4: Wire into syncSourceGroupType**

In `app/Jobs/ProcessM3uImport.php` (add `use App\Models\Bouquet;` and confirm `PlaylistAlias` is imported), extend the existing rename block (currently lines 1695-1702):

```php
        if (! empty($renames)) {
            $currentSelected = array_values(
                array_map(fn ($name) => $renames[$name] ?? $name, $currentSelected)
            );
            $importPrefs = $playlist->import_prefs;
            $playlist->update(['import_prefs' => array_merge($importPrefs, [$selectedKey => $currentSelected])]);
            $playlist->refresh();

            // Bouquets and alias manual filters store the same provider-stable
            // names as import_prefs and go just as stale on a rename (issue #1391).
            Bouquet::applyProviderRenames($playlistId, $type, $renames);
            PlaylistAlias::applyProviderGroupRenames($playlistId, $type, $renames);
        }
```

Then, directly before the method's `return` statement (currently line 1745), add the auto-include pass. `$nameIndex` at this point reflects pre-sync rows adjusted for renames, so names absent from it are genuinely new (a renamed group is not "new"):

```php
        $newNames = $groups->pluck('category_name')
            ->unique()
            ->reject(fn ($name) => $name === null || $nameIndex->has($name))
            ->values()
            ->all();
        Bouquet::appendNewGroupNames($playlistId, $type, $newNames);

        return [$currentSelected, $groups->unique('category_name')->keyBy('category_name')];
```

- [ ] **Step 5: Run tests**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetRenamePropagationTest.php`
Expected: PASS. Also re-run `tests/Feature/PlaylistAliasBouquetResolutionTest.php` (accessor file was touched).

- [ ] **Step 6: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add app/Models/Bouquet.php app/Models/PlaylistAlias.php app/Jobs/ProcessM3uImport.php tests/Feature/BouquetRenamePropagationTest.php
git commit -m "feat: propagate provider group renames into bouquets and alias filters; auto-include new groups"
```

---

### Task 5: Custom tag rename propagation

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (register `Tag::updated` closure — place it next to the existing `Group::updated` registration at ~line 706)
- Test: `tests/Feature/TagRenamePropagationTest.php`

**Interfaces:**
- Consumes: `Spatie\Tags\Tag` (already imported in AppServiceProvider), `CustomPlaylist` uuid convention (group tags: `type = uuid`; category tags: `type = uuid.'-category'`), Bouquet/PlaylistAlias from earlier tasks.
- Produces: renaming a tag rewrites the owning custom playlist's bouquets (`selected_groups` AND `selected_vod_groups` for group tags — the tag namespace is shared across live/VOD; `selected_categories` for category tags) and its aliases' manual `group_filter`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TagRenamePropagationTest.php`:

```php
<?php

use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Spatie\Tags\Tag;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id]);
});

it('rewrites bouquet group selections (live and vod keys) when a group tag is renamed', function () {
    $tag = Tag::findOrCreate('Old Group', $this->custom->uuid);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $this->custom->id,
        'group_selections' => [
            'selected_groups' => ['Old Group', 'Other'],
            'selected_vod_groups' => ['Old Group'],
            'selected_categories' => ['Old Group'],  // same string, different namespace — must NOT change
        ],
    ]);

    $tag->setTranslation('name', 'en', 'New Group');
    $tag->save();

    $bouquet->refresh();
    expect($bouquet->getSelectedLiveGroupNames())->toBe(['New Group', 'Other'])
        ->and($bouquet->getSelectedVodGroupNames())->toBe(['New Group'])
        ->and($bouquet->getSelectedCategoryNames())->toBe(['Old Group']);
});

it('rewrites bouquet category selections when a category tag is renamed', function () {
    $tag = Tag::findOrCreate('Old Cat', $this->custom->uuid.'-category');
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $this->custom->id,
        'group_selections' => ['selected_categories' => ['Old Cat'], 'selected_groups' => ['Old Cat']],
    ]);

    $tag->setTranslation('name', 'en', 'New Cat');
    $tag->save();

    $bouquet->refresh();
    expect($bouquet->getSelectedCategoryNames())->toBe(['New Cat'])
        ->and($bouquet->getSelectedLiveGroupNames())->toBe(['Old Cat']);
});

it('rewrites alias manual group_filter for the same custom playlist', function () {
    $tag = Tag::findOrCreate('Old Group', $this->custom->uuid);
    $alias = PlaylistAlias::create([
        'name' => 'A', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
        'playlist_id' => null, 'custom_playlist_id' => $this->custom->id,
        'xtream_config' => null,
        'group_filter' => ['selected_groups' => ['Old Group']],
    ]);

    $tag->setTranslation('name', 'en', 'New Group');
    $tag->save();

    expect($alias->refresh()->group_filter['selected_groups'])->toBe(['New Group']);
});

it('leaves other playlists and standard-target bouquets alone', function () {
    $otherCustom = CustomPlaylist::create(['name' => 'Other CP', 'user_id' => $this->user->id]);
    $tag = Tag::findOrCreate('Shared Name', $this->custom->uuid);

    $otherBouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $otherCustom->id,
        'group_selections' => ['selected_groups' => ['Shared Name']],
    ]);
    $standardBouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Shared Name']],
    ]);

    $tag->setTranslation('name', 'en', 'Renamed');
    $tag->save();

    expect($otherBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Shared Name'])
        ->and($standardBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Shared Name']);
});

it('ignores tags whose type is not a custom playlist uuid', function () {
    $tag = Tag::findOrCreate('Loose Tag', 'unrelated-type');
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $this->custom->id,
        'group_selections' => ['selected_groups' => ['Loose Tag']],
    ]);

    $tag->setTranslation('name', 'en', 'Renamed Loose');
    $tag->save();

    expect($bouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Loose Tag']);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/TagRenamePropagationTest.php`
Expected: FAIL — rewrite cases fail (no hook).

- [ ] **Step 3: Register the Tag::updated closure**

In `app/Providers/AppServiceProvider.php`, after the existing `Group::updated(...)` block (~line 713):

```php
            // Custom playlist group/category tags: propagate renames into bouquets
            // and alias group filters, which store tag names and would otherwise
            // silently stop matching — same treatment the provider-rename pass in
            // ProcessM3uImport gives standard playlists (issue #1391).
            Tag::updated(function (Tag $tag) {
                if (! $tag->wasChanged('name') || ! $tag->type) {
                    return;
                }

                $oldName = json_decode($tag->getRawOriginal('name') ?? '', true)['en'] ?? null;
                $newName = $tag->getTranslation('name', 'en');
                if (! $oldName || ! $newName || $oldName === $newName) {
                    return;
                }

                $isCategory = str_ends_with($tag->type, '-category');
                $uuid = $isCategory ? substr($tag->type, 0, -strlen('-category')) : $tag->type;
                $customPlaylist = CustomPlaylist::where('uuid', $uuid)->first();
                if (! $customPlaylist) {
                    return;
                }

                // Group tags are shared across live and VOD; category tags map to series.
                $keys = $isCategory ? ['selected_categories'] : ['selected_groups', 'selected_vod_groups'];

                $rewrite = function (array $lists) use ($keys, $oldName, $newName): array {
                    foreach ($keys as $key) {
                        $current = $lists[$key] ?? [];
                        if (in_array($oldName, $current, true)) {
                            $lists[$key] = array_values(array_unique(
                                array_map(fn (string $name): string => $name === $oldName ? $newName : $name, $current)
                            ));
                        }
                    }

                    return $lists;
                };

                Bouquet::where('custom_playlist_id', $customPlaylist->id)
                    ->cursor()
                    ->each(function (Bouquet $bouquet) use ($rewrite): void {
                        $updated = $rewrite($bouquet->group_selections ?? []);
                        if ($updated !== ($bouquet->group_selections ?? [])) {
                            $bouquet->update(['group_selections' => $updated]);
                        }
                    });

                PlaylistAlias::where('custom_playlist_id', $customPlaylist->id)
                    ->cursor()
                    ->each(function (PlaylistAlias $alias) use ($rewrite): void {
                        $updated = $rewrite($alias->group_filter ?? []);
                        if ($updated !== ($alias->group_filter ?? [])) {
                            $alias->updateQuietly(['group_filter' => $updated]);
                        }
                    });
            });
```

Timing note: during the `updated` event Laravel has not yet called `syncOriginal()`, so `getRawOriginal('name')` still returns the pre-save JSON — that is what makes the old-name lookup work. If the raw original is not a JSON string in this Spatie version, adapt with `$tag->getOriginal('name')` (check what it returns under HasTranslations) — the test pins the observable behavior either way.

- [ ] **Step 4: Run tests**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/TagRenamePropagationTest.php`
Expected: PASS. Also re-run `tests/Feature/AutoSyncGroupsToCustomPlaylistTest.php` (that job saves tags; its `order_column` saves must not trip the hook — the `wasChanged('name')` guard covers it).

- [ ] **Step 5: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add app/Providers/AppServiceProvider.php tests/Feature/TagRenamePropagationTest.php
git commit -m "feat: propagate custom tag renames into bouquets and alias filters"
```

---

### Task 6: Duplicate jobs copy bouquets

**Files:**
- Modify: `app/Jobs/DuplicateCustomPlaylist.php` (inside the transaction, after the category-tag recreation loop ending ~line 67)
- Modify: `app/Jobs/DuplicatePlaylist.php` (inside its transaction, after the group-replication `foreach ($playlist->groups()->get() as $group)` block)
- Test: `tests/Feature/BouquetDuplicationTest.php`

**Interfaces:**
- Consumes: `Bouquet` model; the jobs' existing `$playlist` / `$newPlaylist` locals.
- Produces: duplicated playlists carry copies of the source's bouquets — `group_selections` verbatim, retargeted FK, **zero pivot rows** (the duplicate has no aliases; copying pivots would attach new-playlist bouquets to old-playlist aliases, violating the invariant — spec §6).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/BouquetDuplicationTest.php`:

```php
<?php

use App\Jobs\DuplicateCustomPlaylist;
use App\Jobs\DuplicatePlaylist;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

it('DuplicateCustomPlaylist copies bouquets without alias attachments', function () {
    $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id]);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $custom->id,
        'group_selections' => ['selected_groups' => ['My Group']],
    ]);
    $alias = PlaylistAlias::create([
        'name' => 'A', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
        'playlist_id' => null, 'custom_playlist_id' => $custom->id, 'xtream_config' => null,
    ]);
    $alias->bouquets()->attach($bouquet);

    (new DuplicateCustomPlaylist($custom, name: 'CP Copy'))->handle();

    $copy = CustomPlaylist::where('name', 'CP Copy')->firstOrFail();
    $copiedBouquet = Bouquet::where('custom_playlist_id', $copy->id)->firstOrFail();

    expect($copiedBouquet->group_selections)->toBe(['selected_groups' => ['My Group']])
        ->and($copiedBouquet->playlist_id)->toBeNull()
        ->and(DB::table('bouquet_playlist_alias')->where('bouquet_id', $copiedBouquet->id)->count())->toBe(0)
        // Source attachments and selections untouched.
        ->and($alias->bouquets()->count())->toBe(1)
        ->and($bouquet->refresh()->custom_playlist_id)->toBe($custom->id);
});

it('DuplicatePlaylist copies standard-target bouquets', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_selections' => ['selected_groups' => ['Sports'], 'selected_vod_groups' => ['Movies']],
        'auto_include_new_live' => true,
    ]);

    // Check DuplicatePlaylist's constructor signature and required setup before
    // dispatching (read the job first); call handle() synchronously like above.
    (new DuplicatePlaylist($playlist, name: 'P Copy'))->handle();

    $copy = Playlist::where('name', 'P Copy')->firstOrFail();
    $copiedBouquet = Bouquet::where('playlist_id', $copy->id)->firstOrFail();

    expect($copiedBouquet->getSelectedLiveGroupNames())->toBe(['Sports'])
        ->and($copiedBouquet->getSelectedVodGroupNames())->toBe(['Movies'])
        ->and($copiedBouquet->auto_include_new_live)->toBeTrue();
});
```

Read both job files first: confirm constructor signatures and whether `handle()` runs synchronously without queue infrastructure (both use `Queueable` and are invoked with `->handle()` in the pattern above). Adjust the second test's construction to the real signature — if `DuplicatePlaylist` takes different parameters, keep the assertion block and fix only the arrange step.

- [ ] **Step 2: Run to verify failure**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetDuplicationTest.php`
Expected: FAIL — no copied bouquets found.

- [ ] **Step 3: Add the copy blocks**

In `app/Jobs/DuplicateCustomPlaylist.php` (import `App\Models\Bouquet`), insert after the category-tag recreation loop (after line ~67, before the channel-links chunk):

```php
            // Copy the playlist's bouquets. Tag names are recreated byte-identical
            // under the new UUID, so the name-based selections copy verbatim. Alias
            // attachments are deliberately NOT copied: the duplicate has no aliases,
            // and attaching its bouquets to the source's aliases would violate the
            // same-target invariant.
            Bouquet::where('custom_playlist_id', $playlist->id)
                ->cursor()
                ->each(function (Bouquet $bouquet) use ($newPlaylist, $now): void {
                    $copy = $bouquet->replicate(except: ['id']);
                    $copy->custom_playlist_id = $newPlaylist->id;
                    $copy->created_at = $now;
                    $copy->updated_at = $now;
                    $copy->saveQuietly();
                });
```

In `app/Jobs/DuplicatePlaylist.php` (import `App\Models\Bouquet`), insert after the group-replication `foreach` block, adapting the local variable names to that file (its new-playlist variable and timestamp local — read the surrounding code):

```php
            // Copy the playlist's bouquets (groups replicate with identical names,
            // so the name-based selections stay valid). No alias attachments.
            Bouquet::where('playlist_id', $playlist->id)
                ->cursor()
                ->each(function (Bouquet $bouquet) use ($newPlaylist): void {
                    $copy = $bouquet->replicate(except: ['id']);
                    $copy->playlist_id = $newPlaylist->id;
                    $copy->saveQuietly();
                });
```

- [ ] **Step 4: Run tests**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetDuplicationTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add app/Jobs/DuplicateCustomPlaylist.php app/Jobs/DuplicatePlaylist.php tests/Feature/BouquetDuplicationTest.php
git commit -m "feat: copy bouquets when duplicating playlists and custom playlists"
```

---

### Task 7: BouquetResource, policy, picker builders

**Files:**
- Create: `app/Filament/Forms/Components/SourceGroupModalSelect.php`
- Create: `app/Filament/Forms/Components/CustomPlaylistGroupModalSelect.php`
- Create: `app/Policies/BouquetPolicy.php`
- Create: `app/Filament/Resources/Bouquets/BouquetResource.php`
- Create: `app/Filament/Resources/Bouquets/Pages/ListBouquets.php`
- Modify: `app/Models/Bouquet.php` (add `staleSelectionsByKey()`, `staleSelectionNames()`, `removeStaleSelectionNames()`)
- Modify: `app/Filament/Resources/PlaylistAuths/PlaylistAuthResource.php:77` (nav sort 6 → 7)
- Modify: `app/Filament/Resources/StreamFileSettings/StreamFileSettingResource.php:71` (nav sort 7 → 8)
- Test: `tests/Feature/BouquetResourceTest.php`

**Interfaces:**
- Consumes: Task 1-2 model; existing table configs `SourceGroupsTable`, `SourceCategoriesTable`, `CustomPlaylistGroupsTable`, `CustomPlaylistCategoriesTable`; `SourceGroup::displayLabelsForIds(int $playlistId, string $type, array $ids): array`.
- Produces: `SourceGroupModalSelect::make(string $statePath, string $type): ModalTableSelect` (`$type`: `'live'|'vod'|'categories'`; reads sibling `playlist_id` form field / record attribute; ID↔name round-trip; never-silently-shrink dehydration) and `CustomPlaylistGroupModalSelect::make(string $statePath, string $type): ModalTableSelect` (name-keyed, reads `custom_playlist_id`). `Bouquet::staleSelectionNames(): array` / `removeStaleSelectionNames(): void`. A working "Playlist Bouquets" resource at nav sort 6.

Filament testing note: before writing the Livewire tests, use the Boost `search-docs` tool for "test resource table action" and "ModalTableSelect" if any call pattern is unclear; mirror `tests/Feature/PlaylistAliasLiveGroupSortTest.php` for page-mounting idioms.

- [ ] **Step 1: Add the staleness methods to Bouquet (with tests)**

Append to `tests/Feature/BouquetModelTest.php`:

```php
describe('stale selection detection', function () {
    it('reports and removes names that no longer resolve for a standard target', function () {
        \App\Models\SourceGroup::create([
            'name' => 'Alive', 'playlist_id' => $this->playlist->id, 'source_group_id' => 1, 'type' => 'live',
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => [
                'selected_groups' => ['Alive', 'Gone'],
                'selected_vod_groups' => ['Alive'],  // vod namespace: 'Alive' has no vod SourceGroup -> stale there
            ],
        ]);

        expect($bouquet->staleSelectionNames())->toEqualCanonicalizing(['Gone', 'Alive']);

        $bouquet->removeStaleSelectionNames();
        $bouquet->refresh();

        expect($bouquet->getSelectedLiveGroupNames())->toBe(['Alive'])
            ->and($bouquet->getSelectedVodGroupNames())->toBe([]);
    });
});
```

Implement in `app/Models/Bouquet.php`:

```php
    /**
     * Stored names that no longer resolve to a selectable group/category on the
     * target playlist, per selection key. Provider churn (standard targets) or
     * tag deletion/re-tagging (custom targets) makes entries stale; they are
     * kept, never auto-pruned — this powers the UI staleness callout and the
     * explicit cleanup action only.
     *
     * @return array<string, array<string>>
     */
    public function staleSelectionsByKey(): array
    {
        $selections = $this->group_selections ?? [];
        $stale = [];

        $resolve = function (string $key, callable $resolvableFor) use ($selections, &$stale): void {
            $names = $selections[$key] ?? [];
            if (empty($names)) {
                return;
            }
            $missing = array_values(array_diff($names, $resolvableFor($names)));
            if (! empty($missing)) {
                $stale[$key] = $missing;
            }
        };

        if ($this->playlist_id) {
            $resolve('selected_groups', fn (array $names) => SourceGroup::where('playlist_id', $this->playlist_id)
                ->where('type', 'live')->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_vod_groups', fn (array $names) => SourceGroup::where('playlist_id', $this->playlist_id)
                ->where('type', 'vod')->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_categories', fn (array $names) => SourceCategory::where('playlist_id', $this->playlist_id)
                ->whereIn('name', $names)->pluck('name')->all());
        } elseif ($this->custom_playlist_id && $this->customPlaylist) {
            $resolve('selected_groups', fn (array $names) => $this->customPlaylist->filterableGroupsQuery(false)
                ->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_vod_groups', fn (array $names) => $this->customPlaylist->filterableGroupsQuery(true)
                ->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_categories', fn (array $names) => $this->customPlaylist->filterableCategoriesQuery()
                ->whereIn('name', $names)->pluck('name')->all());
        }

        return $stale;
    }

    /**
     * Flattened unique stale names for display.
     *
     * @return array<string>
     */
    public function staleSelectionNames(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->staleSelectionsByKey()) ?: [[]])));
    }

    /**
     * Remove stale entries per key (a name stale for live but valid for VOD is
     * only removed from the live list).
     */
    public function removeStaleSelectionNames(): void
    {
        $staleByKey = $this->staleSelectionsByKey();
        if ($staleByKey === []) {
            return;
        }

        $selections = $this->group_selections ?? [];
        foreach ($staleByKey as $key => $staleNames) {
            $selections[$key] = array_values(array_diff($selections[$key] ?? [], $staleNames));
        }

        $this->update(['group_selections' => $selections]);
    }
```

Add `use App\Models\SourceCategory;` / `use App\Models\SourceGroup;` imports (same-namespace models need no import — they are all in `App\Models`; skip the use lines).

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetModelTest.php` → PASS. Commit: `git add -A app/Models/Bouquet.php tests/Feature/BouquetModelTest.php && git commit -m "feat: bouquet stale-selection detection and cleanup"`.

- [ ] **Step 2: Create the picker builders**

`app/Filament/Forms/Components/SourceGroupModalSelect.php`:

```php
<?php

namespace App\Filament\Forms\Components;

use App\Filament\Tables\SourceCategoriesTable;
use App\Filament\Tables\SourceGroupsTable;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Standard-playlist group/category picker for bouquet selections.
 *
 * Wraps the ModalTableSelect ID<->name round-trip that group_filter-style name
 * columns need: the backing tables are keyed by SourceGroup/SourceCategory IDs
 * (unstable across syncs), while the persisted state is provider-stable names.
 * Reads the playlist from the sibling `playlist_id` form field / record
 * attribute, and reads/writes the record's `group_selections` for the
 * never-silently-shrink merge — i.e. this builder is bouquet-form-scoped;
 * parameterize the stored-selection lookup before reusing it on the alias form.
 *
 * $type: 'live' | 'vod' | 'categories'.
 */
class SourceGroupModalSelect
{
    public static function make(string $statePath, string $type): ModalTableSelect
    {
        $isCategories = $type === 'categories';
        $selectionKey = substr($statePath, strrpos($statePath, '.') + 1);

        $selectLabel = match ($type) {
            'live' => __('Select live groups'),
            'vod' => __('Select VOD groups'),
            default => __('Select series categories'),
        };
        $modalHeading = match ($type) {
            'live' => __('Search live groups'),
            'vod' => __('Search VOD groups'),
            default => __('Search series categories'),
        };

        $sourceQuery = function (int $playlistId) use ($isCategories, $type) {
            return $isCategories
                ? SourceCategory::where('playlist_id', $playlistId)
                : SourceGroup::where('playlist_id', $playlistId)->where('type', $type);
        };

        return ModalTableSelect::make($statePath)
            ->tableConfiguration($isCategories ? SourceCategoriesTable::class : SourceGroupsTable::class)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => (bool) $get('playlist_id'))
            ->multiple()
            ->tableArguments(function (Get $get) use ($isCategories, $type, $statePath): array {
                $arguments = [
                    'playlist_id' => (int) $get('playlist_id'),
                    'selected' => $get($statePath) ?? [],
                ];
                if (! $isCategories) {
                    $arguments['type'] = $type;
                }

                return $arguments;
            })
            ->selectAction(
                fn (Action $action) => $action
                    ->label($selectLabel)
                    ->modalHeading($modalHeading)
                    ->modalSubmitActionLabel(__('Confirm selection'))
                    ->button(),
            )
            ->hintAction(
                Action::make('clear_'.str_replace('.', '_', $statePath))
                    ->label(__('Clear all'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(fn (Set $set) => $set($statePath, []))
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear selection'))
                    ->modalSubmitActionLabel(__('Clear'))
            )
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name ?? $record->name)
            ->getOptionLabelsUsing(function (array $values, $record, Get $get) use ($isCategories, $type): array {
                $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');
                if (! $playlistId) {
                    return [];
                }
                $ids = array_filter($values, fn ($value): bool => is_numeric($value));

                return $isCategories
                    ? SourceCategory::where('playlist_id', $playlistId)->whereIn('id', $ids)->pluck('name', 'id')->toArray()
                    : SourceGroup::displayLabelsForIds($playlistId, $type, $ids);
            })
            ->afterStateHydrated(function ($component, $state, $record) use ($sourceQuery): void {
                // Hidden twin components are still hydrated; bail out unless this is
                // a standard-target record with stored names to resolve.
                if (! $record?->playlist_id || ! is_array($state) || empty($state)) {
                    return;
                }
                if (is_string($state[0] ?? null)) {
                    $component->state(
                        $sourceQuery($record->playlist_id)->whereIn('name', $state)
                            ->pluck('id')->unique()->values()->toArray()
                    );
                }
            })
            ->dehydrateStateUsing(function ($state, $record, Get $get) use ($sourceQuery, $selectionKey): array {
                $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');
                $ids = is_array($state) ? array_values(array_filter($state, 'is_numeric')) : [];

                $names = ($ids === [] || ! $playlistId)
                    ? []
                    : $sourceQuery($playlistId)->whereIn('id', $ids)->pluck('name')->unique()->values()->all();

                // Never-silently-shrink: previously-stored names the hydrator could
                // not resolve (provider churn) are merged back in, unless the user
                // deliberately cleared a selection that still had live entries.
                $stored = $record?->group_selections[$selectionKey] ?? [];
                if (! empty($stored) && $playlistId) {
                    $resolvable = $sourceQuery($playlistId)->whereIn('name', $stored)->pluck('name')->all();
                    $stale = array_values(array_diff($stored, $resolvable));
                    if (! empty($stale) && (! empty($ids) || empty($resolvable))) {
                        $names = array_values(array_unique(array_merge($names, $stale)));
                    }
                }

                return $names;
            });
    }
}
```

`app/Filament/Forms/Components/CustomPlaylistGroupModalSelect.php`:

```php
<?php

namespace App\Filament\Forms\Components;

use App\Filament\Tables\CustomPlaylistCategoriesTable;
use App\Filament\Tables\CustomPlaylistGroupsTable;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Custom-playlist group/category picker for bouquet selections.
 *
 * The backing tables are keyed by NAME (tag names unioned with fallback provider
 * group names), which is exactly what group_selections stores — so unlike the
 * standard-target sibling there is no ID<->name round-trip and nothing can be
 * silently dropped on save. Reads the sibling `custom_playlist_id` field.
 *
 * $type: 'live' | 'vod' | 'categories'.
 */
class CustomPlaylistGroupModalSelect
{
    public static function make(string $statePath, string $type): ModalTableSelect
    {
        $isCategories = $type === 'categories';

        $selectLabel = match ($type) {
            'live' => __('Select live groups'),
            'vod' => __('Select VOD groups'),
            default => __('Select series categories'),
        };
        $modalHeading = match ($type) {
            'live' => __('Search live groups'),
            'vod' => __('Search VOD groups'),
            default => __('Search series categories'),
        };

        return ModalTableSelect::make($statePath)
            ->tableConfiguration($isCategories ? CustomPlaylistCategoriesTable::class : CustomPlaylistGroupsTable::class)
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => (bool) $get('custom_playlist_id'))
            ->multiple()
            ->tableArguments(function (Get $get) use ($isCategories, $type): array {
                $arguments = ['custom_playlist_id' => (int) $get('custom_playlist_id')];
                if (! $isCategories) {
                    $arguments['type'] = $type;
                }

                return $arguments;
            })
            ->selectAction(
                fn (Action $action) => $action
                    ->label($selectLabel)
                    ->modalHeading($modalHeading)
                    ->modalDescription(__('Includes groups you created in this custom playlist and the original source playlist groups.'))
                    ->modalSubmitActionLabel(__('Confirm selection'))
                    ->button(),
            )
            ->hintAction(
                Action::make('clear_custom_'.str_replace('.', '_', $statePath))
                    ->label(__('Clear all'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->action(fn (Set $set) => $set($statePath, []))
                    ->requiresConfirmation()
                    ->modalHeading(__('Clear selection'))
                    ->modalSubmitActionLabel(__('Clear'))
            )
            ->getOptionLabelFromRecordUsing(fn ($record): string => $record->name)
            ->getOptionLabelsUsing(fn (array $values): array => array_combine($values, $values));
    }
}
```

- [ ] **Step 3: Create the policy**

`app/Policies/BouquetPolicy.php` (mirror `CustomPlaylistPolicy` — bouquets carry their own `user_id`):

```php
<?php

namespace App\Policies;

use App\Models\Bouquet;
use App\Models\User;

class BouquetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function delete(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function restore(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    public function forceDelete(User $user, Bouquet $bouquet): bool
    {
        return $this->owns($user, $bouquet);
    }

    private function owns(User $user, Bouquet $bouquet): bool
    {
        return $user->isAdmin() || $user->id === $bouquet->user_id;
    }
}
```

- [ ] **Step 4: Create the resource and page**

`app/Filament/Resources/Bouquets/BouquetResource.php`:

```php
<?php

namespace App\Filament\Resources\Bouquets;

use App\Filament\Forms\Components\CustomPlaylistGroupModalSelect;
use App\Filament\Forms\Components\SourceGroupModalSelect;
use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Traits\HasUserFiltering;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class BouquetResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Bouquet::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getModelLabel(): string
    {
        return __('Playlist Bouquet');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Playlist Bouquets');
    }

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::getForm());
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->columnSpanFull()
                ->rules(fn (Get $get, ?Bouquet $record): array => [
                    Rule::unique('bouquets', 'name')
                        ->where('user_id', auth()->id())
                        ->where(fn ($query) => $get('custom_playlist_id')
                            ? $query->where('custom_playlist_id', $get('custom_playlist_id'))
                            : $query->where('playlist_id', $get('playlist_id')))
                        ->ignore($record?->id),
                ])
                ->helperText(__('A short name for this bouquet. Unique per playlist.')),
            Forms\Components\Textarea::make('description')
                ->columnSpanFull()
                ->helperText(__('Optional description for your reference.')),

            Schemas\Components\Fieldset::make(__('Target Playlist'))
                ->columnSpanFull()
                ->schema([
                    // Same UI-only type+id pattern as the alias form (minus merged):
                    // the hidden FK fields are the persisted state.
                    Forms\Components\Select::make('target_type')
                        ->label(__('Playlist type'))
                        ->options([
                            'playlist' => __('Standard Playlist'),
                            'custom_playlist' => __('Custom Playlist'),
                        ])
                        ->default('playlist')
                        ->selectablePlaceholder(false)
                        ->required()
                        ->dehydrated(false)
                        ->live()
                        ->disabledOn('edit')
                        ->formatStateUsing(fn (?Bouquet $record): string => $record?->custom_playlist_id !== null ? 'custom_playlist' : 'playlist')
                        ->afterStateUpdated(function (Set $set): void {
                            $set('target_id', null);
                            $set('playlist_id', null);
                            $set('custom_playlist_id', null);
                            self::resetSelections($set);
                        }),
                    Forms\Components\Select::make('target_id')
                        ->label(__('Playlist'))
                        ->options(function (Get $get): array {
                            $userId = auth()->id();

                            return $get('target_type') === 'custom_playlist'
                                ? CustomPlaylist::query()->where('user_id', $userId)->orderBy('name')->pluck('name', 'id')->all()
                                : Playlist::query()->where('user_id', $userId)->orderBy('name')->pluck('name', 'id')->all();
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->dehydrated(false)
                        ->live()
                        ->disabledOn('edit')
                        ->formatStateUsing(fn (?Bouquet $record): ?int => $record?->custom_playlist_id ?? $record?->playlist_id)
                        ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                            $id = $state ? (int) $state : null;
                            $isCustom = $get('target_type') === 'custom_playlist';
                            $set('playlist_id', $isCustom ? null : $id);
                            $set('custom_playlist_id', $isCustom ? $id : null);
                            self::resetSelections($set);
                        })
                        ->helperText(__('The playlist cannot be changed after creation — the selected group names would not exist on another playlist. Create a new bouquet instead.')),
                    Forms\Components\Hidden::make('playlist_id'),
                    Forms\Components\Hidden::make('custom_playlist_id'),
                ]),

            Schemas\Components\Callout::make(__('Some saved entries are missing'))
                ->columnSpanFull()
                ->color('warning')
                ->visible(fn (?Bouquet $record): bool => $record !== null && $record->staleSelectionNames() !== [])
                ->description(fn (Bouquet $record): string => __('These saved entries are no longer selectable and are kept until they return or you remove them (use the clean up action on the bouquet list):')
                    .' '.implode(', ', $record->staleSelectionNames())),

            Schemas\Components\Fieldset::make(__('Live channel groups'))
                ->columnSpanFull()
                ->schema([
                    SourceGroupModalSelect::make('group_selections.selected_groups', 'live')
                        ->label(__('Live groups'))
                        ->helperText(__('Aliases using this bouquet will include live channels from these groups.')),
                    CustomPlaylistGroupModalSelect::make('group_selections.selected_groups', 'live')
                        ->label(__('Live groups'))
                        ->helperText(__('Aliases using this bouquet will include live channels from these groups.')),
                    Forms\Components\Toggle::make('auto_include_new_live')
                        ->label(__('Automatically include new live groups'))
                        ->default(false)
                        ->visible(fn (Get $get): bool => (bool) $get('playlist_id'))
                        ->helperText(__('Newly appearing live groups from the provider are automatically added to this bouquet on sync, in addition to the groups selected above.')),
                ]),

            Schemas\Components\Fieldset::make(__('VOD groups'))
                ->columnSpanFull()
                ->schema([
                    SourceGroupModalSelect::make('group_selections.selected_vod_groups', 'vod')
                        ->label(__('VOD groups'))
                        ->helperText(__('Aliases using this bouquet will include VOD channels from these groups.')),
                    CustomPlaylistGroupModalSelect::make('group_selections.selected_vod_groups', 'vod')
                        ->label(__('VOD groups'))
                        ->helperText(__('Aliases using this bouquet will include VOD channels from these groups.')),
                    Forms\Components\Toggle::make('auto_include_new_vod')
                        ->label(__('Automatically include new VOD groups'))
                        ->default(false)
                        ->visible(fn (Get $get): bool => (bool) $get('playlist_id'))
                        ->helperText(__('Newly appearing VOD groups from the provider are automatically added to this bouquet on sync, in addition to the groups selected above.')),
                ]),

            Schemas\Components\Fieldset::make(__('Series categories'))
                ->columnSpanFull()
                ->schema([
                    SourceGroupModalSelect::make('group_selections.selected_categories', 'categories')
                        ->label(__('Series categories'))
                        ->helperText(__('Aliases using this bouquet will include series from these categories.')),
                    CustomPlaylistGroupModalSelect::make('group_selections.selected_categories', 'categories')
                        ->label(__('Series categories'))
                        ->helperText(__('Aliases using this bouquet will include series from these categories.')),
                ]),
        ];
    }

    protected static function resetSelections(Set $set): void
    {
        $set('group_selections.selected_groups', []);
        $set('group_selections.selected_vod_groups', []);
        $set('group_selections.selected_categories', []);
        $set('auto_include_new_live', false);
        $set('auto_include_new_vod', false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['playlist', 'customPlaylist'])
                ->withCount('playlistAliases'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->description(fn (Bouquet $record): string => $record->description ?? '')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('target')
                    ->label(__('Playlist'))
                    ->getStateUsing(fn (Bouquet $record): string => $record->playlist_id
                        ? ($record->playlist?->name ?? 'N/A').' ('.__('Playlist').')'
                        : ($record->customPlaylist?->name ?? 'N/A').' ('.__('Custom Playlist').')')
                    ->url(fn (Bouquet $record): ?string => $record->playlist_id
                        ? ($record->playlist ? PlaylistResource::getUrl('edit', ['record' => $record->playlist_id]) : null)
                        : ($record->customPlaylist ? CustomPlaylistResource::getUrl('edit', ['record' => $record->custom_playlist_id]) : null)),
                Tables\Columns\TextColumn::make('selection_counts')
                    ->label(__('Live / VOD / Series'))
                    ->getStateUsing(fn (Bouquet $record): string => count($record->getSelectedLiveGroupNames())
                        .' / '.count($record->getSelectedVodGroupNames())
                        .' / '.count($record->getSelectedCategoryNames())),
                Tables\Columns\TextColumn::make('playlist_aliases_count')
                    ->label(__('Aliases'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                \Filament\Actions\Action::make('clean_up_missing')
                    ->label(__('Clean up missing'))
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (Bouquet $record): bool => $record->staleSelectionNames() !== [])
                    ->requiresConfirmation()
                    ->modalDescription(fn (Bouquet $record): string => __('Remove these entries that are no longer selectable?')
                        .' '.implode(', ', $record->staleSelectionNames()))
                    ->action(function (Bouquet $record): void {
                        $record->removeStaleSelectionNames();
                        Notification::make()->success()->title(__('Missing entries removed'))->send();
                    }),
                DeleteAction::make()
                    ->modalDescription(function (Bouquet $record): string {
                        $names = $record->playlistAliases()->pluck('name')->all();

                        return empty($names)
                            ? __('This bouquet is not assigned to any aliases.')
                            : __('This bouquet is assigned to the following aliases; deleting it removes its groups from their filters:').' '.implode(', ', $names);
                    }),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->modalDescription(__('Assigned aliases lose these bouquets\' groups from their filters.')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBouquets::route('/'),
        ];
    }
}
```

`app/Filament/Resources/Bouquets/Pages/ListBouquets.php`:

```php
<?php

namespace App\Filament\Resources\Bouquets\Pages;

use App\Filament\Resources\Bouquets\BouquetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListBouquets extends ListRecords
{
    protected static string $resource = BouquetResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Create reusable selections of a playlist\'s groups, then assign them to playlist aliases. An alias delivers the union of its assigned bouquets and its own manual group selections.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->slideOver(),
        ];
    }
}
```

Nav bumps: change `PlaylistAuthResource::getNavigationSort()` return from `6` to `7`, and `StreamFileSettingResource::getNavigationSort()` from `7` to `8`.

- [ ] **Step 5: Write the resource tests**

Create `tests/Feature/BouquetResourceTest.php`:

```php
<?php

use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Models\Bouquet;
use App\Models\Playlist;
use App\Models\SourceGroup;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('lists only the current user\'s bouquets', function () {
    $mine = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $theirs = Bouquet::factory()->create(['user_id' => $otherUser->id, 'playlist_id' => $otherPlaylist->id]);

    livewire(ListBouquets::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates a standard-target bouquet persisting NAMES from picker IDs', function () {
    $sports = SourceGroup::create([
        'name' => 'Sports', 'playlist_id' => $this->playlist->id, 'source_group_id' => 1, 'type' => 'live',
    ]);

    livewire(ListBouquets::class)
        ->callAction('create', data: [
            'name' => 'My Bouquet',
            'target_type' => 'playlist',
            'target_id' => $this->playlist->id,
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => null,
            'group_selections' => ['selected_groups' => [$sports->id]],
        ])
        ->assertHasNoActionErrors();

    $bouquet = Bouquet::where('name', 'My Bouquet')->firstOrFail();
    expect($bouquet->playlist_id)->toBe($this->playlist->id)
        ->and($bouquet->getSelectedLiveGroupNames())->toBe(['Sports']);
});

it('rejects a duplicate name on the same playlist', function () {
    Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'name' => 'Dup']);

    livewire(ListBouquets::class)
        ->callAction('create', data: [
            'name' => 'Dup',
            'target_type' => 'playlist',
            'target_id' => $this->playlist->id,
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => null,
        ])
        ->assertHasActionErrors(['name']);
});

it('preserves a stale stored name across an edit save (never-silently-shrink)', function () {
    SourceGroup::create([
        'name' => 'Alive', 'playlist_id' => $this->playlist->id, 'source_group_id' => 1, 'type' => 'live',
    ]);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Alive', 'Vanished']],
    ]);

    livewire(ListBouquets::class)
        ->callTableAction('edit', $bouquet, data: [
            'name' => $bouquet->name,
        ])
        ->assertHasNoTableActionErrors();

    expect($bouquet->refresh()->getSelectedLiveGroupNames())
        ->toEqualCanonicalizing(['Alive', 'Vanished']);
});

it('cleans up stale names via the table action', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Vanished']],
    ]);

    livewire(ListBouquets::class)
        ->callTableAction('clean_up_missing', $bouquet);

    expect($bouquet->refresh()->getSelectedLiveGroupNames())->toBe([]);
});
```

API notes for the implementer: Filament v5 test helpers for header/table actions vary between `callAction`/`callTableAction` and `TestAction::make(...)->table(...)` — if a call errors, check the exact idiom with Boost `search-docs` ("table actions test") and against `tests/Feature/PlaylistAliasLiveGroupSortTest.php`. The assertions (persisted names, uniqueness error, stale preservation/cleanup) are the contract; the invocation syntax may be adjusted.

- [ ] **Step 6: Run tests, fix, verify nav**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetResourceTest.php`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add -A app/Filament app/Policies/BouquetPolicy.php app/Models/Bouquet.php tests/Feature/BouquetResourceTest.php app/Filament/Resources/PlaylistAuths/PlaylistAuthResource.php app/Filament/Resources/StreamFileSettings/StreamFileSettingResource.php
git commit -m "feat: BouquetResource with target-aware pickers, policy, and staleness tooling"
```

---

### Task 8: Alias form integration and R1 guards

**Files:**
- Modify: `app/Filament/Resources/PlaylistAliases/PlaylistAliasResource.php`:
  - table eager-load (~line 98) + new badge column after `alias_of` (~line 135)
  - Channel Filter fieldset (~line 696): insert Bouquets fieldset as the first schema element
  - the six pickers' `tableArguments` closures (lines ~711, ~795, ~889, ~955, ~991, ~1061): add `bouquet_group_names`
  - the sort Repeater `helperText` (~line 867)
  - `resetGroupFilter()` (~line 1110): clear bouquets
  - new `bouquetContributedNames()` helper
- Modify: `app/Filament/Tables/SourceGroupsTable.php`, `app/Filament/Tables/SourceCategoriesTable.php`, `app/Filament/Tables/CustomPlaylistGroupsTable.php`, `app/Filament/Tables/CustomPlaylistCategoriesTable.php` (add the "In bouquet" indicator column)
- Test: `tests/Feature/PlaylistAliasBouquetTest.php`, extend `tests/Feature/PlaylistAliasCustomPlaylistFilterTest.php`

**Interfaces:**
- Consumes: `PlaylistAlias::bouquets()` relation; `Bouquet` model.
- Produces: alias form `Select::make('bouquets')` (relationship, multiple) visible for standard AND custom aliases; quick-create injecting the active FK; contribution callout; `bouquet_group_names` table argument convention consumed by all four table classes.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PlaylistAliasBouquetTest.php` (mirror the Livewire mounting pattern of `tests/Feature/PlaylistAliasLiveGroupSortTest.php` — read it first; if it mounts `EditPlaylistAlias` page class directly, do the same, otherwise use its list-page + action pattern):

```php
<?php

use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;

function makeFormAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Form Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('attaches and detaches bouquets through the alias form relationship', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $bouquet = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);

    // Direct relationship sync is what the Filament Select persists through.
    $alias->bouquets()->sync([$bouquet->id]);
    expect($alias->bouquets()->count())->toBe(1);

    $alias->bouquets()->sync([]);
    expect($alias->bouquets()->count())->toBe(0);
});

it('shows only same-target bouquets as options', function () {
    $alias = makeFormAlias($this->user, $this->playlist);
    $sameTarget = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherTarget = Bouquet::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $otherPlaylist->id]);
    $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id]);
    $customTarget = Bouquet::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => null, 'custom_playlist_id' => $custom->id,
    ]);

    // The options closure filters on the alias's active FK: replicate its query.
    $options = Bouquet::query()
        ->where('user_id', $this->user->id)
        ->where('playlist_id', $alias->playlist_id)
        ->pluck('id');

    expect($options)->toContain($sameTarget->id)
        ->and($options)->not->toContain($otherTarget->id)
        ->and($options)->not->toContain($customTarget->id);
});
```

Add Livewire form-level cases following the LiveGroupSortTest pattern (exact mounting idiom from that file):
1. Edit page for an alias with an attached bouquet `->assertSuccessful()` (this exercises every new closure: visibility, options, callout, `bouquet_group_names` arguments).
2. Setting the alias's `source_id` to a different playlist resets the `bouquets` form state to `[]` (assert via `assertSchemaStateSet` or the file's equivalent).

Then extend `tests/Feature/PlaylistAliasCustomPlaylistFilterTest.php` — locate its existing Livewire form-save regression (~line 555 and ~line 713 use the `fillForm`/save pattern) and add, following the same structure:

```php
it('does not materialize bouquet names into group_filter when the form is saved (R1 guard)', function () {
    // Arrange (use this file's existing helpers to build a custom-playlist alias
    // with a manual group_filter of ['selected_groups' => ['Manual Group']]):
    // attach a custom-target bouquet whose selections are ['selected_groups' => ['Bouquet Group']].
    // Act: open the edit form, change nothing, save (this file's established pattern).
    // Assert:
    expect($alias->refresh()->group_filter['selected_groups'])->toBe(['Manual Group']);
});
```

Write the arrange/act concretely using the helpers that file already defines (`makeCustomAlias`, `tagChannels`, its Livewire save idiom) — the comment lines above describe intent, the executor fills them with the file's own established calls. Add the standard-target twin of the same test to `PlaylistAliasBouquetTest.php` using the same save idiom.

- [ ] **Step 2: Run to verify failure**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasBouquetTest.php`
Expected: relationship cases pass already (Task 2); Livewire cases FAIL (no bouquets field on the form yet).

- [ ] **Step 3: Implement the alias form changes**

In `PlaylistAliasResource.php` (import `App\Models\Bouquet`):

3a. Eager-load + badge column. Change line ~98 to `$query->with(['playlist', 'customPlaylist', 'mergedPlaylist', 'bouquets']);` and add after the `alias_of` column:

```php
                Tables\Columns\TextColumn::make('bouquets.name')
                    ->label(__('Bouquets'))
                    ->badge()
                    ->limitList(2)
                    ->toggleable(),
```

3b. Insert as the FIRST element of the Channel Filter fieldset's `->schema([...])` (before the existing "What you can select" callout at ~line 697):

```php
                    Schemas\Components\Fieldset::make(__('Bouquets'))
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Select::make('bouquets')
                                ->label(__('Assigned bouquets'))
                                ->relationship(
                                    name: 'bouquets',
                                    titleAttribute: 'name',
                                    modifyQueryUsing: function (Builder $query, Get $get): Builder {
                                        $query->where('user_id', auth()->id());

                                        return $get('custom_playlist_id')
                                            ? $query->where('custom_playlist_id', (int) $get('custom_playlist_id'))
                                            : $query->where('playlist_id', (int) $get('playlist_id'));
                                    },
                                )
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->live()
                                ->columnSpanFull()
                                ->helperText(__('Channels are allowed if their group is in ANY assigned bouquet OR in the manual selections below. Bouquets and manual picks combine — assigning a bouquet never removes anything the manual pickers allow.'))
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('name')->required(),
                                    Forms\Components\Textarea::make('description'),
                                ])
                                ->createOptionUsing(function (array $data, Get $get): int {
                                    $bouquet = Bouquet::create([
                                        'name' => $data['name'],
                                        'description' => $data['description'] ?? null,
                                        'user_id' => auth()->id(),
                                        'playlist_id' => $get('custom_playlist_id') ? null : ((int) $get('playlist_id') ?: null),
                                        'custom_playlist_id' => $get('custom_playlist_id') ? (int) $get('custom_playlist_id') : null,
                                    ]);

                                    Notification::make()
                                        ->success()
                                        ->title(__('Bouquet created'))
                                        ->body(__('Select its groups under Playlist Bouquets.'))
                                        ->send();

                                    return $bouquet->getKey();
                                }),
                            Schemas\Components\Callout::make(__('Bouquet contributions'))
                                ->columnSpanFull()
                                ->visible(fn (Get $get): bool => ! empty($get('bouquets')))
                                ->description(function (Get $get): string {
                                    $bouquets = Bouquet::whereIn('id', (array) $get('bouquets'))->get();

                                    return __('Assigned bouquets contribute :live live groups, :vod VOD groups, and :series series categories in addition to your manual selections.', [
                                        'live' => $bouquets->flatMap(fn (Bouquet $bouquet) => $bouquet->getSelectedLiveGroupNames())->unique()->count(),
                                        'vod' => $bouquets->flatMap(fn (Bouquet $bouquet) => $bouquet->getSelectedVodGroupNames())->unique()->count(),
                                        'series' => $bouquets->flatMap(fn (Bouquet $bouquet) => $bouquet->getSelectedCategoryNames())->unique()->count(),
                                    ]);
                                }),
                        ]),
```

The whole Bouquets fieldset needs no extra `->visible()` — its parent Channel Filter fieldset is already hidden for merged aliases (line ~695), which is exactly the required gate.

3c. `resetGroupFilter()` — add `$set('bouquets', []);` as the first line of the method body.

3d. Add to each of the six pickers' `tableArguments` closures a `bouquet_group_names` entry. Example for the standard live picker (line ~711):

```php
                                ->tableArguments(fn (Get $get): array => [
                                    'playlist_id' => (int) $get('playlist_id'),
                                    'type' => 'live',
                                    'selected' => $get('group_filter.selected_groups') ?? [],
                                    'bouquet_group_names' => self::bouquetContributedNames($get, 'live'),
                                ])
```

Use type `'vod'` for the two VOD pickers and `'categories'` for the two category pickers; the custom-variant closures keep their existing keys and just gain the new entry. Add the helper near `resetGroupFilter()`:

```php
    /**
     * Names contributed by the alias's currently selected bouquets, used by the
     * picker tables to badge rows already covered by a bouquet.
     *
     * @return array<string>
     */
    protected static function bouquetContributedNames(Get $get, string $type): array
    {
        $bouquetIds = (array) $get('bouquets');
        if (empty($bouquetIds)) {
            return [];
        }

        $method = match ($type) {
            'vod' => 'getSelectedVodGroupNames',
            'categories' => 'getSelectedCategoryNames',
            default => 'getSelectedLiveGroupNames',
        };

        return Bouquet::whereIn('id', $bouquetIds)
            ->get()
            ->flatMap(fn (Bouquet $bouquet): array => $bouquet->{$method}())
            ->unique()
            ->values()
            ->all();
    }
```

3e. Sort Repeater helper text (~line 867) — replace the string with:

```php
->helperText(__('Drag the groups into the order you want them delivered to the client. Groups contributed by bouquets that are not listed here are appended in source-playlist order.'))
```

3f. Indicator column in the four table classes. In `SourceGroupsTable::configure()` append to the `->columns([...])` array (arrow functions capture `$table` automatically):

```php
                Tables\Columns\IconColumn::make('in_bouquet')
                    ->label(__('In bouquet'))
                    ->visible(fn (): bool => ! empty($table->getArguments()['bouquet_group_names'] ?? []))
                    ->state(fn ($record): bool => in_array($record->name, $table->getArguments()['bouquet_group_names'] ?? [], true))
                    ->boolean(),
```

(Adjust the import style to each file — `SourceGroupsTable` imports `Filament\Tables\Columns\TextColumn` directly, so add `use Filament\Tables\Columns\IconColumn;` and use `IconColumn::make(...)`.) Repeat identically in `SourceCategoriesTable`, `CustomPlaylistGroupsTable`, `CustomPlaylistCategoriesTable` — all four key rows by `name`, which is the stored representation, so exact `in_array` matching is correct.

- [ ] **Step 4: Run tests**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasBouquetTest.php`
Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasCustomPlaylistFilterTest.php`
Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasLiveGroupSortTest.php`
Expected: ALL PASS (the last two prove no regression in the form's existing behavior).

- [ ] **Step 5: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/PlaylistAliases/PlaylistAliasResource.php app/Filament/Tables tests/Feature/PlaylistAliasBouquetTest.php tests/Feature/PlaylistAliasCustomPlaylistFilterTest.php
git commit -m "feat: bouquet assignment on the alias form with contribution callout and picker badges"
```

---

### Task 9: Add-to-Bouquet actions on the group resources

**Files:**
- Modify: `app/Services/PlaylistService.php` (add `getAddToBouquetSchema()`, `getAddGroupsToBouquetBulkAction()`, `getAddGroupsToBouquetAction()`, `addGroupRecordsToBouquet()` — place next to the existing `getAddGroupsToPlaylist*` pair at ~line 1656)
- Modify: `app/Filament/Resources/Groups/GroupResource.php` (row ActionGroup ~line 218, bulk ~line 440), `app/Filament/Resources/VodGroups/VodGroupResource.php` (~219, ~501), `app/Filament/Resources/Categories/CategoryResource.php` (~240, ~387), plus the three Edit pages' header actions (`Groups/Pages/EditGroup.php:27`, `VodGroups/Pages/EditVodGroup.php:30`, `Categories/Pages/EditCategory.php:27`)
- Test: `tests/Feature/GroupBouquetBulkActionTest.php`

**Interfaces:**
- Consumes: `Bouquet` model; `Group`/`Category` records carrying `playlist_id`, `name_internal` (groups) / `name`.
- Produces: `PlaylistService::getAddGroupsToBouquetBulkAction(string $name = 'add_to_bouquet', string $type = 'live'): BulkAction` and `getAddGroupsToBouquetAction(string $name = 'add_to_bouquet', string $type = 'live'): Action`. `$type ∈ {'live','vod','category'}` selects the `group_selections` key.
- **Deviation from spec:** the modal has no inline quick-create — a bulk action's schema cannot know the selection's playlist before submission, so a quick-created bouquet could not be given its required target. Options are labeled "Name (Playlist)" instead, and the action validates the playlist match at execution.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/GroupBouquetBulkActionTest.php`. Model-level coverage of the action handler (invoke the service method directly — it is a plain static):

```php
<?php

use App\Models\Bouquet;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\PlaylistService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('merges selected group internal names into the bouquet with dedupe', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Existing']],
    ]);
    $groups = collect([
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Sports', 'type' => 'live']),
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Existing', 'type' => 'live']),
    ]);

    PlaylistService::addGroupRecordsToBouquet($groups, $bouquet->id, 'live');

    expect($bouquet->refresh()->getSelectedLiveGroupNames())
        ->toEqualCanonicalizing(['Existing', 'Sports']);
});

it('aborts on a cross-playlist selection without writing', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => []],
    ]);
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $groups = collect([
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'A', 'type' => 'live']),
        Group::factory()->for($otherPlaylist)->for($this->user)->create(['name_internal' => 'B', 'type' => 'live']),
    ]);

    PlaylistService::addGroupRecordsToBouquet($groups, $bouquet->id, 'live');

    expect($bouquet->refresh()->getSelectedLiveGroupNames())->toBe([]);
});

it('writes vod and category keys per type', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
    $vodGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Movies', 'type' => 'vod']);

    PlaylistService::addGroupRecordsToBouquet(collect([$vodGroup]), $bouquet->id, 'vod');

    expect($bouquet->refresh()->getSelectedVodGroupNames())->toBe(['Movies'])
        ->and($bouquet->getSelectedLiveGroupNames())->toBe([]);
});
```

`Group::factory()` exists (used in `ProcessM3uImportReimportTest.php:70`). Filament notifications sent outside a Livewire request context are harmless in tests; if `Notification::make()->send()` errors without a session, start the test file with `$this->withSession([]);` in `beforeEach` or assert around it — check how existing service tests handle it (grep `Notification::make` in `tests/`).

- [ ] **Step 2: Run to verify failure**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/GroupBouquetBulkActionTest.php`
Expected: FAIL — method does not exist.

- [ ] **Step 3: Implement in PlaylistService**

Add (import `App\Models\Bouquet` at the top of the file):

```php
    /**
     * Schema for the Add-to-Bouquet group/category actions: one bouquet select
     * scoped to the user's standard-target bouquets. No inline create — a bulk
     * selection's playlist is unknown until the action runs, so a quick-created
     * bouquet could not be targeted; the action validates the match instead.
     *
     * @return array<int, \Filament\Forms\Components\Field>
     */
    public static function getAddToBouquetSchema(): array
    {
        return [
            Select::make('bouquet')
                ->label(__('Bouquet'))
                ->required()
                ->searchable()
                ->options(fn (): array => Bouquet::query()
                    ->where('user_id', auth()->id())
                    ->whereNotNull('playlist_id')
                    ->with('playlist')
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Bouquet $bouquet): array => [
                        $bouquet->id => $bouquet->name.' ('.($bouquet->playlist?->name ?? 'N/A').')',
                    ])
                    ->all())
                ->helperText(__('Bouquets are scoped to a single playlist; the selected groups must belong to it. Create bouquets under Playlist Bouquets.')),
        ];
    }

    /**
     * Merge the selected Group/Category records' provider-stable names into a
     * bouquet's selections. Public static so tests can exercise the handler
     * directly. $type: 'live' | 'vod' | 'category'.
     */
    public static function addGroupRecordsToBouquet(Collection $records, int $bouquetId, string $type): void
    {
        $bouquet = Bouquet::where('user_id', auth()->id())->find($bouquetId);
        if (! $bouquet || ! $bouquet->playlist_id) {
            Notification::make()->danger()->title(__('Bouquet not found'))->send();

            return;
        }

        $playlistIds = $records->pluck('playlist_id')->unique();
        if ($playlistIds->count() !== 1 || (int) $playlistIds->first() !== $bouquet->playlist_id) {
            Notification::make()
                ->danger()
                ->title(__('Playlist mismatch'))
                ->body(__('Bouquets are scoped to a single playlist. Select groups from the bouquet\'s own playlist only.'))
                ->send();

            return;
        }

        $key = match ($type) {
            'vod' => 'selected_vod_groups',
            'category' => 'selected_categories',
            default => 'selected_groups',
        };

        $names = $records
            ->map(fn ($record) => $record->name_internal ?? $record->name)
            ->filter()
            ->unique()
            ->values();

        $selections = $bouquet->group_selections ?? [];
        $current = $selections[$key] ?? [];
        $updated = array_values(array_unique(array_merge($current, $names->all())));
        $added = count($updated) - count($current);
        $selections[$key] = $updated;
        $bouquet->update(['group_selections' => $selections]);

        Notification::make()
            ->success()
            ->title(__('Added to bouquet'))
            ->body(__(':added added, :existing already present.', [
                'added' => $added,
                'existing' => $names->count() - $added,
            ]))
            ->send();
    }

    public static function getAddGroupsToBouquetBulkAction(string $name = 'add_to_bouquet', string $type = 'live'): BulkAction
    {
        return BulkAction::make($name)
            ->label(__('Add to Bouquet'))
            ->schema(self::getAddToBouquetSchema())
            ->action(fn (Collection $records, array $data) => self::addGroupRecordsToBouquet($records, (int) $data['bouquet'], $type))
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->icon('heroicon-o-rectangle-stack')
            ->modalIcon('heroicon-o-rectangle-stack')
            ->modalDescription(__('Add the selected group(s) to the chosen bouquet.'))
            ->modalSubmitActionLabel(__('Add now'));
    }

    public static function getAddGroupsToBouquetAction(string $name = 'add_to_bouquet', string $type = 'live'): Action
    {
        return Action::make($name)
            ->label(__('Add to Bouquet'))
            ->schema(self::getAddToBouquetSchema())
            ->action(fn ($record, array $data) => self::addGroupRecordsToBouquet(collect([$record]), (int) $data['bouquet'], $type))
            ->requiresConfirmation()
            ->icon('heroicon-o-rectangle-stack')
            ->modalIcon('heroicon-o-rectangle-stack')
            ->modalDescription(__('Add this group to the chosen bouquet.'))
            ->modalSubmitActionLabel(__('Add now'));
    }
```

Match this file's existing imports (`Select`, `Notification`, `BulkAction`, `Action`, `Collection` are already used by the sibling methods).

- [ ] **Step 4: Wire into the six surfaces**

In each resource, add the new action directly after the existing "Add to Custom Playlist" call in the same `ActionGroup`:
- `GroupResource.php` row (~218): `PlaylistService::getAddGroupsToBouquetAction(type: 'live'),` — bulk (~440): `PlaylistService::getAddGroupsToBouquetBulkAction(type: 'live'),`
- `VodGroupResource.php` (~219 / ~501): `type: 'vod'`
- `CategoryResource.php` (~240 / ~387): `type: 'category'`
- The three Edit pages' header actions: same single-record action with the matching type.

- [ ] **Step 5: Run tests**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/GroupBouquetBulkActionTest.php`
Expected: PASS.

- [ ] **Step 6: Pint + commit**

```powershell
vendor/bin/pint --dirty --format agent
git add app/Services/PlaylistService.php app/Filament/Resources/Groups app/Filament/Resources/VodGroups app/Filament/Resources/Categories tests/Feature/GroupBouquetBulkActionTest.php
git commit -m "feat: Add-to-Bouquet actions on group and category resources"
```

---

### Task 10: Downstream propagation coverage (Xtream, guest panel, sort)

**Files:**
- Test only — no production code. Extend: `tests/Feature/PlaylistAliasTest.php`, `tests/Feature/PlaylistAliasLiveGroupSortTest.php`, `tests/Feature/XtreamApiControllerTest.php` (or the nearest Xtream category test file — locate with `Grep "get_live_categories" tests/`), and create `tests/Feature/GuestPanelBouquetFilterTest.php` mirroring the harness in `tests/Feature/GuestBrowseShowsTest.php`.

**Interfaces:** none new — these tests pin that accessor-level resolution reaches every consumer without per-consumer code.

- [ ] **Step 1: PlaylistAliasTest additions**

Append a describe block using this file's existing `makeAlias` helper:

```php
describe('bouquet union end-to-end through channels() and series()', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('a bouquet-only alias filters channels without any manual filter', function () {
        $alias = makeAlias($this->user, $this->playlist, ['group_filter' => null]);
        $bouquet = \App\Models\Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Sports', 'is_vod' => false, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'News', 'is_vod' => false, 'enabled' => true,
        ]);

        $groups = $alias->channels()->pluck('channels.group_internal');
        expect($groups)->toContain('Sports')->and($groups)->not->toContain('News');
    });

    it('a bouquet category restricts series() via source_category_id resolution', function () {
        $alias = makeAlias($this->user, $this->playlist, ['group_filter' => null]);
        $bouquet = \App\Models\Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_categories' => ['Drama']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        SourceCategory::create([
            'playlist_id' => $this->playlist->id, 'name' => 'Drama', 'source_category_id' => 11,
        ]);
        Series::factory()->for($this->playlist)->for($this->user)->create(['source_category_id' => 11, 'enabled' => true]);
        Series::factory()->for($this->playlist)->for($this->user)->create(['source_category_id' => 22, 'enabled' => true]);

        expect($alias->series()->count())->toBe(1);
    });
});
```

Adapt the `Series::factory()` arrange to this file's existing series-filter tests (search the file for `source_category_id` and copy the exact arrange pattern, including any required columns).

- [ ] **Step 2: Live group sort ELSE-bucket case**

In `tests/Feature/PlaylistAliasLiveGroupSortTest.php`, add a case mirroring its existing ordered-output assertion: an alias with `sort_live_groups_custom => true`, `live_group_order => ['B Group']`, manual `selected_groups => ['B Group']`, plus a bouquet contributing `'A Group'`; assert the generated M3U/channel ordering yields `B Group` channels before `A Group` channels (bouquet-contributed groups rank in the CASE ELSE bucket after explicitly ordered ones). Copy the file's established generation/assertion helpers verbatim.

- [ ] **Step 3: Xtream category narrowing**

Locate the Xtream category-list tests (`Grep "get_live_categories" tests/ -l`). Add four cases in the file's established request style:
1. A bouquet-attached standard alias's `get_live_categories` response contains only the union's groups, and each returned `category_id` equals the same group's id from an unfiltered alias's response (no re-keying).
2. A bouquet whose names match nothing returns the `'all'` fallback exactly as an over-narrow manual filter does (copy the existing manual-filter fallback case's shape).
3. Dynamic-category suppression: a **bouquet-only** alias (no manual filter) suppresses TMDB dynamic categories exactly as a manual filter does — find the existing suppression test (`Grep "prependDynamicGroups\|dynamic" tests/ -l`, likely `XtreamDynamicCategoriesTest.php`) and add the bouquet-attached variant; also pin the inverse (bouquet-less alias keeps dynamic categories).
4. Stream-time rejection: a stream/series playback request through the alias for content OUTSIDE the union is rejected, and inside the union succeeds — copy the shape of the existing stream-time membership test in `XtreamApiControllerTest.php` / the stream controller tests (`Grep "getValidatedStreamFromPlaylist\|XtreamStreamController" tests/ -l`). If no such existing test exists, cover the same contract through `$alias->channels()->where('channels.id', $outOfUnionId)->exists()` being false — that is the exact query the controller runs.

- [ ] **Step 4: Guest panel**

Create `tests/Feature/GuestPanelBouquetFilterTest.php`: copy the beforeEach/session harness from `tests/Feature/GuestBrowseShowsTest.php` (playlist auth + guest session), then assert that the VOD listing for a bouquet-attached alias contains only union-permitted channels and the Series listing only union-permitted series. If the harness authenticates against a Playlist rather than a PlaylistAlias, adapt to whichever guest-auth path reaches an alias (see `GuestPanel/Resources/Vods/VodResource.php:111-133` for how the alias is resolved) — the assertion is the contract.

- [ ] **Step 5: Run all four files**

Run each modified/created test file with the herd-php command.
Expected: ALL PASS with zero production-code changes. If a case fails, that is a real integration gap — investigate the accessor path before touching any consumer (the design guarantees inheritance; a failure means the accessor change from Task 3 missed something).

- [ ] **Step 6: Commit**

```powershell
git add tests/Feature/PlaylistAliasTest.php tests/Feature/PlaylistAliasLiveGroupSortTest.php tests/Feature/GuestPanelBouquetFilterTest.php tests/Feature
git commit -m "test: pin bouquet propagation through Xtream, guest panel, and sort paths"
```

---

### Task 11: Language files, Pint sweep, security scan, final verification

**Files:**
- Modify: `lang/en.json` (add every new `__()` literal introduced by Tasks 7-9 as key = value)
- Test: full sweep of the new/extended files only

- [ ] **Step 1: Collect and add the new strings**

Grep the diff for new literals: `git diff dev --unified=0 -- app | Select-String "__\('"` and add each new string to `lang/en.json` (key and value identical). Include (non-exhaustive — the grep is authoritative): "Playlist Bouquet", "Playlist Bouquets", "A short name for this bouquet. Unique per playlist.", "Target Playlist", "The playlist cannot be changed after creation — the selected group names would not exist on another playlist. Create a new bouquet instead.", "Some saved entries are missing", "Live groups", "VOD groups", "Series categories", "Automatically include new live groups", "Automatically include new VOD groups", "Assigned bouquets", "Bouquet contributions", "Bouquet created", "Select its groups under Playlist Bouquets.", "Add to Bouquet", "Added to bouquet", "Playlist mismatch", "Clean up missing", "Missing entries removed", "In bouquet", the helper texts, and the modal descriptions.

- [ ] **Step 2: Run the lang merge tool**

Run: `& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan lang:merge-conflicts`
Expected: exits cleanly, re-sorts the files.

- [ ] **Step 3: Full new-test sweep (still scoped — never the whole suite)**

Run each of these once, all must PASS:

```powershell
& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetModelTest.php tests/Feature/PlaylistAliasBouquetResolutionTest.php tests/Feature/BouquetRenamePropagationTest.php tests/Feature/TagRenamePropagationTest.php tests/Feature/BouquetDuplicationTest.php
& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/BouquetResourceTest.php tests/Feature/PlaylistAliasBouquetTest.php tests/Feature/GroupBouquetBulkActionTest.php tests/Feature/GuestPanelBouquetFilterTest.php
& "C:\Users\Ethan\.config\herd\bin\php84\php.exe" artisan test --compact tests/Feature/PlaylistAliasTest.php tests/Feature/PlaylistAliasCustomPlaylistFilterTest.php tests/Feature/PlaylistAliasLiveGroupSortTest.php tests/Feature/EpgPlaylistAliasCacheInvalidationTest.php tests/Feature/AutoSyncGroupsToCustomPlaylistTest.php
```

- [ ] **Step 4: Security scan (pre-PR checklist)**

Run: `& "C:\Users\Ethan\.config\herd\bin\php.bat" artisan checkpoint:scan --no-interaction` plus `composer audit` / `npm audit --audit-level=high` via the Herd invocation pattern. The `dev` baseline already has 4 known FAIL buckets — only NEW findings block; note any in the PR description.

- [ ] **Step 5: Pint + final commit**

```powershell
vendor/bin/pint --dirty --format agent
git add lang/
git commit -m "chore: language entries for playlist bouquets"
```

- [ ] **Step 6: Verify the branch is PR-ready**

`git log --oneline dev..HEAD` should show the spec commit plus one commit per task. Do NOT open the PR — report ready-state to the user (PR targets `dev` when they say go).

---

## Spec-consistency notes (deviations already embedded above)

1. **No quick-create in the Add-to-Bouquet modals** (Task 9) — bulk selections have no known playlist pre-submit. Quick-create exists on the alias form only, as specced.
2. **Staleness cleanup is a table row action** ("Clean up missing"), not a callout-embedded action — Filament Callouts don't carry actions cleanly; the edit-form callout points at it.
3. **`live_group_order` rename propagation** (Task 4) is included in the companion fix for live-type renames — the spec's §6 covers `group_filter` wholesale; the order list lives inside it and would otherwise go stale.
4. **Sort-order names when saving through bouquet-contributed selections** stay manual-only per spec §5 rule 7 — the Repeater is untouched apart from helper text.
5. **`BouquetAutoIncludeTest` is folded into `BouquetRenamePropagationTest`** (Task 4) — both exercise the same `syncSourceGroupType()` reflection harness; a separate file would duplicate the setup. Coverage is identical to spec §9.
6. **PR description reminders** (for whoever opens the PR): note the `SyncPipelineService.php:480/:484` `$rule['group_filter']` name collision (unrelated field, deliberately untouched — spec §8), and flag the two companion fixes (provider-rename and tag-rename propagation into manual `group_filter`) as behavior fixes distinct from the feature.
