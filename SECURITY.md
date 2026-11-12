# Security Policy

M3U Editor holds provider logins, playlists, guide data, and watch history, and often runs reachable from the internet. We take security reports seriously and treat them as our top priority.

## Reporting a vulnerability

**Please don't report security problems in public issues, discussions, or public Discord channels.**

Report them privately through GitHub:

1. Go to the [Security tab](https://github.com/m3ue/m3u-editor/security) of this repository.
2. Click **Report a vulnerability**.
3. Fill in the form. Only the maintainers can see it.

Or send a direct message to **shparkison** on [Discord](https://discord.gg/rS3abJ5dz7). Please don't post it in a channel, even to ask who to talk to.

Please include:

- what the problem is and what an attacker could do with it,
- the version you tested (shown under **Tools → System Health**) and how you run it (Docker all-in-one, separate proxy, etc.),
- steps to reproduce, or a proof of concept,
- whether it needs a logged-in user, and if so what permissions.

If the problem is in M3U Proxy (including the proxy embedded in M3U Editor), you can report it here or on the [M3U Proxy repository](https://github.com/m3ue/m3u-proxy/security).

## What happens next

- We'll acknowledge your report within **3 days**.
- We'll confirm whether it's a vulnerability, and keep you updated while we work on a fix.
- Serious problems get a fix and a release as quickly as we can, usually within days, not weeks.
- Once a fixed release is out, we publish a [GitHub security advisory](https://github.com/m3ue/m3u-editor/security/advisories) and explain what to update and why in the release notes and on the [blog](https://m3ue.sparkison.dev/blog).
- We'll credit you in the advisory, unless you'd rather we didn't.

Please give us a reasonable chance to release a fix before you disclose the problem publicly.

## Supported versions

Security fixes go into the next release. We don't backport fixes to older versions, so please keep up to date.

| Version | Supported |
| ------- | --------- |
| Latest release | ✅ |
| Older releases | ❌ |

## Scope

In scope:

- M3U Editor itself, including its web app, APIs, and generated playlist, EPG, and Xtream endpoints
- the official Docker images, including the bundled M3U Proxy, PostgreSQL, Redis, and nginx configuration
- the official first-party plugins

Out of scope:

- problems that need an administrator account, unless they let an admin break out of the app (for example, running commands on the host)
- setups that ignore the documented security steps, such as a missing or weak proxy token
- vulnerabilities in your IPTV provider, media server, or other third-party services
- third-party plugins, which should be reported to their authors
- denial of service by flooding requests

Not sure if something is in scope? Report it anyway, and we'll work it out together.
