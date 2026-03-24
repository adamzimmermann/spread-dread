# Spread Dread

A head-to-head NCAA Tournament bracket app where two players compete using point spreads. One player picks a team per game; the other automatically gets the opponent. Picks are evaluated against the spread, not outright winners.

Built with Symfony 7, Tailwind CSS, and vanilla JavaScript.

## Features

- **63-game bracket** automatically wired with correct NCAA seed matchups across all six rounds
- **Point spread competition** — picks are evaluated against the spread, not straight-up winners
- **Turn-based picking** — players alternate picks within each round, with pick progress tracking
- **ESPN integration** — pull tournament teams, spreads, and final scores directly from ESPN's API
- **Live score updates** — pull scores for completed games and automatically advance winners
- **Per-user authentication** — each player logs in with their own account
- **Mobile-friendly** — responsive card-based UI with sticky navigation and region tabs

## Requirements

- [DDEV](https://ddev.readthedocs.io/) (Docker-based local development)
- No Node.js required — Tailwind CSS is compiled via a standalone CLI binary

## Setup

```bash
git clone <repo-url> spread-dread
cd spread-dread
ddev start
ddev composer install
ddev exec php bin/console doctrine:migrations:migrate
ddev launch
```

### Create or Update Users

```bash
ddev exec php bin/console app:user <username> <password>
```

## Development

### Tailwind CSS

CSS is compiled from `assets/styles/app.css` using `symfonycasts/tailwind-bundle`. After changing Tailwind classes in templates or assets:

```bash
ddev exec php bin/console tailwind:build
```

For automatic rebuilds during development:

```bash
ddev exec php bin/console tailwind:build --watch
```

The built CSS (`var/tailwind/app.built.css`) is committed to the repo since the production server can't run the Tailwind binary. Always run `tailwind:build` locally before committing template changes.

### Database Changes

After modifying Doctrine entities:

```bash
ddev exec php bin/console doctrine:migrations:diff
ddev exec php bin/console doctrine:migrations:migrate
```

### Clear Cache

```bash
ddev exec php bin/console cache:clear
```

## How It Works

1. **Create a bracket** — set the tournament year and assign two players
2. **Pull teams** — fetch the 68-team field from ESPN, or enter teams manually
3. **Make picks** — players take turns picking teams; the opponent automatically gets the other team
4. **Pull spreads** — fetch point spreads from ESPN for each round
5. **Update scores** — pull final scores from ESPN; the app evaluates picks against the spread and advances winners to the next round
6. **Track the score** — a sticky scoreboard shows each player's running total

## Deployment

Deploys automatically to Dreamhost via GitHub Actions on push to `main`. The workflow SSHs into the server, pulls the latest code, installs dependencies, runs migrations, and compiles assets.

## Tech Stack

- **Backend:** PHP 8.4, Symfony 7, Doctrine ORM, MySQL 8.0
- **Frontend:** Tailwind CSS (compiled via standalone CLI), Symfony AssetMapper, vanilla JavaScript
- **Infrastructure:** DDEV (local), Dreamhost (production)
- **External APIs:** ESPN Scoreboard & Summary APIs
- **CI/CD:** GitHub Actions
