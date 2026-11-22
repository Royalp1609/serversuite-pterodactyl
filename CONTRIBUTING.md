# Contributing to Server Suite

Thanks for helping. Bug reports, fixes, docs and translations are all
welcome.

## Before you start

- **Bugs and ideas:** open an [issue](https://github.com/Royalp1609/serversuite-pterodactyl/issues/new/choose)
  first, so we can agree on the approach before you write code.
- **Security problems:** don't open a public issue; see [SECURITY.md](SECURITY.md).
- **Docs website:** lives in [serversuite-site](https://github.com/Royalp1609/serversuite-site).

## Ground rules

- **Mods stay free.** Server Suite never sells or gates mod, modpack or
  game-version installs: Modrinth and CurseForge terms don't allow it.
  The billing API covers subdomain allowances and backup plans only.
- **Don't break servers.** Anything that changes a server's files or
  reinstalls it must take or require a backup first and refuse when it
  can't do so safely (see how Versions and Mods handle backups).
- **Permissions on the server side.** Every endpoint checks the panel's
  subuser permissions; hiding a button is not enough.
- **No telemetry.** Don't send panel or server data anywhere the admin
  didn't configure. (The daily release check only fetches GitHub's release
  list and can be turned off.)

## Development setup

Requirements: PHP 8.2+, Composer 2, Python 3 (packaging), Node 22 (only to
typecheck the extension against a panel checkout).

```bash
git clone https://github.com/Royalp1609/serversuite-pterodactyl.git
cd serversuite-pterodactyl
composer install
vendor/bin/pest                         # unit + feature tests (Testbench, SQLite)
python3 tools/build-blueprint.py --check  # extension package and versions in sync
```

Layout:

```
src/        backend package (services, models, drivers, commands)
database/   migrations
config/     defaults (admin settings override them)
extension/  Blueprint extension: React tabs, controller, routes, admin view
tests/      Pest tests with fakes for the panel and Wings
tools/      build-blueprint.py, release.py
```

After changing anything in `extension/`, run `python3 tools/build-blueprint.py`
and commit the rebuilt `serversuite.blueprint`.

## Pull requests

- One issue per pull request, branched from `main`. Link the issue
  (`Fixes #123`).
- A bug fix comes with a test that fails without the fix.
- CI must pass: PHP lint, Pest on Laravel 11 and 12, install + PHPStan in
  real Pterodactyl 1.14 and 1.15 checkouts, and the extension typecheck.
- Say in the description what you tested on a real panel, if anything.
- Don't bump the version; releases are cut separately. A security release is
  tagged annotated with a message starting "Security release"
  (`git tag -a v1.2.3 -m "Security release: ..."`): its notes then say so and
  panels show the update notice as a security one.

By contributing you agree that your contribution is licensed under the
project's licence, [AGPL-3.0-only](LICENSE).
