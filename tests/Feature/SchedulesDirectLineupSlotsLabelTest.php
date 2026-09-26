<?php

use App\Filament\Resources\Epgs\EpgResource;
use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Models\Epg;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * The slot counter of the SchedulesDirect lineup modal is a user-facing string. It must be
 * resolved through a static, parameterized translation key so every locale can translate it
 * and the numbers are passed as parameters instead of being baked into the key itself.
 */
it('renders the SchedulesDirect lineup slot counter from a static parameterized key', function () {
    $epg = Epg::withoutEvents(fn () => Epg::factory()->create([
        'sd_token' => 'token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-NY12345-X',
        'sd_country' => 'USA',
        'sd_postal_code' => '10001',
    ]));

    Http::fake([
        'json.schedulesdirect.org/20141201/status' => Http::response(['account' => ['maxLineups' => 4]]),
        'json.schedulesdirect.org/20141201/lineups' => Http::response(['lineups' => [
            ['lineup' => 'USA-NY12345-X', 'name' => 'Existing', 'transport' => 'DVB-C'],
        ]]),
        'json.schedulesdirect.org/20141201/headends*' => Http::response([[
            'headend' => 'H1',
            'transport' => 'DVB-C',
            'location' => 'X',
            'lineups' => [['lineup' => 'USA-OTH-1', 'name' => 'Other']],
        ]]),
    ]);

    expect(Lang::has(':used of :max slots used'))->toBeTrue()
        ->and(Lang::has('1 of 4 slots used'))->toBeFalse();

    // Replace the static key at runtime: only a lookup through that exact key renders the override.
    Lang::addLines(['*.:used of :max slots used' => 'Slots :used/:max used'], 'en');

    $action = EpgResource::getManageSdLineupsAction();
    $action->record($epg);
    $schema = $action->getSchema(Schema::make(new ListEpgs));

    $fields = [];

    foreach ($schema->getComponents() as $component) {
        $fields[$component->getName()] = $component;
    }

    $addHelperText = $fields['lineup_to_add']
        ->getChildSchema($fields['lineup_to_add']::BELOW_CONTENT_SCHEMA_KEY)
        ->toHtmlString();

    expect(str_contains($addHelperText, 'Slots 1/4 used'))->toBeTrue()
        ->and(str_contains($addHelperText, '1 of 4 slots used'))->toBeFalse()
        ->and($fields['lineup_to_remove']->getHint())->toBe('Slots 1/4 used');
});

it("uses a multiple select for an EPG's selected SchedulesDirect lineups", function () {
    $resource = file_get_contents(app_path('Filament/Resources/Epgs/EpgResource.php'));

    expect($resource)->toContain("Select::make('sd_lineup_ids')")
        ->toContain('->multiple()')
        ->not->toContain("Select::make('sd_lineup_id')");
});

it('limits the normal EPG lineup picker to account-confirmed lineups', function () {
    $resource = file_get_contents(app_path('Filament/Resources/Epgs/EpgResource.php'));
    $lineupField = strstr($resource, "Select::make('sd_lineup_ids')");
    $lineupField = substr($lineupField, 0, strpos($lineupField, "TextInput::make('sd_days_to_import')"));

    expect($lineupField)->toContain('$service->getUserLineups($authData[\'token\'])')
        ->not->toContain('$service->getHeadends($authData[\'token\'], $country, $postalCode)');
});

it('does not advertise account lineup deletion from the EPG delete action', function () {
    $resource = file_get_contents(app_path('Filament/Resources/Epgs/EpgResource.php'));

    expect($resource)->not->toContain('Also delete lineup from SchedulesDirect account')
        ->not->toContain('delete_sd_lineup')
        ->not->toContain('removeConfiguredLineup');
});
