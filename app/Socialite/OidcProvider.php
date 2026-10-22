<?php

namespace App\Socialite;

use RuntimeException;
use SocialiteProviders\OIDC\Provider;

class OidcProvider extends Provider
{
    private array $identityClaims = [];

    protected function shouldVerifyJwt(): bool
    {
        return true;
    }

    protected function decodeJWT($jwt, $code)
    {
        $this->identityClaims = [];
        $claims = parent::decodeJWT($jwt, $code);
        $issuer = $claims->iss ?? null;
        $subject = $claims->sub ?? null;
        $audiences = $claims->aud ?? null;
        $audiences = is_string($audiences) ? [$audiences] : $audiences;

        if (! is_string($issuer) || $issuer === '' || $issuer !== config('services.oidc.expected_issuer')
            || ! is_string($subject) || trim($subject) === ''
            || ! is_array($audiences) || ! in_array($this->clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims->azp ?? null) !== $this->clientId)
            || (isset($claims->azp) && $claims->azp !== $this->clientId)) {
            throw new RuntimeException('OIDC identity does not match the configured issuer and client.');
        }

        $this->identityClaims = ['iss' => $issuer, 'sub' => $subject, 'aud' => $claims->aud];
        // The upstream signature-verification branch does not consume its nonce.
        $this->request->session()->forget('nonce');

        return $claims;
    }

    protected function mapUserToObject(array $user)
    {
        if ($this->identityClaims === [] || ($user['sub'] ?? null) !== $this->identityClaims['sub']) {
            throw new RuntimeException('OIDC userinfo does not describe the authenticated subject.');
        }

        // Some providers put email only in userinfo. Do not lose the verified
        // token's identity claims when the upstream driver fetches that profile.
        return parent::mapUserToObject(array_replace($user, $this->identityClaims));
    }
}
