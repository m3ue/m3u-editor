<?php

/**
 * Regression tests for the SourceCategoriesTable "enabled" filter, mirroring the
 * SourceGroupsTable filter coverage in SourceGroupsTableSearchTest.
 */

use App\Filament\Tables\SourceCategoriesTable;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\SourceCategory;
use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

class SourceCategoriesTableHarness extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public int $playlistId = 0;

    public array $selected = [];

    public function table(Table $table): Table
    {
        return SourceCategoriesTable::configure(
            $table->arguments(['playlist_id' => $this->playlistId, 'selected' => $this->selected])
        );
    }

    public function render(): string
    {
        return <<<'BLADE'
        <div>{{ $this->table }}</div>
        BLADE;
    }
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->action = SourceCategory::create(['playlist_id' => $this->playlist->id, 'name' => 'Action', 'source_category_id' => 1]);
    $this->comedy = SourceCategory::create(['playlist_id' => $this->playlist->id, 'name' => 'Comedy', 'source_category_id' => 2]);
    $this->drama = SourceCategory::create(['playlist_id' => $this->playlist->id, 'name' => 'Drama', 'source_category_id' => 3]);
});

function filterCategories(Playlist $playlist, bool $enabled, array $selected = []): Testable
{
    return Livewire::test(SourceCategoriesTableHarness::class, ['playlistId' => $playlist->id, 'selected' => $selected])
        ->filterTable('enabled', $enabled);
}

it('filters selected categories by current source category ids', function () {
    filterCategories($this->playlist, true, [$this->action->id])
        ->assertCanSeeTableRecords([$this->action])
        ->assertCanNotSeeTableRecords([$this->comedy, $this->drama]);
});

it('filters selected categories by legacy selected names', function () {
    filterCategories($this->playlist, true, ['Comedy'])
        ->assertCanSeeTableRecords([$this->comedy])
        ->assertCanNotSeeTableRecords([$this->action, $this->drama]);
});

it('filters unselected categories by excluding the current selection', function () {
    filterCategories($this->playlist, false, [$this->action->id])
        ->assertCanSeeTableRecords([$this->comedy, $this->drama])
        ->assertCanNotSeeTableRecords([$this->action]);
});

it('shows no categories when filtering to selected only with an empty selection', function () {
    filterCategories($this->playlist, true, [])
        ->assertCanNotSeeTableRecords([$this->action, $this->comedy, $this->drama]);
});

it('shows all categories when filtering to unselected only with an empty selection', function () {
    filterCategories($this->playlist, false, [])
        ->assertCanSeeTableRecords([$this->action, $this->comedy, $this->drama]);
});

it('shows a renamed category with its provider name', function () {
    Category::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Laughs',
        'name_internal' => 'Comedy',
    ]);

    Livewire::test(SourceCategoriesTableHarness::class, ['playlistId' => $this->playlist->id])
        ->assertSee('Laughs (provider: Comedy)')
        ->assertSee('Action');

    expect(SourceCategory::displayLabelsForIds($this->playlist->id, [$this->comedy->id, $this->action->id]))
        ->toBe([$this->action->id => 'Action', $this->comedy->id => 'Laughs (provider: Comedy)']);
});

it('finds a renamed category by either its custom or its provider name', function () {
    Category::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Laughs',
        'name_internal' => 'Comedy',
    ]);

    foreach (['laughs', 'comedy'] as $term) {
        Livewire::test(SourceCategoriesTableHarness::class, ['playlistId' => $this->playlist->id])
            ->searchTable($term)
            ->assertCanSeeTableRecords([$this->comedy])
            ->assertCanNotSeeTableRecords([$this->action, $this->drama]);
    }
});

it('ignores a rename from another playlist', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    Category::factory()->for($otherPlaylist)->for($this->user)->create([
        'name' => 'Not this one',
        'name_internal' => 'Comedy',
    ]);

    expect(SourceCategory::displayLabelsForIds($this->playlist->id, [$this->comedy->id]))
        ->toBe([$this->comedy->id => 'Comedy']);
});
