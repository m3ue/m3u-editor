<?php

use App\Filament\Clusters\Settings\Pages\ManageAlertSettings;
use App\Filament\Clusters\Settings\Pages\ManageGeneralSettings;
use App\Filament\Clusters\Settings\Pages\ManageSmtpSettings;
use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['permissions' => ['use_proxy']]);
    actingAs($this->admin);

    $this->setPrivacyMode = function (bool $enabled): void {
        $settings = app(GeneralSettings::class);
        $settings->privacy_mode = $enabled;
        $settings->save();
    };

    $this->bindProxyMock = function (): void {
        $service = Mockery::mock(M3uProxyService::class);
        $service->shouldReceive('fetchActiveStreams')->andReturn(['success' => true, 'streams' => []]);
        $service->shouldReceive('fetchActiveClients')->andReturn(['success' => true, 'clients' => []]);
        $service->shouldReceive('fetchBroadcasts')->andReturn(['success' => true, 'broadcasts' => []]);
        app()->instance(M3uProxyService::class, $service);
    };
});

it('defaults privacy mode to off', function () {
    expect(app(GeneralSettings::class)->privacy_mode)->toBeFalse();
});

it('saves the privacy mode toggle from the general settings page', function () {
    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['privacy_mode' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->privacy_mode)->toBeTrue();
});

it('renders the blur styles in the page head only when privacy mode is on', function () {
    $render = fn (): string => Filament::renderHook(PanelsRenderHook::HEAD_END)->toHtml();

    ($this->setPrivacyMode)(false);
    expect($render())->not->toContain('.privacy-sensitive');

    ($this->setPrivacyMode)(true);
    expect($render())
        ->toContain('.privacy-sensitive')
        ->toContain('filter: blur')
        ->toContain('.privacy-sensitive:hover');
});

it('shows the stream monitor hide urls button when privacy mode is off', function () {
    ($this->bindProxyMock)();
    ($this->setPrivacyMode)(false);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSee('Toggle URL visibility');
});

it('hides the stream monitor hide urls button when privacy mode is on', function () {
    ($this->bindProxyMock)();
    ($this->setPrivacyMode)(true);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertDontSee('Toggle URL visibility');
});

it('marks the output url field and qr code as privacy sensitive', function () {
    $html = Blade::render(
        '<x-output-url-field label="M3U" :enabled="true" url="http://example.test/m3u" suffix=".m3u" qr-body="M3U" record-name="Test" description="d" disabled-description="x" />'
    );

    expect($html)->toContain('privacy-sensitive');
    expect(substr_count($html, 'privacy-sensitive'))->toBeGreaterThanOrEqual(2);
});

it('marks webhook url inputs as privacy sensitive', function (string $field) {
    Livewire::test(ManageAlertSettings::class)
        ->assertFormFieldExists($field, fn ($component): bool => str_contains(
            (string) $component->getExtraInputAttributeBag()->get('class'),
            'privacy-sensitive',
        ));
})->with(['discord_webhook_url', 'slack_webhook_url']);

it('marks the smtp username input as privacy sensitive', function () {
    Livewire::test(ManageSmtpSettings::class)
        ->assertFormFieldExists('smtp_username', fn ($component): bool => str_contains(
            (string) $component->getExtraInputAttributeBag()->get('class'),
            'privacy-sensitive',
        ));
});

it('shows stream monitor urls unblurred by the legacy toggle when privacy mode is on', function () {
    ($this->bindProxyMock)();
    ($this->setPrivacyMode)(true);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSeeHtml('showUrls: true ||');
});

it('marks the provider hosts in the alias entry label as privacy sensitive', function () {
    Queue::fake();

    $playlist = Playlist::factory()->for($this->admin)->createQuietly(['xtream_config' => null]);
    $alias = PlaylistAlias::create([
        'name' => 'Provider Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->admin->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => [[
            'url' => 'http://provider.example.com:8080',
            'username' => 'user',
            'password' => 'pass',
            'replace_url_enabled' => true,
            'replace_url' => 'http://vpn.example.com:8080',
        ]],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSeeHtml('Provider: <span class="privacy-sensitive">provider.example.com</span> -> <span class="privacy-sensitive">vpn.example.com</span>');
});
