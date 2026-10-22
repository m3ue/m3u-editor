<?php

use App\Filament\Auth\EditProfile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('services.oidc.enabled', true);
    config()->set('auth.auto_login', false);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('starts linking through the real profile confirmation action', function () {
    $local = User::factory()->create();
    $this->actingAs($local);
    Socialite::shouldReceive('driver->redirect')->once()->andReturn(redirect('https://issuer.example.test/authorize'));

    Livewire::test(EditProfile::class)
        ->assertActionVisible(TestAction::make('linkOidc')->schemaComponent('form-actions', schema: 'content'))
        ->callAction(TestAction::make('linkOidc')->schemaComponent('form-actions', schema: 'content'))
        ->assertRedirect('https://issuer.example.test/authorize');

    expect(session('oidc_link.user_id'))->toBe($local->id)
        ->and(session('oidc_link.expires_at'))->toBeGreaterThan(now()->timestamp);
});

it('hides linking for an already bound profile', function () {
    $local = User::factory()->create(['oidc_id' => 'subject', 'oidc_issuer' => 'https://issuer.example.test']);
    $this->actingAs($local);
    Livewire::test(EditProfile::class)->assertDontSee(__('oidc.link_account'));
});

it('hides linking when OIDC or local authentication is disabled', function (bool $enabled, bool $autoLogin) {
    config()->set('services.oidc.enabled', $enabled);
    config()->set('auth.auto_login', $autoLogin);
    $this->actingAs(User::factory()->create());
    Livewire::test(EditProfile::class)->assertDontSee(__('oidc.link_account'));
    $this->post('/auth/oidc/link')->assertStatus($enabled ? 403 : 404);
})->with([[false, false], [true, true]]);
