<?php

use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Http\Middleware\SyncTableColumnPreferences;
use App\Models\TableColumnPreference;
use App\Models\User;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('seeds a saved column order from the database into a brand-new session on a real page load', function () {
    $this->actingAs($this->user);

    // Derive a realistic column-manager state the same way Filament would,
    // then reorder it the way a user's drag-reorder would.
    $mounted = Livewire::test(ListChannels::class);
    $reflection = new ReflectionMethod(ListChannels::class, 'getDefaultTableColumnState');
    $reflection->setAccessible(true);
    $state = $reflection->invoke($mounted->instance());
    [$state[0], $state[1]] = [$state[1], $state[0]];

    $hash = md5(ListChannels::class);

    TableColumnPreference::create([
        'user_id' => $this->user->id,
        'table_key' => "{$hash}_columns",
        'value' => $state,
    ]);
    TableColumnPreference::create([
        'user_id' => $this->user->id,
        'table_key' => "{$hash}_has_reordered_columns",
        'value' => true,
    ]);

    // A brand-new, empty session (simulates a first login on a different device).
    $this->flushSession();

    $this->get(route('filament.admin.resources.channels.index'))->assertOk();

    expect(session("tables.{$hash}_columns")[0]['name'])->toBe($state[0]['name']);
});

it('only persists column-manager session keys, not sort/filter/search state that shares the same session namespace', function () {
    $this->actingAs($this->user);

    $hash = md5('Some\\Other\\Page');

    $request = Request::create('/admin');
    $session = app('session')->driver();
    $session->start();
    $session->put('tables', [
        "{$hash}_columns" => ['a', 'b'],
        "{$hash}_has_reordered_columns" => true,
        "{$hash}_sort" => 'name',
        "{$hash}_filters" => ['status' => 'enabled'],
        "{$hash}_per_page" => 25,
        "{$hash}_search" => 'foo',
    ]);
    $request->setLaravelSession($session);

    (new SyncTableColumnPreferences)->terminate($request, new Response);

    $persistedKeys = TableColumnPreference::query()
        ->where('user_id', $this->user->id)
        ->pluck('table_key')
        ->all();

    expect($persistedKeys)->toEqualCanonicalizing([
        "{$hash}_columns",
        "{$hash}_has_reordered_columns",
    ]);
});

it('does not overwrite a value already present in the current session when seeding', function () {
    $this->actingAs($this->user);

    $hash = md5('Some\\Other\\Page');

    TableColumnPreference::create([
        'user_id' => $this->user->id,
        'table_key' => "{$hash}_columns",
        'value' => ['saved'],
    ]);

    $request = Request::create('/admin');
    $session = app('session')->driver();
    $session->start();
    $session->put('tables', ["{$hash}_columns" => ['current']]);
    $request->setLaravelSession($session);

    $captured = null;
    (new SyncTableColumnPreferences)->handle($request, function ($req) use (&$captured) {
        $captured = $req->session()->get('tables');

        return new Response;
    });

    expect($captured["{$hash}_columns"])->toBe(['current']);
});
