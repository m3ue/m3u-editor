<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OidcController
{
    public function redirect(): RedirectResponse
    {
        if (! config('services.oidc.enabled')) {
            abort(404);
        }

        return Socialite::driver('oidc')->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! config('services.oidc.enabled')) {
            abort(404);
        }

        try {
            $oidcUser = Socialite::driver('oidc')->user();
        } catch (\Throwable $e) {
            Log::error('OIDC authentication failed', ['error' => $e->getMessage()]);

            return redirect()->route('filament.admin.auth.login')
                ->with('oidc_error', 'Authentication failed. Please try again.');
        }

        $email = $oidcUser->getEmail();
        if (empty($email)) {
            Log::warning('OIDC user has no email address', ['oidc_id' => $oidcUser->getId()]);

            return redirect()->route('filament.admin.auth.login')
                ->with('oidc_error', 'Your SSO account does not have an email address.');
        }

        // Try to find existing user by OIDC ID, then email, then username
        $user = User::where('oidc_id', $oidcUser->getId())->first();

        if (! $user) {
            $user = User::where('email', $email)->first();
        }

        if (! $user && $oidcUser->getName()) {
            $user = User::where('name', $oidcUser->getName())->first();
        }

        if ($user) {
            // Link OIDC identity to existing user if not already linked
            if (! $user->oidc_id) {
                $user->update(['oidc_id' => $oidcUser->getId()]);
            }

            // Sync profile from IdP — only sync email and avatar.
            // Name is NOT synced because it is used as the Xtream API
            // username; overwriting it would break streaming clients
            // (e.g. Tivimate) that authenticate with the original name.
            $user->update(array_filter([
                'email' => $oidcUser->getEmail() ?: $user->email,
                'avatar_url' => $oidcUser->getAvatar() ?: $user->avatar_url,
            ]));
        } elseif (config('services.oidc.auto_create_users')) {
            $user = User::create([
                'name' => $oidcUser->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::random(64),
                'oidc_id' => $oidcUser->getId(),
                'avatar_url' => $oidcUser->getAvatar(),
                'email_verified_at' => now(),
            ]);
        } else {
            Log::warning('OIDC login denied: no matching user and auto-create disabled', ['email' => $email]);

            return redirect()->route('filament.admin.auth.login')
                ->with('oidc_error', 'No account found for this email. Contact your administrator.');
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect()->intended(filament()->getUrl());
    }
}
