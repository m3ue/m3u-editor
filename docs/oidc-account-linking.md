# OIDC account identity and upgrades

SSO accounts are identified only by the exact OpenID Connect `(iss, sub)` pair. A display name or email address does not prove ownership of an existing local account. The application verifies ID-token signatures, nonce, issuer and client audience before using the identity. Userinfo may supply the email/profile, but its subject must match the verified token.

## Configuration

- `OIDC_ISSUER_URL` is the provider's discovery base URL, without `/.well-known/openid-configuration`.
- `OIDC_EXPECTED_ISSUER` is the exact `issuer` advertised by that provider and returned in ID tokens. It defaults to `OIDC_ISSUER_URL`. Values are not normalized: trailing slashes and case must match literally.
- Keep the existing client ID, client secret, redirect URI and scopes (`openid,profile,email`). The provider must publish usable signing keys.
- `OIDC_AUTO_CREATE_USERS=true` creates a new account only when its email and local username are available. With `false`, unknown identities cannot create accounts.

Email is a profile attribute, never an authentication or automatic linking key. An email already belonging to another local user rejects the callback without modifying either account. Only a boolean `email_verified=true` marks a new/changed email verified; changing to an unverified email clears the old verification. The local name remains the Xtream streaming username and is never overwritten during sign-in or linking.

## Link a local account

Sign in with the account's existing local credentials, open your profile and choose **Link SSO account**. Confirm the action and authenticate with the provider. The confirmation creates a single-use five-minute intent bound to your current local session; the callback cannot link or switch to a different user's account. Accounts already bound to a different identity cannot be overwritten through this flow. Linking is unavailable in `AUTO_LOGIN` mode.

## Upgrade existing SSO accounts

The schema upgrade adds a nullable issuer and a unique `(oidc_issuer, oidc_id)` constraint. It deliberately does not assume which issuer produced old subject-only bindings. Until explicitly linked/migrated, those old bindings are not accepted for SSO login. Keep local administrator access available during the upgrade, especially when `OIDC_HIDE_LOGIN_FORM` or automatic SSO redirects are enabled.

If the existing subjects were issued by the configured original provider, an operator can review and explicitly bind them:

```sh
php artisan oidc:bind-legacy-issuer --issuer='https://auth.example.com/realms/myrealm' --dry-run
php artisan oidc:bind-legacy-issuer --issuer='https://auth.example.com/realms/myrealm'
```

Use the literal original issuer, matching `OIDC_EXPECTED_ISSUER`, not a guessed/replacement provider. The command checks every candidate before writing, rejects duplicate/already-owned subjects, and updates only legacy rows in one transaction. Dry-run never changes bindings. Review conflicts manually; do not merge accounts or rewrite subjects based on names/emails. Alternatively, users with local credentials can explicitly link from their profile.

Existing subject-only accounts whose provider/email changed may need operator assistance; do not enable auto-creation as a migration shortcut. No existing password, username, role or streaming credential is changed by the issuer migration.

## Checks

```sh
php artisan test --compact tests/Feature/OidcAuthenticationTest.php tests/Feature/OidcIdentityLinkingTest.php tests/Feature/OidcProfileLinkTest.php tests/Feature/BindLegacyOidcIssuerTest.php tests/Unit/OidcProviderTest.php
```

The provider tests use genuinely signed synthetic tokens and an in-process HTTP transport fixture. They exercise signature validation and the userinfo fallback without contacting a real identity provider.
