<?php

use App\Filament\Resources\AedProfiles\Pages\ListAedProfiles;
use App\Filament\Resources\StreamFileSettings\Pages\ListStreamFileSettings;
use App\Filament\Resources\StreamProfiles\Pages\ListStreamProfiles;
use App\Models\AedProfile;
use App\Models\StreamFileSetting;
use App\Models\StreamProfile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->targetUser = User::factory()->create();
});

// ── AedProfile ────────────────────────────────────────────────────────────────

it('shows copy-to-user action to admins on AED profiles', function (): void {
    $profile = AedProfile::factory()->for($this->admin)->create();

    $this->actingAs($this->admin);

    Livewire::test(ListAedProfiles::class)
        ->assertActionVisible(TestAction::make('copy-to-user')->table($profile));
});

it('hides copy-to-user action from non-admins on AED profiles', function (): void {
    $user = User::factory()->create();
    $profile = AedProfile::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ListAedProfiles::class)
        ->assertActionHidden(TestAction::make('copy-to-user')->table($profile));
});

it('copies an AED profile to selected users', function (): void {
    $profile = AedProfile::factory()->for($this->admin)->create(['name' => 'Shared AED Profile']);

    $this->actingAs($this->admin);

    Livewire::test(ListAedProfiles::class)
        ->callAction(
            TestAction::make('copy-to-user')->table($profile),
            data: ['user_ids' => [$this->targetUser->id]]
        )
        ->assertNotified();

    expect(
        AedProfile::where('user_id', $this->targetUser->id)
            ->where('name', 'Shared AED Profile')
            ->exists()
    )->toBeTrue();
});

it('copies an AED profile to multiple users', function (): void {
    $profile = AedProfile::factory()->for($this->admin)->create(['name' => 'Multi AED Profile']);
    $anotherUser = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListAedProfiles::class)
        ->callAction(
            TestAction::make('copy-to-user')->table($profile),
            data: ['user_ids' => [$this->targetUser->id, $anotherUser->id]]
        )
        ->assertNotified();

    expect(
        AedProfile::where('name', 'Multi AED Profile')
            ->whereIn('user_id', [$this->targetUser->id, $anotherUser->id])
            ->count()
    )->toBe(2);
});

// ── StreamProfile ─────────────────────────────────────────────────────────────

it('shows copy-to-user action to admins on stream profiles', function (): void {
    $profile = StreamProfile::factory()->for($this->admin)->create();

    $this->actingAs($this->admin);

    Livewire::test(ListStreamProfiles::class)
        ->assertActionVisible(TestAction::make('copy-to-user')->table($profile));
});

it('hides copy-to-user action from non-admins on stream profiles', function (): void {
    $user = User::factory()->create(['permissions' => ['use_proxy']]);
    $profile = StreamProfile::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ListStreamProfiles::class)
        ->assertActionHidden(TestAction::make('copy-to-user')->table($profile));
});

it('copies a stream profile to selected users', function (): void {
    $profile = StreamProfile::factory()->for($this->admin)->create(['name' => 'Shared Stream Profile']);

    $this->actingAs($this->admin);

    Livewire::test(ListStreamProfiles::class)
        ->callAction(
            TestAction::make('copy-to-user')->table($profile),
            data: ['user_ids' => [$this->targetUser->id]]
        )
        ->assertNotified();

    expect(
        StreamProfile::where('user_id', $this->targetUser->id)
            ->where('name', 'Shared Stream Profile')
            ->exists()
    )->toBeTrue();
});

// ── StreamFileSetting ─────────────────────────────────────────────────────────

it('shows copy-to-user action to admins on stream file settings', function (): void {
    $setting = StreamFileSetting::factory()->for($this->admin)->create();

    $this->actingAs($this->admin);

    Livewire::test(ListStreamFileSettings::class)
        ->assertActionVisible(TestAction::make('copy-to-user')->table($setting));
});

it('hides copy-to-user action from non-admins on stream file settings', function (): void {
    $user = User::factory()->create(['permissions' => ['use_stream_file_sync']]);
    $setting = StreamFileSetting::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test(ListStreamFileSettings::class)
        ->assertActionHidden(TestAction::make('copy-to-user')->table($setting));
});

it('copies a stream file setting to selected users', function (): void {
    $setting = StreamFileSetting::factory()->for($this->admin)->create(['name' => 'Shared File Setting']);

    $this->actingAs($this->admin);

    Livewire::test(ListStreamFileSettings::class)
        ->callAction(
            TestAction::make('copy-to-user')->table($setting),
            data: ['user_ids' => [$this->targetUser->id]]
        )
        ->assertNotified();

    expect(
        StreamFileSetting::where('user_id', $this->targetUser->id)
            ->where('name', 'Shared File Setting')
            ->exists()
    )->toBeTrue();
});
