# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Environment

DDEV-based local development: PHP 8.4, MySQL 8.0, Apache.

```bash
ddev start                                              # Start containers
ddev composer install                                   # Install deps
ddev exec php bin/console doctrine:migrations:migrate   # Run migrations
ddev launch                                             # Open https://spread-dread.ddev.site
```

Run Symfony console commands inside DDEV:
```bash
ddev exec php bin/console <command>
```

Generate a new migration after entity changes:
```bash
ddev exec php bin/console doctrine:migrations:diff
ddev exec php bin/console doctrine:migrations:migrate
```

Build Tailwind CSS (required after changing Tailwind classes in templates or assets):
```bash
ddev exec php bin/console tailwind:build
ddev exec php bin/console tailwind:build --watch   # Development watch mode
```

The built CSS (`var/tailwind/app.built.css`) is committed to the repo so the production server doesn't need to run the Tailwind binary. Always run `tailwind:build` locally before committing template changes.

Run tests:
```bash
ddev composer test                                          # Full suite
ddev exec php bin/phpunit --filter testCoversSpread         # Single test by method name
ddev exec php bin/phpunit tests/Service/ScoringServiceTest.php   # Single file
```

Tests use PHPUnit with `dama/doctrine-test-bundle` for transaction isolation (each test rolls back automatically). The test suite shares the dev database but all changes are rolled back. PHPUnit is configured to fail on deprecations, notices, and warnings (`phpunit.dist.xml`). Always run tests before pushing code.

Create or update a user account (password is hashed with bcrypt). Creating a new
account requires `--email`; updating an existing one does not:
```bash
ddev exec php bin/console app:user <username> <password> --email=<address>
```

Clear cache:
```bash
ddev exec php bin/console cache:clear
```

## Architecture

This is a Symfony 7 app for two players to compete on NCAA Tournament brackets using point spreads. One player picks a team per game; the other automatically gets the opponent. Picks are evaluated against the spread, not outright winners.

### Core Domain Model

**Bracket** is the aggregate root. Each bracket owns 63 **Game** entities forming a tournament tree via `Game.nextGame` self-references. Games link to **Team** entities (nullable until teams are assigned/advanced). **Pick** tracks which player chose which team per game. Picks have a nullable `isWinner` field set after spread evaluation.

The bracket tree wiring: odd `bracketPosition` feeds into `team1` of the next game, even feeds into `team2`. Which regions meet in the Final Four is set per year by an admin (`TournamentCalendar`); brackets can't be created until it is.

**Setting** is a simple key/value store (`setting_key`/`setting_value`) for app-wide configuration.

### Key Services

- **BracketBuilderService** — Creates the 63-game bracket structure (32+16+8+4+2+1) with correct NCAA seed matchups and `nextGame` wiring.
- **ScoringService** — `evaluatePicks()` checks if picked team covered the spread. `advanceWinner()` populates the next game's team slot. `calculateScores()` returns per-player totals.
- **EspnApiService** — Pulls teams, spreads and scores from ESPN. Queries whole months (`dates=YYYYMM`); ESPN rejects date ranges. Matches games via event IDs stored on Game entities.
- **TournamentCalendar** — The current tournament year (the year of the next March; users never choose it) and the per-year Final Four pairing.

### Authentication

Authentication is **fully custom and bypasses Symfony's security component** — the
`security.yaml` firewall/provider config is vestigial. Login (`SecurityController`)
looks up the **User** entity by username, verifies the password with
`password_verify()`, and stores `user_id`/`username` in the session.

All authentication and authorization goes through `App\Security\SessionAuthenticator`:
`requireUser()`, `requireAdmin()`, and `requireBracketAccess($bracket)`. Do not use
`$this->getUser()` or `IsGranted`. `getUser()` returns null for a disabled account,
so disabling a user ejects them on their next request.

Accounts are created by **invitation only**. An admin sends an invite from `/admin`;
the recipient sets a username and password at `/invite/{token}`. Users are associated
with a bracket as player 1 (always the creator) or player 2. `app:user` remains as a
break-glass CLI path and is how the first admin is created:

    ddev exec php bin/console app:user <username> <password> --email=<address> --admin

Invite and password-reset tokens are stored as SHA-256 hashes; the raw token exists
only in the email. Every form and AJAX call carries a CSRF token under the id `app`.

### Tournament data

`app:tournament:sync` (cron, hourly in March–April) fills missing teams, refreshes spreads for rounds without picks, and pulls final scores for every bracket of the current year. Tests never call ESPN: `tests/Support/FakeEspn.php` is wired as the HTTP client's mock response factory in the test environment.

### Frontend

Tailwind CSS compiled via `symfonycasts/tailwind-bundle` (standalone Tailwind CLI, no Node.js). CSS source in `assets/styles/app.css`, JS in `assets/app.js`, served via Symfony AssetMapper. Run `tailwind:build` after changing Tailwind classes. Vanilla JS using fetch API for AJAX interactions (picks, spreads, scores). Pick assignment returns rendered Twig partial HTML that replaces the game card in-place.

### API Routes

All AJAX endpoints are POST and return JSON (except pick assignment which returns HTML):
- `/api/games/{id}/pick` — Assign pick + auto-assign opponent
- `/api/games/{id}/spread` — Set spread, re-evaluates picks if game complete
- `/api/brackets/{id}/pull-spreads` — Refresh spreads for a round; once the round has a pick, existing spreads are locked and only games still without a spread (and without picks) are filled
- `/api/brackets/{id}/pull-teams` — Fill empty first-round slots from ESPN (e.g. after the First Four)
- `/api/brackets/{id}/update-scores` — Pull scores from ESPN for a round
