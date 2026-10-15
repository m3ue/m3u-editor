<?php

namespace App\Filament\Actions;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Jobs\SyncDynamicGroups;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\TmdbService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Create / Edit actions for Dynamic Group rules on the VOD / Series Dynamic
 * Groups listings and the shared View page. Both use the Playlist form's rule
 * schema (PlaylistResource::getDynamicGroupRuleSchema()), so the fields,
 * caching options and preview match the Playlist form's Dynamic Groups (TMDB)
 * repeater. Saving writes the rule into the playlist's
 * `dynamic_groups_config` and updates the DynamicGroup row synchronously so
 * the table reflects the change immediately. Saving never calls TMDB
 * itself: it queues a refresh (SyncDynamicGroups::queueRefresh()) that
 * fills in the group's members.
 */
class DynamicGroupRuleActions
{
    public static function create(string $type): CreateAction
    {
        $label = $type === 'series'
            ? __('Create Series Dynamic Group')
            : __('Create VOD Dynamic Group');

        return CreateAction::make()
            ->label($label)
            ->modalHeading($label)
            ->slideOver()
            ->modalWidth('4xl')
            ->schema(static::schema($type))
            ->using(function (array $data) use ($type): DynamicGroup {
                $playlist = Playlist::query()
                    ->where('user_id', Auth::id())
                    ->findOrFail($data['playlist_id']);

                return static::save($playlist, $type, $data);
            })
            ->successNotification(
                Notification::make()
                    ->success()
                    ->title(__('Dynamic Group created'))
                    ->body(__('Its members are being fetched from TMDB and will appear shortly.')),
            )
            ->successRedirectUrl(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]));
    }

    /**
     * Hidden for a row whose rule is no longer in the playlist config
     * (renamed or removed on the Playlist form and not re-synced yet): the
     * next sync deletes that row, so there is nothing to edit.
     */
    public static function edit(): EditAction
    {
        return EditAction::make()
            ->slideOver()
            ->modalWidth('4xl')
            ->modalHeading(fn (DynamicGroup $record): string => $record->type === 'series'
                ? __('Edit Series Dynamic Group')
                : __('Edit VOD Dynamic Group'))
            ->schema(fn (DynamicGroup $record): array => static::schema($record->type, editing: true))
            ->fillForm(function (DynamicGroup $record): array {
                $config = static::ruleConfigFor($record);

                return [
                    ...$config[static::ruleIndexFor($record, $config)],
                    'playlist_id' => $record->playlist_id,
                    'type' => $record->type,
                ];
            })
            ->using(function (DynamicGroup $record, array $data): DynamicGroup {
                // Saving needs the full playlist (its user_id, and the
                // playlist-updated hooks), unlike the partial eager load.
                static::save(Playlist::query()->findOrFail($record->playlist_id), $record->type, $data, $record);

                // Pick up what materializeRule() changed on its own instance.
                // Not refresh(): that reloads the eager-loaded playlist with
                // every column.
                $record->setRawAttributes($record->fresh()->getAttributes(), sync: true);

                return $record;
            })
            ->visible(fn (DynamicGroup $record): bool => static::ruleIndexFor($record) !== null);
    }

    /**
     * Playlist picker + the shared rule schema, with the rule's Content Type
     * locked to the listing's type. Keeping the type selectable would let a
     * user pick the other type's `source` options while the save still forces
     * this listing's type, corrupting the rule.
     *
     * @return array<int, mixed>
     */
    protected static function schema(string $type, bool $editing = false): array
    {
        return [
            Select::make('playlist_id')
                ->label(__('Playlist'))
                ->required()
                ->searchable()
                ->options(fn (?Model $record): array => $record instanceof DynamicGroup
                    ? [$record->playlist_id => $record->playlist?->name]
                    : Playlist::query()
                        ->where('user_id', Auth::id())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                ->default(fn ($livewire): ?int => filled($livewire->activeTab ?? null)
                    ? (int) $livewire->activeTab
                    : null)
                ->live()
                ->disabled($editing)
                ->helperText($editing ? null : __('The Dynamic Group rule will be appended to this playlist\'s Dynamic Groups (TMDB) configuration.')),
            Hidden::make('type')->default($type),
            Grid::make(10)
                ->schema(array_values(array_filter(
                    PlaylistResource::getDynamicGroupRuleSchema(useTenCol: true),
                    fn ($component): bool => ! ($component instanceof Field && $component->getName() === 'type'),
                ))),
        ];
    }

    /**
     * Write the form's rule into the playlist's `dynamic_groups_config`
     * (appended on create, replaced in place on edit) and materialize its
     * row. Every dehydrated field is kept, so a field added to the shared
     * rule schema is saved here without extra wiring.
     */
    protected static function save(Playlist $playlist, string $type, array $data, ?DynamicGroup $record = null): DynamicGroup
    {
        $rule = array_merge(Arr::except($data, ['playlist_id']), [
            'type' => $type,
            'name' => trim((string) ($data['name'] ?? '')),
        ]);

        $config = array_values($playlist->dynamic_groups_config ?? []);
        $index = $record !== null ? static::ruleIndexFor($record, $config) : null;

        if ($record !== null && $index === null) {
            static::fail(__('This group\'s rule is no longer in the playlist\'s Dynamic Groups (TMDB) configuration.'));
        }

        // A second rule with the same (type, source, name) would map onto the
        // same row. On edit, a rule-less row (removed or renamed on the
        // Playlist form, not re-synced yet) still holds that identity's
        // unique key, so renaming onto it is blocked too.
        $identity = DynamicGroup::ruleIdentity($rule);
        $duplicatesAnotherRule = collect($config)->contains(
            fn (array $existingRule, int $existingIndex): bool => $existingIndex !== $index
                && DynamicGroup::ruleIdentity($existingRule) === $identity,
        );
        $duplicatesAnotherRow = $record !== null && DynamicGroup::query()
            ->where('playlist_id', $playlist->id)
            ->where($identity)
            ->whereKeyNot($record->id)
            ->exists();

        if ($duplicatesAnotherRule || $duplicatesAnotherRow) {
            static::fail(__('This playlist already has a dynamic group with this name and source.'));
        }

        if ($index === null) {
            $config[] = $rule;
            $index = array_key_last($config);
        } else {
            $config[$index] = $rule;
        }

        $playlist->update(['dynamic_groups_config' => $config]);

        // Rename the row in place first, so materializeRule() finds it by the
        // rule's new (type, source, name) identity instead of creating a new
        // row. Keeping its id keeps the Xtream category id and the cached
        // download links attached to it.
        $record?->update(['source' => $rule['source'], 'name' => $rule['name']]);

        $group = (new SyncDynamicGroups($playlist->id))->materializeRule(
            $playlist,
            $type,
            (string) $rule['source'],
            $rule['name'],
            (array) ($rule['tmdb_params'] ?? []),
            $index,
            app(TmdbService::class),
            (bool) ($rule['enabled'] ?? false),
            refreshMembership: false,
        );

        SyncDynamicGroups::queueRefresh($playlist->id);

        // materializeRule() only returns null for a rule without a source or
        // name, which the form's required fields already rule out.
        return $group ?? static::fail(__('Select a content type and source first.'));
    }

    /**
     * Position of the row's rule in `$config`, which defaults to its
     * playlist's `dynamic_groups_config`.
     *
     * @param  array<int, array<string, mixed>>|null  $config
     */
    protected static function ruleIndexFor(DynamicGroup $record, ?array $config = null): ?int
    {
        foreach ($config ?? static::ruleConfigFor($record) as $index => $rule) {
            if ($record->matchesRule($rule)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The row's playlist rules, read from the playlist the listings and the
     * View page eager-load with only the columns they need. A full Playlist
     * row is never loaded here: its `xtream_status` accessor queues
     * UpdateXtreamStats on a cache miss whenever the model is serialized.
     * Falls back to reading just this column when the playlist was loaded
     * without it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function ruleConfigFor(DynamicGroup $record): array
    {
        $playlist = $record->relationLoaded('playlist') ? $record->playlist : null;

        $config = $playlist !== null && array_key_exists('dynamic_groups_config', $playlist->getAttributes())
            ? $playlist->dynamic_groups_config
            : Playlist::query()->whereKey($record->playlist_id)->value('dynamic_groups_config');

        return array_values($config ?? []);
    }

    /**
     * Halt is caught silently by Filament's action pipeline (unlike a plain
     * exception, which surfaces as an unhandled crash), so the notification
     * is sent explicitly first.
     */
    protected static function fail(string $body): never
    {
        Notification::make()
            ->danger()
            ->title(__('Dynamic Group not saved'))
            ->body($body)
            ->send();

        throw new Halt;
    }
}
