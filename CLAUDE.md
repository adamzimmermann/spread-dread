# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Environment

DDEV-based local development: PHP 8.2, MySQL 8.0, Apache.

```bash
ddev start                                              # Start containers
ddev composer install                                   # Install dependencies
ddev exec php bin/console doctrine:migrations:migrate   # Run migrations
ddev launch                                             # Open https://march-madness.ddev.site
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

Clear cache:
```bash
ddev exec php bin/console cache:clear
```

## Architecture

This is a Symfony 7 app for two players to compete on NCAA Tournament brackets using point spreads. One player picks a team per game; the other automatically gets the opponent. Picks are evaluated against the spread, not outright winners.

### Core Domain Model

**Bracket** is the aggregate root. Each bracket owns 63 **Game** entities forming a tournament tree via `Game.nextGame` self-references. Games link to **Team** entities (nullable until teams are assigned/advanced). **Pick** tracks which player chose which team per game. Picks have a nullable `isWinner` field set after spread evaluation.

The bracket tree wiring: odd `bracketPosition` feeds into `team1` of the next game, even feeds into `team2`. Region pairs East/West and South/Midwest merge in the Final Four.

### Key Services

- **BracketBuilderService** — Creates the 63-game bracket structure (32+16+8+4+2+1) with correct NCAA seed matchups and `nextGame` wiring.
- **ScoringService** — `evaluatePicks()` checks if picked team covered the spread. `advanceWinner()` populates the next game's team slot. `calculateScores()` returns per-player totals.
- **OddsApiService** — Pulls spreads and scores from The Odds API (`basketball_ncaab`), matches to games via fuzzy team name normalization.

### Authentication

Simple session-based shared password (not Symfony's security system). Password stored in `Setting` entity (default: "marchmadness"). All controllers call `requireAuth()` checking `$request->getSession()->get('authenticated')`.

### Frontend

No build step. Tailwind CSS via CDN. Vanilla JS (`public/js/app.js`) using fetch API for AJAX interactions (picks, spreads, scores). Pick assignment returns rendered Twig partial HTML that replaces the game card in-place.

### API Routes

All AJAX endpoints are POST and return JSON (except pick assignment which returns HTML):
- `/api/games/{id}/pick` — Assign pick + auto-assign opponent
- `/api/games/{id}/spread` — Set spread, re-evaluates picks if game complete
- `/api/games/{id}/score` — Set scores, determine winner, evaluate picks, advance winner
- `/api/brackets/{id}/pull-spreads` — Pull spreads from Odds API for a round
- `/api/brackets/{id}/update-scores` — Pull scores from Odds API for a round
