<?php

use App\Providers\AppServiceProvider;
use App\Socialite\OidcProvider;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Manager\SocialiteWasCalled;

function signedOidcFixture(array $claims = []): array
{
    static $key;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $publicKey = openssl_pkey_get_details($key)['key'];
    $payload = array_replace([
        'iss' => 'https://issuer.example.test', 'sub' => 'fixture-subject',
        'aud' => 'fixture-client', 'iat' => time(), 'exp' => time() + 300,
        'nonce' => 'fixture-nonce', 'email' => 'viewer@example.test',
    ], $claims);

    return [JWT::encode($payload, $privateKey, 'RS256'), $publicKey];
}

function oidcProviderFixture(array $claims = [], ?array $userInfo = null): OidcProvider
{
    [$token, $publicKey] = signedOidcFixture($claims);
    $request = Request::create('/auth/oidc/callback', 'GET', ['state' => 'fixture-state', 'code' => 'fixture-code']);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put('state', 'fixture-state');
    $request->session()->put('nonce', 'fixture-nonce');
    $provider = new OidcProvider($request, 'fixture-client', 'synthetic-client-secret', 'http://localhost/auth/oidc/callback');
    $provider->setConfig(new Config('fixture-client', 'synthetic-client-secret', 'http://localhost/auth/oidc/callback', [
        'base_url' => 'https://issuer.example.test', 'verify_jwt' => true, 'jwt_public_key' => $publicKey,
    ]));
    $provider->configurations = [
        'token_endpoint' => 'https://issuer.example.test/token',
        'userinfo_endpoint' => 'https://issuer.example.test/userinfo',
    ];
    $responses = [new Response(200, [], json_encode(['id_token' => $token, 'access_token' => 'synthetic-access-token', 'expires_in' => 300]))];
    if ($userInfo !== null) {
        $responses[] = new Response(200, [], json_encode($userInfo));
    }
    $provider->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

    return $provider;
}

beforeEach(function () {
    config()->set('services.oidc.expected_issuer', 'https://issuer.example.test');
});

it('accepts a genuinely signed identity for the configured issuer and client', function () {
    $user = oidcProviderFixture()->user();
    expect($user->getId())->toBe('fixture-subject')->and($user->getRaw()['iss'])->toBe('https://issuer.example.test');
});

it('registers the hardened provider as the actual Socialite OIDC driver', function () {
    config()->set('services.oidc.enabled', true);
    config()->set('services.oidc.client_id', 'fixture-client');
    config()->set('services.oidc.client_secret', 'synthetic-client-secret');
    config()->set('services.oidc.base_url', 'https://issuer.example.test');
    $register = new ReflectionMethod(AppServiceProvider::class, 'registerOidcProvider');
    $register->invoke(new AppServiceProvider(app()));
    app('events')->dispatch(app(SocialiteWasCalled::class));

    expect(Socialite::driver('oidc'))->toBeInstanceOf(OidcProvider::class);
});

it('retains signed identity claims when the email comes from userinfo', function () {
    $user = oidcProviderFixture(['email' => null], ['sub' => 'fixture-subject', 'email' => 'viewer@example.test'])->user();
    expect($user->getEmail())->toBe('viewer@example.test')
        ->and($user->getRaw()['iss'])->toBe('https://issuer.example.test')
        ->and($user->getRaw()['aud'])->toBe('fixture-client');
});

it('rejects mismatched issuer or client claims', function (array $claims) {
    expect(fn () => oidcProviderFixture($claims)->user())->toThrow(RuntimeException::class);
})->with([
    'issuer' => [['iss' => 'https://other.example.test']],
    'audience' => [['aud' => 'another-client']],
    'multiple audiences without authorized party' => [['aud' => ['fixture-client', 'another-client']]],
    'authorized party' => [['azp' => 'another-client']],
]);

it('accepts multiple audiences with the correct authorized party', function () {
    expect(oidcProviderFixture(['aud' => ['fixture-client', 'another-client'], 'azp' => 'fixture-client'])->user()->getId())
        ->toBe('fixture-subject');
});

it('requires userinfo to describe the signed subject', function () {
    expect(fn () => oidcProviderFixture(['email' => null], ['sub' => 'another-subject', 'email' => 'viewer@example.test'])->user())
        ->toThrow(RuntimeException::class);
});
