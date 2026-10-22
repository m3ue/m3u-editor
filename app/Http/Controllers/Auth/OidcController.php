<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OidcController
{
    public function redirect(): RedirectResponse
    {
        abort_unless(config('services.oidc.enabled'), 404);
        session()->forget('oidc_link');

        return Socialite::driver('oidc')->redirect();
    }

    public function link(): RedirectResponse
    {
        abort_unless(config('services.oidc.enabled'), 404);
        abort_unless(Auth::check() && ! config('auth.auto_login'), 403);

        $response = Socialite::driver('oidc')->redirect();
        session()->put('oidc_link', [
            'user_id' => Auth::id(),
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]);

        return $response;
    }

    public function callback(): RedirectResponse
    {
        abort_unless(config('services.oidc.enabled'), 404);
        // A consent attempt is single-use, including provider failures.
        $link = session()->pull('oidc_link');

        try {
            $oidcUser = Socialite::driver('oidc')->user();
        } catch (\Throwable $e) {
            Log::error('OIDC authentication failed', ['error' => $e->getMessage()]);

            return redirect()->route('filament.admin.auth.login')
                ->with('oidc_error', 'Authentication failed. Please try again.');
        }

        $claims = $oidcUser->getRaw();
        $subject = $oidcUser->getId();
        $issuer = $claims['iss'] ?? null;
        $email = $oidcUser->getEmail();

        if (! is_string($subject) || trim($subject) === '' || strlen($subject) > 255
            || ! is_string($issuer) || $issuer === '' || strlen($issuer) > 255
            || $issuer !== config('services.oidc.expected_issuer')
            || ($claims['sub'] ?? null) !== $subject
            || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return $this->denied();
        }

        if ($link !== null && (! is_array($link) || ($link['user_id'] ?? null) !== Auth::id()
            || ! is_int($link['expires_at'] ?? null) || $link['expires_at'] <= now()->timestamp
            || ! Auth::check() || config('auth.auto_login'))) {
            return $this->denied();
        }

        try {
            $user = DB::transaction(function () use ($oidcUser, $claims, $subject, $issuer, $email, $link): ?User {
                $user = User::query()->where('oidc_issuer', $issuer)->where('oidc_id', $subject)->lockForUpdate()->first();

                // Some database collations compare strings case-insensitively;
                // OIDC issuer and subject identifiers must still match literally.
                if ($user && ($user->oidc_issuer !== $issuer || $user->oidc_id !== $subject)) {
                    return null;
                }

                if ($link !== null) {
                    // Never switch to another account when the user asked to link.
                    if ($user && $user->id !== $link['user_id']) {
                        return null;
                    }

                    $user = User::query()->lockForUpdate()->find($link['user_id']);
                    if (! $user || ($user->oidc_issuer !== null
                        && ($user->oidc_issuer !== $issuer || $user->oidc_id !== $subject))) {
                        return null;
                    }
                }

                if (User::query()->where('email', $email)->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists()) {
                    return null;
                }

                $verifiedAt = ($claims['email_verified'] ?? false) === true ? now() : null;

                if ($user) {
                    $user->forceFill([
                        'oidc_id' => $subject,
                        'oidc_issuer' => $issuer,
                        'email' => $email,
                        'email_verified_at' => $user->email === $email && ! $verifiedAt ? $user->email_verified_at : $verifiedAt,
                        'avatar_url' => $oidcUser->getAvatar() ?: $user->avatar_url,
                    ])->save();

                    // The local name is also the Xtream username. Never overwrite it.
                    return $user;
                }

                if (! config('services.oidc.auto_create_users')) {
                    return null;
                }

                $name = $oidcUser->getName() ?: Str::before($email, '@');
                if (! is_string($name) || strlen($name) > 255 || User::query()->where('name', $name)->exists()) {
                    return null;
                }

                return User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Str::random(64),
                    'oidc_id' => $subject,
                    'oidc_issuer' => $issuer,
                    'avatar_url' => $oidcUser->getAvatar(),
                    'email_verified_at' => $verifiedAt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent callback/link may have claimed the subject or email.
            return $this->denied();
        }

        if (! $user) {
            return $this->denied();
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect()->intended(filament()->getUrl());
    }

    private function denied(): RedirectResponse
    {
        return redirect()->route('filament.admin.auth.login')
            ->with('oidc_error', __('oidc.account_error'));
    }
}
