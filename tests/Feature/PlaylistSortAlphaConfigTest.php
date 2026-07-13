<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Playlist;
use App\Models\SourceCategory;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'sort_alpha_config' => null,
    ]);

    $this->actingAs($this->user);
});

it('offers only valid sort columns for each target', function (string $target, array $expectedOptions) {
    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'sort_alpha_config' => [[
                'enabled' => true,
                'target' => $target,
                'group' => ['all'],
                'column' => array_key_first($expectedOptions),
                'sort' => 'ASC',
            ]],
        ])
        ->assertFormFieldExists('sort_alpha_config', function (Repeater $field) use ($expectedOptions): bool {
            $item = collect($field->getItems())->first();
            $column = $item?->getFlatFields(withHidden: true)['column'] ?? null;

            Assert::assertInstanceOf(Select::class, $column);
            Assert::assertSame($expectedOptions, $column->getOptions());

            return true;
        });
})->with([
    'live groups' => [
        'live_groups',
        [
            'title' => 'Title (or override if set)',
            'name' => 'Name (or override if set)',
            'stream_id' => 'ID (or override if set)',
            'channel' => 'Channel No.',
        ],
    ],
    'vod groups' => [
        'vod_groups',
        [
            'title' => 'Title (or override if set)',
            'name' => 'Name (or override if set)',
            'stream_id' => 'ID (or override if set)',
            'channel' => 'Channel No.',
            'release_date' => 'Release Date',
        ],
    ],
    'series categories' => [
        'series_categories',
        [
            'release_date' => 'Release Date',
        ],
    ],
]);

it('offers playlist categories for series release date rules', function () {
    $category = SourceCategory::create([
        'playlist_id' => $this->playlist->id,
        'name' => 'Provider Drama',
        'source_category_id' => 123,
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'sort_alpha_config' => [[
                'enabled' => true,
                'target' => 'series_categories',
                'group' => ['all'],
                'column' => 'release_date',
                'sort' => 'DESC',
            ]],
        ])
        ->assertFormFieldExists('sort_alpha_config', function (Repeater $field) use ($category): bool {
            $item = collect($field->getItems())->first();
            $group = $item?->getFlatFields(withHidden: true)['group'] ?? null;

            Assert::assertInstanceOf(Select::class, $group);
            Assert::assertSame([
                'all' => 'All categories',
                $category->name => $category->name,
            ], $group->getOptions());

            return true;
        });
});

it('persists a series release date sort rule', function () {
    $rule = [
        'enabled' => true,
        'target' => 'series_categories',
        'group' => ['all'],
        'column' => 'release_date',
        'sort' => 'DESC',
    ];

    Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
        ->fillForm([
            'user_agent' => 'Test Agent',
            'sort_alpha_config' => [$rule],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->playlist->fresh()->sort_alpha_config)->toEqual([$rule]);
});
