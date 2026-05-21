<?php

/**
 * Regression tests for SourceGroupsTable search/display.
 *
 * Guards the alias/playlist group selector: substring search must work (this broke
 * when a custom LIKE bypassed Filament's case-insensitive search on PostgreSQL),
 * while the imported group's custom name is shown when one exists.
 */

use App\Filament\Tables\SourceGroupsTable;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\SourceGroup;
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

class SourceGroupsTableHarness extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public int $playlistId = 0;

    public string $groupType = 'live';

    public function table(Table $table): Table
    {
        return SourceGroupsTable::configure(
            $table->arguments(['playlist_id' => $this->playlistId, 'type' => $this->groupType])
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

    $this->ent = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Entertainment', 'type' => 'live']);
    $this->doc = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Documentaries', 'type' => 'live']);
    $this->sport = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Sports', 'type' => 'live']);
});

function searchGroups(Playlist $playlist, string $term): Testable
{
    return Livewire::test(SourceGroupsTableHarness::class, ['playlistId' => $playlist->id])
        ->searchTable($term);
}

it('finds a group by a substring at the start of the name', function () {
    searchGroups($this->playlist, 'enter')
        ->assertCanSeeTableRecords([$this->ent])
        ->assertCanNotSeeTableRecords([$this->doc, $this->sport]);
});

it('finds a group by a mid-word substring', function () {
    searchGroups($this->playlist, 'doc')
        ->assertCanSeeTableRecords([$this->doc])
        ->assertCanNotSeeTableRecords([$this->ent, $this->sport]);
});

it('finds a group by its full name', function () {
    searchGroups($this->playlist, 'Sports')
        ->assertCanSeeTableRecords([$this->sport])
        ->assertCanNotSeeTableRecords([$this->ent, $this->doc]);
});

it('finds groups by a shared substring', function () {
    searchGroups($this->playlist, 'ent')
        ->assertCanSeeTableRecords([$this->ent, $this->doc])
        ->assertCanNotSeeTableRecords([$this->sport]);
});

it('search is case-insensitive', function () {
    searchGroups($this->playlist, 'ENTER')
        ->assertCanSeeTableRecords([$this->ent])
        ->assertCanNotSeeTableRecords([$this->doc, $this->sport]);
});

it('displays the imported custom name when one exists', function () {
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'UK Sports HD',
        'name_internal' => 'Sports',
        'type' => 'live',
    ]);

    searchGroups($this->playlist, 'Sports')
        ->assertCanSeeTableRecords([$this->sport])
        ->assertSee('UK Sports HD');
});

it('falls back to the source name when no group has been imported', function () {
    searchGroups($this->playlist, 'Documentaries')
        ->assertCanSeeTableRecords([$this->doc])
        ->assertSee('Documentaries');
});

it('finds a group by a term that only appears in its custom name', function () {
    // Source "Sports" renamed to "UK Sports HD" — "UK" only appears in the custom name.
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'UK Sports HD',
        'name_internal' => 'Sports',
        'type' => 'live',
    ]);

    searchGroups($this->playlist, 'UK')
        ->assertCanSeeTableRecords([$this->sport])
        ->assertCanNotSeeTableRecords([$this->ent, $this->doc]);
});

it('matches both the custom name and the source name for a renamed group', function () {
    // The reported case: a source group whose name contains "general", renamed to "Entertainment".
    $general = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'GENERAL hevc', 'type' => 'live']);
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Entertainment',
        'name_internal' => 'GENERAL hevc',
        'type' => 'live',
    ]);

    // The display (custom) name is searchable…
    searchGroups($this->playlist, 'entertainment')->assertCanSeeTableRecords([$general]);
    // …and the original source name still matches.
    searchGroups($this->playlist, 'general')->assertCanSeeTableRecords([$general]);
});

it('does not match a custom name belonging to a group of a different type', function () {
    // A VOD group shares name_internal "Sports" with the live source group. Its custom
    // name ("Movie Marathon") must not surface the *live* "Sports" group when searching
    // the live table — the custom-name match is constrained to the same type.
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Movie Marathon',
        'name_internal' => 'Sports',
        'type' => 'vod',
    ]);

    searchGroups($this->playlist, 'marathon')
        ->assertCanNotSeeTableRecords([$this->ent, $this->doc, $this->sport]);
});
