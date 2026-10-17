<?php

/**
 * The EPG Create/Edit pages are not routed, so the List page slide-overs and
 * record actions are the only way users add, edit and delete SchedulesDirect
 * EPGs and manage their lineups. These tests drive those routed actions
 * against a faked SchedulesDirect API.
 */

use App\Enums\EpgSourceType;
use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Models\Epg;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Queue::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->makeSdEpg = fn (array $lineupIds, array $attributes = []) => Epg::factory()->for($this->user)->createQuietly([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_ids' => $lineupIds,
        'sync_interval' => '0 */6 * * *',
        ...$attributes,
    ]);
});

/**
 * Fake the SD endpoints the EPG form and lineup actions call. $accountLineups
 * are the lineups on the SD account; DELETE on a lineup not in that list
 * answers 2104 (not in account), like the real API.
 */
function sdFakeAccount(array $accountLineups = []): void
{
    Http::fake(function (Request $request) use ($accountLineups) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/available/countries') => Http::response([
                'North America' => [['shortName' => 'USA', 'fullName' => 'United States']],
            ]),
            str_ends_with($path, '/token') => Http::response([
                'code' => 0,
                'token' => 'fresh-token',
                'tokenExpires' => now()->addDay()->timestamp,
            ]),
            str_ends_with($path, '/headends') => Http::response([
                ['transport' => 'Cable', 'lineups' => [
                    ['lineup' => 'USA-A', 'name' => 'Lineup A'],
                    ['lineup' => 'USA-B', 'name' => 'Lineup B'],
                ]],
            ]),
            str_ends_with($path, '/status') => Http::response(['account' => ['maxLineups' => 4]]),
            str_ends_with($path, '/lineups') => Http::response(['lineups' => array_map(
                fn (string $id) => ['lineup' => $id, 'name' => "Lineup {$id}", 'transport' => 'Cable'],
                $accountLineups,
            )]),
            $request->method() === 'DELETE' && in_array(basename($path), $accountLineups, true) => Http::response(['code' => 0, 'response' => 'OK']),
            $request->method() === 'DELETE' => Http::response(['code' => 2104, 'message' => 'Lineup not in account'], 400),
            default => Http::response(['message' => "Unexpected request {$request->method()} {$path}"], 500),
        };
    });
}

it('creates a SchedulesDirect EPG with several lineups and the form defaults from the list slide-over', function () {
    sdFakeAccount();

    Livewire::test(ListEpgs::class)
        ->callAction('create', data: [
            'name' => 'SD Guide',
            'source_type' => EpgSourceType::SCHEDULES_DIRECT->value,
            'sd_username' => 'test@example.com',
            'sd_password' => 'password',
            'sd_country' => 'USA',
            'sd_postal_code' => '90210',
            'sd_lineup_ids' => ['USA-A', 'USA-B'],
        ])
        ->assertHasNoActionErrors();

    $epg = Epg::query()->where('name', 'SD Guide')->sole();

    expect($epg->user_id)->toBe($this->user->id)
        ->and($epg->source_type)->toBe(EpgSourceType::SCHEDULES_DIRECT)
        ->and($epg->sd_lineup_ids)->toBe(['USA-A', 'USA-B'])
        ->and($epg->sd_days_to_import)->toBe(3)
        ->and($epg->sd_metadata)->toBe(['enabled' => false])
        ->and($epg->sd_debug)->toBeFalse()
        ->and((bool) $epg->auto_sync)->toBeTrue()
        ->and($epg->sync_interval)->toBe('0 */6 * * *');

    // Resolving the selected lineup labels looks them up for the entered location
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/20141201/headends')
        && parse_url($request->url(), PHP_URL_QUERY) === 'country=USA&postalcode=90210'
        && $request->header('token') === ['fresh-token']);
});

it('requires the SD credentials, location and days when creating a SchedulesDirect EPG', function () {
    sdFakeAccount();

    Livewire::test(ListEpgs::class)
        ->callAction('create', data: [
            'name' => 'SD Guide',
            'source_type' => EpgSourceType::SCHEDULES_DIRECT->value,
            'sd_username' => null,
            'sd_password' => null,
            'sd_postal_code' => null,
            'sd_days_to_import' => null,
        ])
        ->assertHasActionErrors([
            'sd_username' => 'required',
            'sd_password' => 'required',
            'sd_postal_code' => 'required',
            'sd_days_to_import' => 'required',
        ]);

    expect(Epg::query()->count())->toBe(0);
});

it('replaces the lineup selection from the edit slide-over without touching the stored token or stations', function () {
    sdFakeAccount();
    $epg = ($this->makeSdEpg)(['USA-A'], [
        'sd_country' => 'USA',
        'sd_postal_code' => '90210',
        'sd_station_ids' => ['1', '2'],
    ]);

    Livewire::test(ListEpgs::class)
        ->callAction(TestAction::make('edit')->table($epg), data: [
            'sd_lineup_ids' => ['USA-B', 'USA-A'],
        ])
        ->assertHasNoActionErrors();

    $epg->refresh();
    expect($epg->sd_lineup_ids)->toBe(['USA-B', 'USA-A'])
        ->and($epg->sd_token)->toBe('valid-token')
        ->and($epg->sd_station_ids)->toBe(['1', '2']);

    // Changing lineups only saves the selection; the account is updated on the next sync
    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['PUT', 'DELETE'], true));
});

it('lists the account lineups and removes the selected one from the record action', function () {
    sdFakeAccount(['USA-A', 'USA-B']);
    $epg = ($this->makeSdEpg)(['USA-A']);

    Livewire::test(ListEpgs::class)
        ->mountAction(TestAction::make('manage_sd_lineups')->table($epg))
        ->assertFormFieldExists('lineup_to_remove', fn ($field) => array_keys($field->getOptions()) === ['USA-A', 'USA-B'])
        ->fillForm(['lineup_to_remove' => 'USA-B'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(__('Lineup removed'));

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/20141201/lineups/USA-B')
        && $request->header('token') === ['valid-token']);

    // Removing a lineup from the account leaves the EPG's own selection alone
    expect($epg->fresh()->sd_lineup_ids)->toBe(['USA-A']);
});

it('re-authenticates before removing a lineup when the stored token has expired', function () {
    sdFakeAccount(['USA-A']);
    $epg = ($this->makeSdEpg)(['USA-A'], ['sd_token_expires_at' => now()->subMinute()]);

    Livewire::test(ListEpgs::class)
        ->callAction(TestAction::make('manage_sd_lineups')->table($epg), data: ['lineup_to_remove' => 'USA-A'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('Lineup removed'));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/20141201/token'));
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/20141201/lineups/USA-A')
        && $request->header('token') === ['fresh-token']);
});

it('hides the lineup manager on URL EPGs', function () {
    $epg = Epg::factory()->for($this->user)->createQuietly(['source_type' => EpgSourceType::URL]);

    Livewire::test(ListEpgs::class)
        ->assertActionHidden(TestAction::make('manage_sd_lineups')->table($epg));
});

it('deletes the EPG and only removes the lineups no other EPG on the account uses by default', function () {
    sdFakeAccount(['USA-OWN', 'USA-SHARED']);
    $epg = ($this->makeSdEpg)(['USA-OWN', 'USA-SHARED']);
    ($this->makeSdEpg)(['USA-SHARED']);

    Livewire::test(ListEpgs::class)
        ->callAction(TestAction::make('delete')->table($epg))
        ->assertHasNoActionErrors();

    expect(Epg::find($epg->id))->toBeNull();
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/20141201/lineups/USA-OWN'));
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/lineups/USA-SHARED'));
});

it('keeps the account lineups when the delete toggle is switched off', function () {
    sdFakeAccount(['USA-OWN']);
    $epg = ($this->makeSdEpg)(['USA-OWN']);

    Livewire::test(ListEpgs::class)
        ->callAction(TestAction::make('delete')->table($epg), data: ['delete_sd_lineup' => false])
        ->assertHasNoActionErrors();

    expect(Epg::find($epg->id))->toBeNull();
    Http::assertNothingSent();
});

it('deletes the EPG when its lineup is already gone from the account', function () {
    sdFakeAccount([]);
    $epg = ($this->makeSdEpg)(['USA-GONE']);

    Livewire::test(ListEpgs::class)
        ->callAction(TestAction::make('delete')->table($epg))
        ->assertHasNoActionErrors();

    expect(Epg::find($epg->id))->toBeNull();
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/20141201/lineups/USA-GONE'));
});

it('still deletes the EPG when SchedulesDirect refuses the lineup removal', function () {
    Http::fake(['json.schedulesdirect.org/*' => Http::response(['code' => 2107, 'message' => 'Too many lineup changes'], 400)]);
    $epg = ($this->makeSdEpg)(['USA-OWN']);

    Livewire::test(ListEpgs::class)
        ->callAction(TestAction::make('delete')->table($epg))
        ->assertHasNoActionErrors();

    expect(Epg::find($epg->id))->toBeNull();
});
