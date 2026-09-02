<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('sorts channels by release date across the selected VOD groups via the bulk action', function () {
    $g1 = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'A']);
    $g2 = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'B']);

    $g1Old = Channel::factory()->for($this->user)->for($this->playlist)->for($g1)->create(['is_vod' => true, 'year' => 1990, 'sort' => 1]);
    $g1New = Channel::factory()->for($this->user)->for($this->playlist)->for($g1)->create(['is_vod' => true, 'year' => 2020, 'sort' => 2]);
    $g2Old = Channel::factory()->for($this->user)->for($this->playlist)->for($g2)->create(['is_vod' => true, 'year' => 1980, 'sort' => 1]);
    $g2New = Channel::factory()->for($this->user)->for($this->playlist)->for($g2)->create(['is_vod' => true, 'year' => 2010, 'sort' => 2]);

    Livewire::test(ListVodGroups::class)
        ->loadTable()
        ->callTableBulkAction('sort_release_date_bulk', [$g1, $g2], ['sort' => 'DESC'])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Channels Sorted by Release Date');

    expect((int) $g1New->refresh()->sort)->toBe(1);
    expect((int) $g1Old->refresh()->sort)->toBe(2);
    expect((int) $g2New->refresh()->sort)->toBe(1);
    expect((int) $g2Old->refresh()->sort)->toBe(2);
});

it('sorts series by release date across the selected categories via the bulk action', function () {
    $c1 = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'A']);
    $c2 = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'B']);

    $c1Old = Series::factory()->for($this->user)->for($this->playlist)->for($c1, 'category')->create(['release_date' => '1990-01-01', 'sort' => 1]);
    $c1New = Series::factory()->for($this->user)->for($this->playlist)->for($c1, 'category')->create(['release_date' => '2020-01-01', 'sort' => 2]);
    $c2Old = Series::factory()->for($this->user)->for($this->playlist)->for($c2, 'category')->create(['release_date' => '1980-01-01', 'sort' => 1]);
    $c2New = Series::factory()->for($this->user)->for($this->playlist)->for($c2, 'category')->create(['release_date' => '2010-01-01', 'sort' => 2]);

    Livewire::test(ListCategories::class)
        ->loadTable()
        ->callTableBulkAction('sort_release_date_bulk', [$c1, $c2], ['sort' => 'DESC'])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Series Sorted by Release Date');

    expect($c1New->refresh()->sort)->toBe(1);
    expect($c1Old->refresh()->sort)->toBe(2);
    expect($c2New->refresh()->sort)->toBe(1);
    expect($c2Old->refresh()->sort)->toBe(2);
});
