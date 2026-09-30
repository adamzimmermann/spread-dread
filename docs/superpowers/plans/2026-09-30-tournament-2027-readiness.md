# 2027 Tournament Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the app run the 2027 NCAA tournament without anyone choosing a year, creating a broken bracket, or working from stale data.

**Architecture:** A new `TournamentCalendar` service owns "which tournament is current" (derived from the date) and the per-year Final Four pairing (an admin setting in the existing `setting` table). `EspnApiService` switches to month-based scoreboard queries, which is the only form ESPN still accepts, and fetches each month once per request. Bracket creation is refused until the field and pairing are known. A console command, run hourly by cron during March and April, pulls teams, spreads and scores for every bracket of the current year. Spreads keep refreshing until the first pick of a round is made, then stay fixed.

**Tech Stack:** Symfony 7.2, PHP 8.4, Doctrine ORM 3, MySQL (8.0 locally, 5.x in production), Twig, Tailwind (standalone CLI), vanilla JS over AssetMapper, PHPUnit 13 with `dama/doctrine-test-bundle`, Symfony `MockHttpClient` for ESPN in tests.

**Spec:** No separate spec document. The requirements were agreed in the session of 2026-09-30 and are recorded under **Decisions** below. Executors read that section as the spec.

## Decisions

1. **Users never choose the year.** The current tournament year is the year of the next March: January–April is the current calendar year, May–December is the following one. On 2026-09-30 that is 2027. Existing brackets keep their stored year.
2. **Bracket creation is refused until the tournament is set.** Both must be true: ESPN lists at least 60 first-round teams for the year (60, not 64, because up to four slots wait on First Four games), and an admin has recorded that year's Final Four pairing. Otherwise nothing is created and the user is told brackets open after Selection Sunday.
3. **Final Four pairings are set per year by an admin**, as "which region plays East". The NCAA sets them each year (2024 was East–West / South–Midwest; 2025 was East–Midwest / South–West).
4. **Missing teams can be filled in later.** `POST /api/brackets/{id}/pull-teams` is restored, and the bracket page shows a button for it when first-round slots are empty.
5. **Spreads refresh until the round's first pick, then lock.** Once any game in a round of a bracket has a pick, pulling spreads for that round changes nothing. Manually editing a single game's spread stays allowed; that is out of scope here.
6. **Scores, teams and spreads sync on a schedule.** `app:tournament:sync` runs hourly from cron during March and April, to go easy on ESPN's free API. The buttons stay as a manual override.
7. **Dead code is removed:** `OddsApiService` (never called), `templates/bracket/teams.html.twig` (no route renders it), and the `Round` entity/table (rows are written per bracket and never read).

## Evidence gathered while planning (2026-09-30)

- **ESPN rejects date ranges.** `scoreboard?dates=20260318-20260322&groups=100&limit=100`, the exact query the app sends today, returns `HTTP 400 {"code":400,"message":"Failed to get events endpoint."}`. Every range tried failed. The app swallows the exception, so team loading and score updates are silently broken now.
- **Month queries work and cover the whole tournament.** `dates=202603&groups=100&limit=300` returns 64 events: First Four 4, 1st Round 32, 2nd Round 16, Sweet 16 8, Elite 8 4. `dates=202604&groups=100&limit=300` returns 3: Final Four 2, National Championship 1. Single days (`dates=20260319`) also work.
- **Spreads endpoint still works:** `summary?event=<id>` returns `pickcenter[0]` with `spread` and `homeTeamOdds.favorite`/`awayTeamOdds.favorite`.
- **Elite 8 wiring bug:** `BracketBuilderService` creates all four Elite 8 games with `bracketPosition = 1`. `ScoringService::advanceWinner()` sends odd positions to `team1`, so both regional champions overwrite `team1` of the same Final Four game. Confirmed in the local 2026 bracket's rows.
- Headlines differ by year: "NCAA Men's Basketball Championship - East Region - 1st Round" (2026) versus "Men's Basketball Championship - Midwest Region - 1st Round" (2025). Match on `Men's Basketball Championship`.

## Global Constraints

- **The repository is PUBLIC.** No real email address, server path, credential or other personal data in any tracked file, commit message or test. Test data uses `@example.com`; docs use placeholders such as `<app path>`.
- Authentication is custom. Use `SessionAuthenticator` (`requireUser()`, `requireAdmin()`, `requireBracketAccess()`). Never use `$this->getUser()` or `IsGranted`.
- Every state-changing route is POST and carries a CSRF token with id `app`.
- PHPUnit fails on deprecations, notices and warnings (`phpunit.dist.xml`).
- Tests share the dev database and each test rolls back. Use unique usernames per test.
- Run everything inside DDEV: `ddev exec php bin/console …`, `ddev exec php bin/phpunit …`, `ddev composer test`.
- After changing any service's constructor, run `ddev exec rm -rf var/cache/test` before running tests. `cache:clear --env=test` has been observed not to rebuild the container.
- After template changes run `ddev exec php bin/console tailwind:build` and commit `var/tailwind/app.built.css`.
- Migrations must run on MySQL 5.x (production). No 8.0-only syntax.
- No test may call the real ESPN API. Task 3 installs a fake; tasks before it do not touch ESPN.

## Review Focus

1. **ESPN is down or returns 400 while someone creates a bracket.** No bracket is created, and the page says brackets aren't open yet instead of creating an empty one. Pinned by `testCreateIsRefusedWhenEspnFails` in Task 4.
2. **Play-in slots before the First Four is played.** ESPN may list a placeholder ("TBD", or "Team A/Team B"). It must not be saved as a team; the slot stays empty until a later pull fills it. Pinned by `testPlaceholderCompetitorsAreNotSavedAsTeams` in Task 5.
3. **Refreshing teams after picks exist.** Picks already made must still point at the same teams afterwards. Pinned by `testRefreshKeepsExistingPicks` in Task 5.
4. **ESPN drops the line once a game starts** (empty `pickcenter`) for a game that already has a spread in an unlocked round. The spread is kept and no "set manually" warning appears. Pinned by `testMissingLineKeepsExistingSpread` in Task 6.
5. **The sync command runs repeatedly, and last year's brackets exist.** A second run changes nothing and doesn't double-count picks; brackets from other years are untouched. Pinned by `testSyncIsIdempotentAndIgnoresOtherYears` in Task 7.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `src/Service/TournamentCalendar.php` | Current tournament year; per-year Final Four pairing |
| `src/Command/TournamentSyncCommand.php` | `app:tournament:sync`: teams, spreads, scores for the current year |
| `tests/Support/FakeEspn.php` | `MockHttpClient` response factory that imitates ESPN's scoreboard and summary endpoints |
| `config/services_test.yaml` | Registers `FakeEspn` in the test container |
| `tests/Service/TournamentCalendarTest.php` | Year rule and pairing tests |
| `tests/Functional/EspnApiServiceTest.php` | Month queries, memoisation, placeholder filtering |
| `tests/Functional/BracketCreateGateTest.php` | Creation refused or allowed |
| `tests/Functional/PullTeamsTest.php` | Refilling empty slots |
| `tests/Functional/SpreadLockTest.php` | Spread refresh and lock |
| `tests/Functional/TournamentSyncCommandTest.php` | Cron command |
| `migrations/Version20261001000000.php` | Drops the `round` table |

**Modified:**

| File | Change |
|---|---|
| `src/Service/BracketBuilderService.php` | Elite 8 positions; pairing passed in; stop writing `Round` rows |
| `src/Service/EspnApiService.php` | Month queries, memoised; placeholder filter; logging; lock-aware spread refresh |
| `src/Service/ScoringService.php` | `settleRound()` |
| `src/Repository/GameRepository.php` | `roundHasPicks()`, `countMissingFirstRoundTeams()` |
| `src/Controller/BracketController.php` | Server-chosen year, creation gate, `pull-teams` route, lock flag |
| `src/Controller/AdminController.php` | Final Four pairing form |
| `src/Entity/Game.php` | Owns round names (moved from `Round`) |
| `templates/bracket/create.html.twig` | No year input; closed-state message |
| `templates/bracket/show.html.twig` | "Load missing teams" button; locked spreads button |
| `templates/admin/dashboard.html.twig` | Tournament section |
| `assets/app.js` | New `pullTeams`; spreads lock handling |
| `config/packages/framework.yaml` | `when@test` mock HTTP client |
| `config/services.yaml`, `.env.example` | Drop Odds API wiring |
| `tests/Functional/BracketBuilderServiceTest.php` | Pass pairing; wiring tests |
| `CLAUDE.md`, `README.md` | Routes, sync command, cron, yearly checklist |

**Deleted:** `src/Service/OddsApiService.php`, `src/Entity/Round.php`, `src/Repository/RoundRepository.php`, `templates/bracket/teams.html.twig`.

---

### Task 1: Fix Elite 8 → Final Four wiring

**Files:**
- Modify: `src/Service/BracketBuilderService.php:66-73`
- Test: `tests/Functional/BracketBuilderServiceTest.php`

**Interfaces:**
- Consumes: `ScoringService::advanceWinner(Game $game): void` (existing; odd `bracketPosition` → `team1`, even → `team2`).
- Produces: in every bracket, the two Elite 8 games feeding one Final Four game have `bracketPosition` 1 and 2.

- [ ] **Step 1: Write the failing tests** (append to `BracketBuilderServiceTest`)

```php
    public function testEachFinalFourGameIsFedByPositionsOneAndTwo(): void
    {
        $bracket = $this->createBracket($this->createUser('bb_e8pos_p1'), $this->createUser('bb_e8pos_p2'));
        static::getContainer()->get(BracketBuilderService::class)->buildBracket($bracket);

        $feeders = [];
        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 4]) as $e8) {
            $feeders[$e8->getNextGame()->getBracketPosition()][] = $e8->getBracketPosition();
        }

        foreach ($feeders as $ffPosition => $positions) {
            sort($positions);
            $this->assertSame([1, 2], $positions, "Final Four game $ffPosition");
        }
    }

    public function testBothRegionalChampionsReachTheFinalFour(): void
    {
        $bracket = $this->createBracket($this->createUser('bb_e8adv_p1'), $this->createUser('bb_e8adv_p2'));
        static::getContainer()->get(BracketBuilderService::class)->buildBracket($bracket);
        $scoring = static::getContainer()->get(\App\Service\ScoringService::class);

        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 4]) as $e8) {
            $champ = $this->createTeam($e8->getRegion() . ' Champ', 1, $e8->getRegion());
            $e8->setTeam1($champ)->setTeam2($this->createTeam($e8->getRegion() . ' Runner', 2, $e8->getRegion()));
            $e8->setTeam1Score(70)->setTeam2Score(60)->setWinner($champ)->setIsComplete(true);
            $this->em->flush();
            $scoring->advanceWinner($e8);
        }

        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 5]) as $ff) {
            $this->assertNotNull($ff->getTeam1(), 'Final Four team1');
            $this->assertNotNull($ff->getTeam2(), 'Final Four team2');
        }
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php bin/phpunit --filter 'testEachFinalFourGameIsFedByPositionsOneAndTwo|testBothRegionalChampionsReachTheFinalFour'`
Expected: FAIL. The first gets `[1, 1]`; the second gets a null `team2`.

- [ ] **Step 3: Fix the position**

In `buildBracket()`, the Elite 8 game is created inside `foreach ($regions as $regionIndex => $region)`. Change

```php
                $e8 = $this->createGame($bracket, 4, $region, 1);
```
to
```php
                // First region of the pair feeds team1, second feeds team2.
                $e8 = $this->createGame($bracket, 4, $region, $regionIndex + 1);
```

- [ ] **Step 4: Run the builder tests**

Run: `ddev exec php bin/phpunit tests/Functional/BracketBuilderServiceTest.php`
Expected: PASS (all six).

- [ ] **Step 5: Commit**

```bash
git add src/Service/BracketBuilderService.php tests/Functional/BracketBuilderServiceTest.php
git commit -m "Fix Elite 8 positions so both regional champions reach the Final Four"
```

---

### Task 2: TournamentCalendar, per-year Final Four pairing, admin form

**Files:**
- Create: `src/Service/TournamentCalendar.php`, `tests/Service/TournamentCalendarTest.php`
- Modify: `src/Service/BracketBuilderService.php`, `src/Controller/AdminController.php`, `templates/admin/dashboard.html.twig`
- Test: `tests/Functional/BracketBuilderServiceTest.php`, `tests/Functional/AdminControllerTest.php`

**Interfaces:**
- Consumes: `SettingRepository::get(string $key, string $default = ''): string`, `SettingRepository::set(string $key, string $value): void` (existing).
- Produces:
  - `TournamentCalendar::activeYear(?\DateTimeImmutable $now = null): int`
  - `TournamentCalendar::eastOpponent(int $year): ?string`: `'West'|'South'|'Midwest'|null`
  - `TournamentCalendar::setEastOpponent(int $year, string $region): void`: throws `\InvalidArgumentException` for anything else
  - `TournamentCalendar::finalFourPairs(int $year): ?array`: `[['East', X], [Y, Z]]` or `null` when unset
  - `TournamentCalendar::DEFAULT_PAIRS`: `[['East', 'West'], ['South', 'Midwest']]`, for tests and legacy callers only
  - `BracketBuilderService::buildBracket(Bracket $bracket, array $finalFourPairs = TournamentCalendar::DEFAULT_PAIRS): void`
  - Route `POST /admin/tournament` named `app_admin_tournament`, fields `year`, `east_opponent`

- [ ] **Step 1: Write the failing unit tests**

`tests/Service/TournamentCalendarTest.php`:

```php
<?php

namespace App\Tests\Service;

use App\Repository\SettingRepository;
use App\Service\TournamentCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TournamentCalendarTest extends TestCase
{
    private array $store = [];

    private function calendar(): TournamentCalendar
    {
        $settings = $this->createStub(SettingRepository::class);
        $settings->method('get')->willReturnCallback(fn (string $k, string $d = '') => $this->store[$k] ?? $d);
        $settings->method('set')->willReturnCallback(function (string $k, string $v): void { $this->store[$k] = $v; });
        return new TournamentCalendar($settings);
    }

    public static function dates(): array
    {
        return [
            'January'  => ['2027-01-15', 2027],
            'March'    => ['2027-03-20', 2027],
            'April'    => ['2027-04-30', 2027],
            'May'      => ['2027-05-01', 2028],
            'this fall' => ['2026-09-30', 2027],
            'December' => ['2026-12-31', 2027],
        ];
    }

    #[DataProvider('dates')]
    public function testActiveYearIsTheYearOfTheNextMarch(string $date, int $expected): void
    {
        $this->assertSame($expected, $this->calendar()->activeYear(new \DateTimeImmutable($date)));
    }

    public function testPairsAreNullUntilSet(): void
    {
        $this->assertNull($this->calendar()->finalFourPairs(2027));
    }

    public function testPairsFollowTheEastOpponent(): void
    {
        $calendar = $this->calendar();
        $calendar->setEastOpponent(2027, 'Midwest');
        $this->assertSame([['East', 'Midwest'], ['West', 'South']], $calendar->finalFourPairs(2027));
        $this->assertNull($calendar->finalFourPairs(2028), 'Pairing is per year');
    }

    public function testRejectsUnknownRegion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calendar()->setEastOpponent(2027, 'East');
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php bin/phpunit tests/Service/TournamentCalendarTest.php`
Expected: FAIL with `Class "App\Service\TournamentCalendar" not found`.

- [ ] **Step 3: Implement `TournamentCalendar`**

```php
<?php

namespace App\Service;

use App\Repository\SettingRepository;

/**
 * Which NCAA tournament is current, and how its regions pair in the Final Four.
 * Users never choose a year: it is always the year of the next March.
 */
class TournamentCalendar
{
    public const DEFAULT_PAIRS = [['East', 'West'], ['South', 'Midwest']];
    private const EAST_OPPONENTS = ['West', 'South', 'Midwest'];

    public function __construct(private SettingRepository $settings)
    {
    }

    public function activeYear(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $year = (int) $now->format('Y');

        // The tournament ends in early April; from May on, the next one is current.
        return (int) $now->format('n') >= 5 ? $year + 1 : $year;
    }

    public function eastOpponent(int $year): ?string
    {
        $value = $this->settings->get(self::key($year));
        return in_array($value, self::EAST_OPPONENTS, true) ? $value : null;
    }

    public function setEastOpponent(int $year, string $region): void
    {
        if (!in_array($region, self::EAST_OPPONENTS, true)) {
            throw new \InvalidArgumentException("East cannot play '$region' in the Final Four.");
        }
        $this->settings->set(self::key($year), $region);
    }

    /** @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}|null */
    public function finalFourPairs(int $year): ?array
    {
        $east = $this->eastOpponent($year);
        if ($east === null) {
            return null;
        }
        $others = array_values(array_diff(self::EAST_OPPONENTS, [$east]));
        return [['East', $east], [$others[0], $others[1]]];
    }

    private static function key(int $year): string
    {
        return 'final_four_east_opponent_' . $year;
    }
}
```

- [ ] **Step 4: Run the unit tests**

Run: `ddev exec php bin/phpunit tests/Service/TournamentCalendarTest.php`
Expected: PASS (9 including data-provider cases).

- [ ] **Step 5: Write the failing builder test** (append to `BracketBuilderServiceTest`)

```php
    public function testFinalFourFollowsTheGivenPairing(): void
    {
        $bracket = $this->createBracket($this->createUser('bb_pair_p1'), $this->createUser('bb_pair_p2'));
        static::getContainer()->get(BracketBuilderService::class)
            ->buildBracket($bracket, [['East', 'South'], ['West', 'Midwest']]);

        $byFinalFour = [];
        foreach ($this->em->getRepository(Game::class)->findBy(['bracket' => $bracket, 'roundNumber' => 4]) as $e8) {
            $byFinalFour[$e8->getNextGame()->getBracketPosition()][$e8->getBracketPosition()] = $e8->getRegion();
        }

        $this->assertSame([1 => 'East', 2 => 'South'], $byFinalFour[1]);
        $this->assertSame([1 => 'West', 2 => 'Midwest'], $byFinalFour[2]);
    }
```

- [ ] **Step 6: Run to verify it fails**

Run: `ddev exec php bin/phpunit --filter testFinalFourFollowsTheGivenPairing`
Expected: FAIL (East feeds FF1 with West, ignoring the argument).

- [ ] **Step 7: Take the pairing as a parameter**

In `BracketBuilderService` (same namespace as `TournamentCalendar`, so no import), change the signature and remove the hard-coded pairs:

```php
    /**
     * @param array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}} $finalFourPairs
     *        Regions meeting in Final Four game 1 and game 2 (see TournamentCalendar::finalFourPairs()).
     */
    public function buildBracket(Bracket $bracket, array $finalFourPairs = TournamentCalendar::DEFAULT_PAIRS): void
```

Replace the `$regionPairs = [ … ];` block and the comment above it with:

```php
        // Rounds 1-4: Regional rounds. Each region feeds one team to the Final
        // Four; which regions meet is set per year (TournamentCalendar).
        $regionPairs = $finalFourPairs;
```

- [ ] **Step 8: Write the failing admin tests** (append to `AdminControllerTest`)

```php
    public function testAdminSetsTheFinalFourPairing(): void
    {
        $this->createUser('adm_ff_admin', 'password', null, true);
        $this->loginViaForm('adm_ff_admin');
        $calendar = static::getContainer()->get(\App\Service\TournamentCalendar::class);
        $year = $calendar->activeYear();

        $crawler = $this->client->request('GET', '/admin');
        $this->assertSelectorTextContains('body', "Tournament $year");
        $form = $crawler->selectButton('Save pairing')->form(['east_opponent' => 'South']);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin');

        $calendar = static::getContainer()->get(\App\Service\TournamentCalendar::class);
        $this->assertSame('South', $calendar->eastOpponent($year));
    }

    public function testInvalidPairingIsRejected(): void
    {
        $this->createUser('adm_ffbad_admin', 'password', null, true);
        $this->loginViaForm('adm_ffbad_admin');
        $year = static::getContainer()->get(\App\Service\TournamentCalendar::class)->activeYear();

        $this->client->request('POST', '/admin/tournament', [
            '_token' => $this->csrfToken('/admin'),
            'year' => $year,
            'east_opponent' => 'East',
        ]);
        $this->assertResponseRedirects('/admin');
        $this->assertNull(static::getContainer()->get(\App\Service\TournamentCalendar::class)->eastOpponent($year));
    }

    public function testNonAdminCannotSetThePairing(): void
    {
        $this->createUser('adm_ffplain_user');
        $this->loginViaForm('adm_ffplain_user');
        $token = $this->csrfToken();

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('POST', '/admin/tournament', ['_token' => $token, 'year' => 2027, 'east_opponent' => 'West']);
    }
```

- [ ] **Step 9: Run to verify they fail**

Run: `ddev exec php bin/phpunit --filter 'testAdminSetsTheFinalFourPairing|testInvalidPairingIsRejected|testNonAdminCannotSetThePairing'`
Expected: FAIL. There's no "Tournament" section, and the route returns 404 (`NotFoundHttpException` in the third test instead of `AccessDeniedException`).

- [ ] **Step 10: Add the route and the dashboard section**

`AdminController`: add `use App\Service\TournamentCalendar;`. In `dashboard()` add the `TournamentCalendar $calendar` argument and pass:

```php
            'tournamentYear' => $calendar->activeYear(),
            'eastOpponent' => $calendar->eastOpponent($calendar->activeYear()),
```

New action, placed after `revokeInvite()`:

```php
    #[Route('/admin/tournament', name: 'app_admin_tournament', methods: ['POST'])]
    public function setTournament(
        Request $request,
        SessionAuthenticator $auth,
        TournamentCalendar $calendar,
    ): Response {
        $auth->requireAdmin();
        $this->assertCsrf($request);

        $year = (int) $request->request->get('year');
        try {
            $calendar->setEastOpponent($year, (string) $request->request->get('east_opponent'));
            $this->addFlash('success', "Final Four pairing saved for $year.");
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Pick which region plays East.');
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }
```

`templates/admin/dashboard.html.twig`: insert after the "Invite someone" section:

```twig
<section class="bg-white rounded-lg shadow p-4 mb-6">
    <h2 class="font-semibold text-gray-800 mb-1">Tournament {{ tournamentYear }}</h2>
    <p class="text-sm text-gray-600 mb-3">
        Set this on Selection Sunday. Nobody can create a {{ tournamentYear }} bracket until it is set.
    </p>
    {% if eastOpponent is null %}
        <p class="text-sm text-amber-700 mb-3">Final Four pairing not set yet.</p>
    {% endif %}
    <form method="post" action="{{ path('app_admin_tournament') }}" class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="_token" value="{{ csrf_token('app') }}">
        <input type="hidden" name="year" value="{{ tournamentYear }}">
        <label for="east_opponent" class="text-sm text-gray-700">In the Final Four, East plays</label>
        <select name="east_opponent" id="east_opponent" required
                class="border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">Choose…</option>
            {% for region in ['West', 'South', 'Midwest'] %}
                <option value="{{ region }}" {{ eastOpponent == region ? 'selected' }}>{{ region }}</option>
            {% endfor %}
        </select>
        <button type="submit" class="bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-800">
            Save pairing
        </button>
    </form>
</section>
```

- [ ] **Step 11: Rebuild CSS, clear the test cache, run the suite**

Run: `ddev exec php bin/console tailwind:build && ddev exec rm -rf var/cache/test && ddev composer test`
Expected: PASS. The existing `buildBracket($bracket)` calls use the default pairing.

- [ ] **Step 12: Commit**

```bash
git add src/Service/TournamentCalendar.php src/Service/BracketBuilderService.php src/Controller/AdminController.php templates/admin/dashboard.html.twig var/tailwind/app.built.css tests/Service/TournamentCalendarTest.php tests/Functional/BracketBuilderServiceTest.php tests/Functional/AdminControllerTest.php
git commit -m "Add TournamentCalendar: derived tournament year and per-year Final Four pairing"
```

---

### Task 3: ESPN fake for tests; month-based queries, memoised

**Files:**
- Create: `tests/Support/FakeEspn.php`, `config/services_test.yaml`, `tests/Functional/EspnApiServiceTest.php`
- Modify: `config/packages/framework.yaml`, `src/Service/EspnApiService.php`

**Interfaces:**
- Produces (test support, used by Tasks 4–7):
  - `FakeEspn::reset(): void`: clears all state; call in each ESPN-touching test's `setUp()` after `parent::setUp()`
  - `FakeEspn::firstRound(int $year, array $placeholders = []): void`: puts 32 first-round events into March of `$year`. Teams are named `"{Region} {seed}"` (e.g. `East 1`) and event ids are `"{year}-{Region}-{seedA}-{seedB}"`. `$placeholders` lists `"{Region}_{seed}"` slots to show as a TBD competitor.
  - `FakeEspn::addEvent(int $year, int $month, array $event): void`
  - `FakeEspn::event(string $id, string $headline, array $home, array $away, bool $completed = false): array`, where `$home`/`$away` = `['name' => string, 'seed' => ?int, 'score' => ?int]`
  - `FakeEspn::setSummary(string $eventId, string $homeName, string $awayName, ?float $spread, bool $homeFavored = true): void`. A `$spread` of `null` means an empty `pickcenter`.
  - `FakeEspn::$failAll` (bool): every request returns HTTP 500
  - `FakeEspn::$requests` (list<string>): every URL requested
- Produces (app):
  - `EspnApiService::fetchTournamentEvents(int $year): array`: all tournament events for March and April, fetched once per service instance
  - `EspnApiService::MIN_FIELD_SIZE = 60`
  - `EspnApiService::tournamentFieldIsSet(int $year): bool`
  - Constructor gains `Psr\Log\LoggerInterface $logger`
  - `EspnApiService` implements `Symfony\Contracts\Service\ResetInterface`; `reset(): void` clears the memoised events (and, from Task 7, summaries). Symfony calls it between requests; tests call it when they change `FakeEspn` mid-test.

- [ ] **Step 1: Create the fake**

`tests/Support/FakeEspn.php`:

```php
<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for ESPN's scoreboard and summary endpoints in tests
 * (framework.http_client.mock_response_factory). State is static because the
 * test client reboots the kernel between requests, discarding instances.
 *
 * Mirrors ESPN's real behaviour observed 2026-09-30: `dates` must be YYYYMM or
 * YYYYMMDD; a range (YYYYMMDD-YYYYMMDD) is answered with HTTP 400.
 */
final class FakeEspn
{
    private const SEED_MATCHUPS = [[1, 16], [8, 9], [5, 12], [4, 13], [6, 11], [3, 14], [7, 10], [2, 15]];

    /** @var array<string, list<array>> events keyed by YYYYMM */
    public static array $months = [];
    /** @var array<string, array> summary payloads keyed by event id */
    public static array $summaries = [];
    /** @var list<string> */
    public static array $requests = [];
    public static bool $failAll = false;

    public static function reset(): void
    {
        self::$months = [];
        self::$summaries = [];
        self::$requests = [];
        self::$failAll = false;
    }

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$requests[] = $url;
        if (self::$failAll) {
            return new MockResponse('{"code":500}', ['http_code' => 500]);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_ends_with($path, '/scoreboard')) {
            $dates = (string) ($query['dates'] ?? '');
            if (!preg_match('/^\d{6}(\d{2})?$/', $dates)) {
                return new MockResponse('{"code":400,"message":"Failed to get events endpoint."}', ['http_code' => 400]);
            }
            return new MockResponse(json_encode(['events' => self::$months[substr($dates, 0, 6)] ?? []]));
        }

        if (str_ends_with($path, '/summary')) {
            $id = (string) ($query['event'] ?? '');
            return isset(self::$summaries[$id])
                ? new MockResponse(json_encode(self::$summaries[$id]))
                : new MockResponse('{}', ['http_code' => 404]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }

    public static function addEvent(int $year, int $month, array $event): void
    {
        self::$months[sprintf('%d%02d', $year, $month)][] = $event;
    }

    public static function firstRound(int $year, array $placeholders = []): void
    {
        foreach (['East', 'West', 'South', 'Midwest'] as $region) {
            foreach (self::SEED_MATCHUPS as [$a, $b]) {
                $side = static fn (int $seed): array => in_array("{$region}_{$seed}", $placeholders, true)
                    ? ['name' => 'TBD', 'seed' => null, 'score' => null]
                    : ['name' => "$region $seed", 'seed' => $seed, 'score' => null];

                self::addEvent($year, 3, self::event(
                    "$year-$region-$a-$b",
                    "NCAA Men's Basketball Championship - $region Region - 1st Round",
                    $side($a),
                    $side($b),
                ));
            }
        }
    }

    public static function event(string $id, string $headline, array $home, array $away, bool $completed = false): array
    {
        $competitor = static fn (array $side, string $homeAway): array => array_filter([
            'homeAway' => $homeAway,
            'team' => ['displayName' => $side['name']],
            'curatedRank' => $side['seed'] !== null ? ['current' => $side['seed']] : null,
            'score' => $side['score'] !== null ? (string) $side['score'] : null,
        ], static fn ($v) => $v !== null);

        return [
            'id' => $id,
            'competitions' => [[
                'notes' => [['headline' => $headline]],
                'competitors' => [$competitor($home, 'home'), $competitor($away, 'away')],
                'status' => ['type' => ['completed' => $completed]],
            ]],
        ];
    }

    public static function setSummary(string $eventId, string $homeName, string $awayName, ?float $spread, bool $homeFavored = true): void
    {
        self::$summaries[$eventId] = [
            'header' => ['competitions' => [['competitors' => [
                ['homeAway' => 'home', 'team' => ['displayName' => $homeName]],
                ['homeAway' => 'away', 'team' => ['displayName' => $awayName]],
            ]]]],
            'pickcenter' => $spread === null ? [] : [[
                'spread' => $homeFavored ? -abs($spread) : abs($spread),
                'homeTeamOdds' => ['favorite' => $homeFavored],
                'awayTeamOdds' => ['favorite' => !$homeFavored],
            ]],
        ];
    }
}
```

`config/services_test.yaml`:

```yaml
services:
    App\Tests\Support\FakeEspn: ~
```

`config/packages/framework.yaml`: under the existing `when@test: framework:` key add:

```yaml
        http_client:
            mock_response_factory: App\Tests\Support\FakeEspn
```

- [ ] **Step 2: Write the failing tests**

`tests/Functional/EspnApiServiceTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Service\EspnApiService;
use App\Tests\Support\FakeEspn;

class EspnApiServiceTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    private function espn(): EspnApiService
    {
        return static::getContainer()->get(EspnApiService::class);
    }

    public function testTeamsComeFromMonthQueriesNotRanges(): void
    {
        FakeEspn::firstRound(2027);

        $result = $this->espn()->fetchTournamentTeams(2027);

        $this->assertCount(64, $result['teams']);
        $this->assertCount(32, $result['matchups']);
        foreach (FakeEspn::$requests as $url) {
            $this->assertMatchesRegularExpression('/dates=2027(03|04)(&|$)/', $url);
        }
    }

    public function testEventsAreFetchedOncePerService(): void
    {
        FakeEspn::firstRound(2027);
        $espn = $this->espn();

        $espn->fetchTournamentEvents(2027);
        $espn->fetchTournamentEvents(2027);

        $this->assertCount(2, FakeEspn::$requests, 'One request per month, then cached');
    }

    public function testAprilGamesAreIncluded(): void
    {
        FakeEspn::addEvent(2027, 4, FakeEspn::event(
            'final', "NCAA Men's Basketball Championship - National Championship",
            ['name' => 'East 1', 'seed' => 1, 'score' => 70], ['name' => 'West 1', 'seed' => 1, 'score' => 65], true,
        ));

        $ids = array_column($this->espn()->fetchTournamentEvents(2027), 'id');
        $this->assertContains('final', $ids);
    }

    public function testFieldIsSetOnlyWithSixtyTeams(): void
    {
        $this->assertFalse($this->espn()->tournamentFieldIsSet(2027), 'Nothing listed yet');

        FakeEspn::reset();
        $this->espn()->reset(); // The empty result above is memoised.
        FakeEspn::firstRound(2027, ['East_16', 'West_16', 'South_11', 'Midwest_11']);
        $this->assertTrue($this->espn()->tournamentFieldIsSet(2027), 'Four First Four slots still open');
    }

    public function testFailureIsReportedAsNoTeams(): void
    {
        FakeEspn::$failAll = true;
        $result = $this->espn()->fetchTournamentTeams(2027);
        $this->assertSame([], $result['teams']);
        $this->assertArrayHasKey('error', $result);
    }
}
```

- [ ] **Step 3: Run to verify they fail**

Run: `ddev exec rm -rf var/cache/test && ddev exec php bin/phpunit tests/Functional/EspnApiServiceTest.php`
Expected: FAIL. The range query gets 400 from the fake, so teams come back empty, and `fetchTournamentEvents`/`tournamentFieldIsSet` are undefined.

- [ ] **Step 4: Rewrite the fetching in `EspnApiService`**

Declare `class EspnApiService implements ResetInterface` (import `Symfony\Contracts\Service\ResetInterface`; autoconfigure tags it `kernel.reset`). Constructor: add `private LoggerInterface $logger,` (import `Psr\Log\LoggerInterface`). Add:

```php
    /** First-round teams needed before brackets open; up to four slots can wait on the First Four. */
    public const MIN_FIELD_SIZE = 60;

    /** @var array<int, array> tournament events per year, fetched once per request/command run */
    private array $eventsByYear = [];
```

Delete `fetchFirstRoundEvents()` and `fetchTournamentScoreboard()`. Add:

```php
    /**
     * Every NCAA tournament event for $year. ESPN rejects date ranges
     * (HTTP 400, observed 2026-09-30) but accepts whole months, and the
     * tournament always falls in March and April.
     */
    public function fetchTournamentEvents(int $year): array
    {
        if (isset($this->eventsByYear[$year])) {
            return $this->eventsByYear[$year];
        }

        $events = [];
        foreach (['03', '04'] as $month) {
            try {
                $data = $this->httpClient->request('GET', self::SCOREBOARD_URL, [
                    'query' => ['dates' => $year . $month, 'groups' => 100, 'limit' => 300],
                ])->toArray();
            } catch (\Throwable $e) {
                $this->logger->warning('ESPN scoreboard request failed', ['month' => $year . $month, 'error' => $e->getMessage()]);
                return []; // Not cached, so the next call retries.
            }

            foreach ($data['events'] ?? [] as $event) {
                $headline = $event['competitions'][0]['notes'][0]['headline'] ?? '';
                if (str_contains($headline, "Men's Basketball Championship")) {
                    $events[] = $event;
                }
            }
        }

        return $this->eventsByYear[$year] = $events;
    }

    public function reset(): void
    {
        $this->eventsByYear = [];
    }

    public function tournamentFieldIsSet(int $year): bool
    {
        return count($this->fetchTournamentTeams($year)['teams']) >= self::MIN_FIELD_SIZE;
    }
```

In `fetchTournamentTeams()`, replace `$events = $this->fetchFirstRoundEvents($year);` with `$events = $this->fetchTournamentEvents($year);`. The loop already skips events whose headline lacks `1st Round`.

In `updateScores()` replace `$this->fetchTournamentScoreboard($year)` with `$this->fetchTournamentEvents($year)`; do the same in `findEspnEventId()`.

In `fetchEventSummary()`, log in the `catch` before `return null`:

```php
            $this->logger->warning('ESPN summary request failed', ['event' => $eventId, 'error' => $e->getMessage()]);
```

- [ ] **Step 5: Run the tests**

Run: `ddev exec rm -rf var/cache/test && ddev exec php bin/phpunit tests/Functional/EspnApiServiceTest.php`
Expected: PASS (5).

- [ ] **Step 6: Check against the real API once, by hand**

Run: `curl -s --compressed 'https://site.api.espn.com/apis/site/v2/sports/basketball/mens-college-basketball/scoreboard?dates=202603&groups=100&limit=300' | head -c 200`
Expected: JSON beginning `{"leagues":`. This is not a test; it only confirms the query shape still works.

- [ ] **Step 7: Full suite, then commit**

Run: `ddev composer test`. Expected: PASS.

```bash
git add tests/Support/FakeEspn.php config/services_test.yaml config/packages/framework.yaml src/Service/EspnApiService.php tests/Functional/EspnApiServiceTest.php
git commit -m "Query ESPN by month (date ranges now return 400) and fetch once per request"
```

---

### Task 4: Server-chosen year and the creation gate

**Files:**
- Modify: `src/Controller/BracketController.php` (`create()`), `templates/bracket/create.html.twig`
- Create: `tests/Functional/BracketCreateGateTest.php`

**Interfaces:**
- Consumes: `TournamentCalendar::activeYear()`, `TournamentCalendar::finalFourPairs(int)`, `BracketBuilderService::buildBracket(Bracket, array)`, `EspnApiService::tournamentFieldIsSet(int)`, `EspnApiService::populateBracketTeams(Bracket)`, `FakeEspn`.
- Produces: `bracket/create.html.twig` receives `year` (int) and `open` (bool); the form has no `year` input.

- [ ] **Step 1: Write the failing tests**

`tests/Functional/BracketCreateGateTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Entity\Bracket;
use App\Service\TournamentCalendar;
use App\Tests\Support\FakeEspn;

class BracketCreateGateTest extends WebTestCase
{
    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
        $this->year = static::getContainer()->get(TournamentCalendar::class)->activeYear();
    }

    private function openTournament(): void
    {
        FakeEspn::firstRound($this->year);
        static::getContainer()->get(TournamentCalendar::class)->setEastOpponent($this->year, 'West');
    }

    private function submitCreate(string $p1, string $p2): void
    {
        $this->createUser($p1);
        $this->createUser($p2);
        $this->loginViaForm($p1);
        $this->client->request('POST', '/brackets/create', [
            '_token' => $this->csrfToken(),
            'name' => "$p1 bracket",
            'year' => 2020, // Must be ignored.
            'opponent_username' => $p2,
        ]);
    }

    private function bracketNamed(string $name): ?Bracket
    {
        return $this->em->getRepository(Bracket::class)->findOneBy(['name' => $name]);
    }

    public function testFormHasNoYearField(): void
    {
        $this->openTournament();
        $this->createUser('cg_form_user');
        $this->loginViaForm('cg_form_user');

        $this->client->request('GET', '/brackets/create');
        $this->assertSelectorNotExists('input[name="year"]');
        $this->assertSelectorTextContains('body', (string) $this->year);
    }

    public function testCreateUsesTheActiveYearAndLoadsTeams(): void
    {
        $this->openTournament();
        $this->submitCreate('cg_ok_p1', 'cg_ok_p2');

        $bracket = $this->bracketNamed('cg_ok_p1 bracket');
        $this->assertNotNull($bracket);
        $this->assertSame($this->year, $bracket->getYear());
        $this->assertResponseRedirects('/brackets/' . $bracket->getId());
    }

    public function testCreateIsRefusedBeforeTeamsAreAnnounced(): void
    {
        static::getContainer()->get(TournamentCalendar::class)->setEastOpponent($this->year, 'West');
        $this->submitCreate('cg_noteams_p1', 'cg_noteams_p2');

        $this->assertNull($this->bracketNamed('cg_noteams_p1 bracket'));
        $this->assertSelectorTextContains('body', 'open after Selection Sunday');
    }

    public function testCreateIsRefusedUntilThePairingIsSet(): void
    {
        FakeEspn::firstRound($this->year);
        $this->submitCreate('cg_nopair_p1', 'cg_nopair_p2');

        $this->assertNull($this->bracketNamed('cg_nopair_p1 bracket'));
        $this->assertSelectorTextContains('body', 'open after Selection Sunday');
    }

    public function testCreateIsRefusedWhenEspnFails(): void
    {
        $this->openTournament();
        FakeEspn::$failAll = true;
        $this->submitCreate('cg_down_p1', 'cg_down_p2');

        $this->assertNull($this->bracketNamed('cg_down_p1 bracket'));
        $this->assertSelectorTextContains('body', 'open after Selection Sunday');
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/BracketCreateGateTest.php`
Expected: FAIL. The year input exists, the bracket is created with year 2020, and the refused cases create empty brackets.

- [ ] **Step 3: Implement**

`BracketController::create()`: add a `TournamentCalendar $calendar` argument (import `App\Service\TournamentCalendar`). At the top, after `$opponents = …`:

```php
        $year = $calendar->activeYear();
        $finalFourPairs = $calendar->finalFourPairs($year);
        $open = $finalFourPairs !== null && $espnApiService->tournamentFieldIsSet($year);
        $view = ['opponents' => $opponents, 'currentUser' => $user, 'year' => $year, 'open' => $open];
```

In the POST branch, delete `$year = (int) $request->request->get('year', date('Y'));`. Change the validation chain to start with the gate:

```php
            $error = null;
            if (!$open) {
                $error = "Brackets for $year open after Selection Sunday.";
            } elseif ($name === '') {
```

Replace both `return $this->render('bracket/create.html.twig', [ … ]);` calls with `return $this->render('bracket/create.html.twig', $view);`, and change `$bracketBuilder->buildBracket($bracket);` to `$bracketBuilder->buildBracket($bracket, $finalFourPairs);`.

`templates/bracket/create.html.twig`: change the heading to `Create {{ year }} Bracket`. Delete the whole "Tournament Year" `<div>`. Change the name placeholder to `e.g. Adam vs Aaron {{ year }}`. Wrap the `<form>…</form>` in:

```twig
{% if open %}
    … existing form …
{% else %}
    <p class="bg-white rounded-lg shadow px-4 py-6 text-center text-gray-600">
        Brackets for {{ year }} open after Selection Sunday, once the field is announced.
    </p>
{% endif %}
```

- [ ] **Step 4: Run the gate tests, then the suite**

Run: `ddev exec php bin/phpunit tests/Functional/BracketCreateGateTest.php && ddev composer test`
Expected: PASS. Also update `BracketControllerTest::testCreateBracketPage` if it now fails: call `FakeEspn::reset()` in it (the closed page still returns 200, so it should pass unchanged).

- [ ] **Step 5: Rebuild CSS and commit**

```bash
ddev exec php bin/console tailwind:build
git add src/Controller/BracketController.php templates/bracket/create.html.twig var/tailwind/app.built.css tests/Functional/BracketCreateGateTest.php
git commit -m "Choose the tournament year server-side; refuse brackets until the field and pairing are set"
```

---

### Task 5: Refill empty first-round slots (`pull-teams`)

**Files:**
- Modify: `src/Service/EspnApiService.php` (`fetchTournamentTeams()`), `src/Repository/GameRepository.php`, `src/Controller/BracketController.php`, `templates/bracket/show.html.twig`, `assets/app.js`
- Delete: `templates/bracket/teams.html.twig`
- Create: `tests/Functional/PullTeamsTest.php`

**Interfaces:**
- Consumes: `FakeEspn::firstRound(int, array)`, `BracketBuilderService::buildBracket()`, `EspnApiService::populateBracketTeams()`.
- Produces:
  - `GameRepository::countMissingFirstRoundTeams(Bracket $bracket): int`: empty `team1`/`team2` slots across round 1
  - Route `POST /api/brackets/{id}/pull-teams` named `api_bracket_pull_teams`, returning JSON `{"result": {"success": bool, "count": int, "error"?: string}, "missing": int}`
  - `show.html.twig` receives `missingTeams` (int)

- [ ] **Step 1: Write the failing tests**

`tests/Functional/PullTeamsTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Entity\Pick;
use App\Entity\Team;
use App\Repository\GameRepository;
use App\Service\BracketBuilderService;
use App\Service\EspnApiService;
use App\Tests\Support\FakeEspn;

class PullTeamsTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    /** A bracket built while East's 16 seed was still undecided. */
    private function bracketWithOpenSlot(string $prefix): Bracket
    {
        FakeEspn::firstRound(2027, ['East_16']);
        $bracket = $this->createBracket($this->createUser("{$prefix}_p1"), $this->createUser("{$prefix}_p2"));
        $bracket->setYear(2027);
        static::getContainer()->get(BracketBuilderService::class)->buildBracket($bracket);
        static::getContainer()->get(EspnApiService::class)->populateBracketTeams($bracket);
        return $bracket;
    }

    private function eastOneGame(Bracket $bracket): Game
    {
        return $this->em->getRepository(Game::class)->findOneBy(
            ['bracket' => $bracket, 'roundNumber' => 1, 'region' => 'East', 'bracketPosition' => 1],
        );
    }

    public function testPlaceholderCompetitorsAreNotSavedAsTeams(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_tbd');

        $this->assertNull($this->eastOneGame($bracket)->getTeam2());
        $this->assertNull($this->em->getRepository(Team::class)->findOneBy(['name' => 'TBD']));
        $this->assertSame(1, static::getContainer()->get(GameRepository::class)->countMissingFirstRoundTeams($bracket));
    }

    public function testPullTeamsFillsTheSlotOnceDecided(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_fill');
        $bracketId = $bracket->getId();
        $this->loginViaForm('pt_fill_p1');
        $this->client->request('GET', "/brackets/$bracketId");
        $this->assertSelectorTextContains('#btn-pull-teams', 'Load missing teams (1)');

        FakeEspn::reset();
        FakeEspn::firstRound(2027);
        $this->client->request('POST', "/api/brackets/$bracketId/pull-teams", ['_token' => $this->csrfToken()]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['missing']);

        $game = $this->em->getRepository(Game::class)->findOneBy(
            ['bracket' => $bracketId, 'roundNumber' => 1, 'region' => 'East', 'bracketPosition' => 1],
        );
        $this->assertSame('East 16', $game->getTeam2()->getName());
    }

    public function testRefreshKeepsExistingPicks(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_keep');
        $game = $this->em->getRepository(Game::class)->findOneBy(
            ['bracket' => $bracket, 'roundNumber' => 1, 'region' => 'East', 'bracketPosition' => 2],
        );
        $pickedTeamId = $game->getTeam1()->getId();
        $pick = (new Pick())->setPlayer(1)->setTeam($game->getTeam1());
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();

        FakeEspn::reset();
        FakeEspn::firstRound(2027);
        $espn = static::getContainer()->get(EspnApiService::class);
        $espn->reset(); // Same container as bracketWithOpenSlot(); drop its memoised events.
        $espn->populateBracketTeams($bracket);

        $this->assertSame($pickedTeamId, $game->getTeam1()->getId());
        $this->assertSame($pickedTeamId, $game->getPickForPlayer(1)->getTeam()->getId());
    }

    public function testOutsiderCannotPullTeams(): void
    {
        $bracket = $this->bracketWithOpenSlot('pt_out');
        $this->createUser('pt_out_stranger');
        $this->loginViaForm('pt_out_stranger');
        $token = $this->csrfToken();

        $this->client->catchExceptions(false);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->client->request('POST', "/api/brackets/{$bracket->getId()}/pull-teams", ['_token' => $token]);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/PullTeamsTest.php`
Expected: FAIL. A "TBD" team is saved, `countMissingFirstRoundTeams` is undefined, and the route returns 404.

- [ ] **Step 3: Skip placeholders when reading teams**

In `EspnApiService::fetchTournamentTeams()`, in the competitor loop after `$teamName` is computed, replace `if (!$seed || !$teamName) { continue; }` with:

```php
                // Before the First Four is played ESPN may list a placeholder
                // ("TBD", or "Team A/Team B"). Leave the slot empty; a later
                // pull fills it.
                if (!$seed || !$teamName || stripos($teamName, 'TBD') !== false || str_contains($teamName, '/')) {
                    continue;
                }
```

- [ ] **Step 4: Add the repository count**

`GameRepository`:

```php
    public function countMissingFirstRoundTeams(Bracket $bracket): int
    {
        $missing = 0;
        foreach ($this->findByBracketAndRound($bracket, 1) as $game) {
            $missing += ($game->getTeam1() === null ? 1 : 0) + ($game->getTeam2() === null ? 1 : 0);
        }
        return $missing;
    }
```

- [ ] **Step 5: Add the route and pass `missingTeams` to the page**

`BracketController`, after `updateScores()`:

```php
    #[Route('/api/brackets/{id}/pull-teams', name: 'api_bracket_pull_teams', methods: ['POST'])]
    public function pullTeams(
        Request $request,
        Bracket $bracket,
        EspnApiService $espnApiService,
        GameRepository $gameRepository,
        SessionAuthenticator $auth,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $auth->requireBracketAccess($bracket);

        $result = $espnApiService->populateBracketTeams($bracket);

        return $this->json([
            'result' => $result,
            'missing' => $gameRepository->countMissingFirstRoundTeams($bracket),
        ]);
    }
```

In `show()`, add `'missingTeams' => $gameRepository->countMissingFirstRoundTeams($bracket),` to the render array.

- [ ] **Step 6: Button and JS**

`templates/bracket/show.html.twig`: directly above `{# Action buttons #}`:

```twig
{% if missingTeams > 0 %}
<button type="button" id="btn-pull-teams" onclick="pullTeams({{ bracket.id }})"
        class="w-full mb-4 bg-amber-50 border border-amber-300 text-amber-800 px-3 py-2 rounded text-sm font-medium hover:bg-amber-100 cursor-pointer">
    Load missing teams ({{ missingTeams }})
</button>
{% endif %}
```

`assets/app.js`: replace the whole `function pullTeams(bracketId) { … }` (and its `// --- Pull Teams from API (ESPN) ---` comment) with:

```js
// --- Load missing first-round teams (e.g. after the First Four) ---

function pullTeams(bracketId) {
    var btn = document.getElementById('btn-pull-teams');
    btn.classList.add('loading');
    btn.textContent = 'Loading...';

    postForm('/api/brackets/' + bracketId + '/pull-teams', {})
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.result && data.result.success === false) {
            alert(data.result.error || 'Teams could not be loaded.');
        }
        window.location.reload();
    })
    .catch(function(err) {
        alert('Error: ' + err.message);
        btn.classList.remove('loading');
        btn.textContent = 'Load missing teams';
    });
}
```

`window.pullTeams = pullTeams;` already exists; keep it. Delete `templates/bracket/teams.html.twig`.

- [ ] **Step 7: Run tests, build, commit**

Run: `ddev exec php bin/phpunit tests/Functional/PullTeamsTest.php && ddev exec php bin/console tailwind:build && ddev composer test`
Expected: PASS.

```bash
git add -A src/Service/EspnApiService.php src/Repository/GameRepository.php src/Controller/BracketController.php templates/bracket/show.html.twig templates/bracket/teams.html.twig assets/app.js var/tailwind/app.built.css tests/Functional/PullTeamsTest.php
git commit -m "Restore pull-teams to fill empty first-round slots; ignore play-in placeholders"
```

---

### Task 6: Spreads refresh until the round's first pick, then lock

**Files:**
- Modify: `src/Repository/GameRepository.php`, `src/Service/EspnApiService.php` (`pullSpreads()`), `src/Controller/BracketController.php` (`show()`), `templates/bracket/show.html.twig`, `assets/app.js` (`pullSpreads()`)
- Create: `tests/Functional/SpreadLockTest.php`

**Interfaces:**
- Consumes: `FakeEspn::setSummary()`.
- Produces:
  - `GameRepository::roundHasPicks(Bracket $bracket, int $roundNumber): bool`
  - `EspnApiService::pullSpreads(Bracket, int): array{matched: int, total: int, unmatched: list<int>, locked: bool}`. When `locked` is true nothing is changed and no requests are made.

- [ ] **Step 1: Write the failing tests**

`tests/Functional/SpreadLockTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Entity\Game;
use App\Entity\Pick;
use App\Service\EspnApiService;
use App\Tests\Support\FakeEspn;

class SpreadLockTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    /** One first-round game with an ESPN id and a 3.5 spread already stored. */
    private function gameWithSpread(string $prefix): Game
    {
        $bracket = $this->createBracket($this->createUser("{$prefix}_p1"), $this->createUser("{$prefix}_p2"));
        $home = $this->createTeam("{$prefix} Home", 1);
        $away = $this->createTeam("{$prefix} Away", 16);
        $game = $this->createGame($bracket, $home, $away);
        $game->setExternalGameId("{$prefix}-evt")->setSpread(3.5)->setSpreadTeam($home);
        $this->em->flush();
        return $game;
    }

    private function pull(Game $game): array
    {
        return static::getContainer()->get(EspnApiService::class)->pullSpreads($game->getBracket(), 1);
    }

    public function testSpreadRefreshesWhileNoPicks(): void
    {
        $game = $this->gameWithSpread('sl_open');
        FakeEspn::setSummary('sl_open-evt', 'sl_open Home', 'sl_open Away', 5.5);

        $result = $this->pull($game);

        $this->assertFalse($result['locked']);
        $this->assertSame(5.5, $game->getSpread());
    }

    public function testSpreadIsLockedOnceARoundHasAPick(): void
    {
        $game = $this->gameWithSpread('sl_lock');
        $pick = (new Pick())->setPlayer(1)->setTeam($game->getTeam1());
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();
        FakeEspn::setSummary('sl_lock-evt', 'sl_lock Home', 'sl_lock Away', 9.0);

        $result = $this->pull($game);

        $this->assertTrue($result['locked']);
        $this->assertSame(3.5, $game->getSpread());
        $this->assertSame([], FakeEspn::$requests, 'A locked round makes no ESPN calls');
    }

    public function testMissingLineKeepsExistingSpread(): void
    {
        $game = $this->gameWithSpread('sl_gone');
        FakeEspn::setSummary('sl_gone-evt', 'sl_gone Home', 'sl_gone Away', null);

        $result = $this->pull($game);

        $this->assertSame(3.5, $game->getSpread());
        $this->assertSame([], $result['unmatched'], 'Existing spread: no "set manually" warning');
    }

    public function testButtonShowsLockedState(): void
    {
        $game = $this->gameWithSpread('sl_btn');
        $pick = (new Pick())->setPlayer(1)->setTeam($game->getTeam1());
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();

        $this->loginViaForm('sl_btn_p1');
        $this->client->request('GET', '/brackets/' . $game->getBracket()->getId());
        $this->assertSelectorTextContains('#btn-spreads', 'Spreads locked');
        $this->assertSelectorExists('#btn-spreads[disabled]');
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/SpreadLockTest.php`
Expected: FAIL. The `locked` key is missing, games that already have a spread are skipped (so it stays 3.5 where 5.5 is expected), and the button text is "Pull Spreads".

- [ ] **Step 3: Repository method**

`GameRepository`:

```php
    public function roundHasPicks(Bracket $bracket, int $roundNumber): bool
    {
        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(p.id)')
            ->join('g.picks', 'p')
            ->where('g.bracket = :bracket')
            ->andWhere('g.roundNumber = :round')
            ->setParameter('bracket', $bracket)
            ->setParameter('round', $roundNumber)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
```

- [ ] **Step 4: Lock-aware refresh in `EspnApiService::pullSpreads()`**

Replace the method's docblock and body up to the `foreach`:

```php
    /**
     * Refresh spreads for a round from ESPN. Lines keep updating until the
     * round's first pick; after that the round is locked so every pick is
     * judged against the line it was made on.
     * @return array{matched: int, total: int, unmatched: array, locked: bool}
     */
    public function pullSpreads(Bracket $bracket, int $roundNumber): array
    {
        $games = $this->gameRepository->findByBracketAndRound($bracket, $roundNumber);

        if ($this->gameRepository->roundHasPicks($bracket, $roundNumber)) {
            return ['matched' => 0, 'total' => count($games), 'unmatched' => [], 'locked' => true];
        }

        $matched = 0;
        $unmatched = [];

        foreach ($games as $game) {
            if ($game->isComplete()) {
                continue;
            }
            if (!$game->getTeam1() || !$game->getTeam2()) {
                continue;
            }
```

That is: remove `if ($game->getSpread() !== null) { continue; }` and add the `isComplete()` skip. Then make "no line available" keep an existing spread silently. Change both `$unmatched[] = $game->getId();` lines after `fetchEventSummary` and `applySpread` to:

```php
                if ($game->getSpread() === null) {
                    $unmatched[] = $game->getId();
                }
```

Keep the `!$eventId` branch as it is. Change the final return to add `'locked' => false`.

- [ ] **Step 5: Locked button**

`BracketController::show()`: `roundHasPicks` is already computed as `$hasPicks`, so nothing changes there.

`templates/bracket/show.html.twig`: replace the Pull Spreads `<button …>Pull Spreads</button>` with:

```twig
    {% if roundHasPicks %}
    <button id="btn-spreads" disabled title="Picks have been made in this round, so spreads no longer change."
            class="flex-1 bg-gray-100 border border-gray-200 text-gray-400 px-3 py-2 rounded text-sm font-medium cursor-not-allowed">
        Spreads locked
    </button>
    {% else %}
    <button onclick="pullSpreads({{ bracket.id }}, {{ currentRound }})"
            class="flex-1 bg-white border border-gray-300 text-gray-700 px-3 py-2 rounded text-sm font-medium hover:bg-gray-50 cursor-pointer"
            id="btn-spreads">
        Pull Spreads
    </button>
    {% endif %}
```

`assets/app.js` `pullSpreads()`: delete the `if (btn.dataset.hasSpreads === '1' && …) { … }` confirm block. In the success branch, before `updateGameCards(data.cards);`:

```js
            if (data.result && data.result.locked) {
                alert('Picks have been made in this round, so spreads are locked.');
            }
```

- [ ] **Step 6: Run tests, build, commit**

Run: `ddev exec php bin/phpunit tests/Functional/SpreadLockTest.php && ddev exec php bin/console tailwind:build && ddev composer test`
Expected: PASS.

```bash
git add src/Repository/GameRepository.php src/Service/EspnApiService.php templates/bracket/show.html.twig assets/app.js var/tailwind/app.built.css tests/Functional/SpreadLockTest.php
git commit -m "Refresh spreads until a round's first pick, then lock them"
```

---

### Task 7: `app:tournament:sync` and hourly cron

**Files:**
- Modify: `src/Service/ScoringService.php`, `src/Controller/BracketController.php` (`updateScores()`), `src/Service/EspnApiService.php` (`updateScores()` early exit; memoised summaries)
- Create: `src/Command/TournamentSyncCommand.php`, `tests/Functional/TournamentSyncCommandTest.php`

**Interfaces:**
- Consumes: `TournamentCalendar::activeYear()`, `GameRepository::countMissingFirstRoundTeams()`, `EspnApiService::populateBracketTeams()`, `EspnApiService::pullSpreads()`, `EspnApiService::updateScores()`, `FakeEspn`.
- Produces:
  - `ScoringService::settleRound(Bracket $bracket, int $roundNumber): void`: evaluate picks and advance winners for every completed game in the round
  - Command `app:tournament:sync` with option `--year=<int>` (defaults to `activeYear()`)

- [ ] **Step 1: Write the failing tests**

`tests/Functional/TournamentSyncCommandTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Entity\Game;
use App\Entity\Pick;
use App\Tests\Support\FakeEspn;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class TournamentSyncCommandTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeEspn::reset();
    }

    private function run(int $year): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:tournament:sync'));
        $tester->execute(['--year' => (string) $year]);
        return $tester;
    }

    /** A bracket for $year with one first-round game ESPN reports as final (70-60). */
    private function finishedGame(string $prefix, int $year): Game
    {
        $bracket = $this->createBracket($this->createUser("{$prefix}_p1"), $this->createUser("{$prefix}_p2"));
        $bracket->setYear($year);
        $home = $this->createTeam("{$prefix} Home", 1);
        $away = $this->createTeam("{$prefix} Away", 16);
        $next = $this->createGame($bracket, $home, $away, 2, 1);
        $next->setTeam1(null)->setTeam2(null);
        $game = $this->createGame($bracket, $home, $away, 1, 1);
        $game->setExternalGameId("{$prefix}-evt")->setNextGame($next)->setSpread(5.0)->setSpreadTeam($home);
        $pick = (new Pick())->setPlayer(1)->setTeam($home);
        $game->addPick($pick);
        $this->em->persist($pick);
        $this->em->flush();

        FakeEspn::addEvent($year, 3, FakeEspn::event(
            "{$prefix}-evt", "NCAA Men's Basketball Championship - East Region - 1st Round",
            ['name' => "{$prefix} Home", 'seed' => 1, 'score' => 70],
            ['name' => "{$prefix} Away", 'seed' => 16, 'score' => 60],
            true,
        ));
        return $game;
    }

    public function testSyncScoresEvaluatesAndAdvances(): void
    {
        $game = $this->finishedGame('ts_ok', 2027);

        $tester = $this->run(2027);

        $tester->assertCommandIsSuccessful();
        $this->em->refresh($game);
        $this->assertTrue($game->isComplete());
        $this->assertTrue($game->getPickForPlayer(1)->isWinner(), 'Covered 5 by winning by 10');
        $this->assertSame('ts_ok Home', $game->getNextGame()->getTeam1()->getName());
    }

    public function testSyncIsIdempotentAndIgnoresOtherYears(): void
    {
        $current = $this->finishedGame('ts_idem', 2027);
        $old = $this->finishedGame('ts_old', 2026);

        $this->run(2027);
        $second = $this->run(2027);

        $this->assertStringContainsString('0 game(s) finalised', $second->getDisplay());
        $this->em->refresh($current);
        $this->em->refresh($old);
        $winners = array_filter($current->getPicks()->toArray(), fn (Pick $p) => $p->isWinner() === true);
        $this->assertCount(1, $winners);
        $this->assertFalse($old->isComplete(), 'Other years are not touched');
    }

    public function testSyncWithNoBracketsDoesNothing(): void
    {
        $tester = $this->run(2031);
        $tester->assertCommandIsSuccessful();
        $this->assertSame([], FakeEspn::$requests);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/TournamentSyncCommandTest.php`
Expected: FAIL with `Command "app:tournament:sync" is not defined`.

- [ ] **Step 3: `ScoringService::settleRound()` and use it in the controller**

`ScoringService` (add `use App\Entity\Bracket;` if missing):

```php
    /**
     * Evaluate picks and advance winners for every completed game in a round.
     * Safe to repeat: evaluatePicks() skips picks already judged.
     */
    public function settleRound(Bracket $bracket, int $roundNumber): void
    {
        foreach ($this->gameRepository->findByBracketAndRound($bracket, $roundNumber) as $game) {
            if ($game->isComplete()) {
                $this->evaluatePicks($game);
                $this->advanceWinner($game);
            }
        }
    }
```

`BracketController::updateScores()`: replace the `// Evaluate picks and advance winners …` loop with

```php
        $scoringService->settleRound($bracket, $round);
        $games = $gameRepository->findByBracketAndRound($bracket, $round);
```

- [ ] **Step 4: Avoid needless ESPN calls**

`EspnApiService::updateScores()`: right after `$games = …`, add

```php
        $pending = array_filter($games, fn (Game $g) => !$g->isComplete() && $g->getTeam1() && $g->getTeam2());
        if ($pending === []) {
            return ['updated' => 0, 'unmatched' => []];
        }
```

`EspnApiService::fetchEventSummary()`: memoise successful responses. Add `private array $summaries = [];` and clear it in `reset()` (`$this->summaries = [];`). At the top of the method `if (isset($this->summaries[$eventId])) { return $this->summaries[$eventId]; }`. On success, `return $this->summaries[$eventId] = $response->toArray();`.

- [ ] **Step 5: The command**

`src/Command/TournamentSyncCommand.php`:

```php
<?php

namespace App\Command;

use App\Repository\BracketRepository;
use App\Repository\GameRepository;
use App\Service\EspnApiService;
use App\Service\ScoringService;
use App\Service\TournamentCalendar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:tournament:sync',
    description: 'Pull missing teams, open-round spreads and final scores from ESPN for every bracket of the current tournament',
)]
class TournamentSyncCommand extends Command
{
    public function __construct(
        private TournamentCalendar $calendar,
        private BracketRepository $bracketRepository,
        private GameRepository $gameRepository,
        private EspnApiService $espn,
        private ScoringService $scoring,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('year', null, InputOption::VALUE_REQUIRED, 'Tournament year (default: the current one)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $year = (int) ($input->getOption('year') ?? $this->calendar->activeYear());
        $brackets = $this->bracketRepository->findBy(['year' => $year]);

        if ($brackets === []) {
            $output->writeln("No $year brackets.", OutputInterface::VERBOSITY_VERBOSE);
            return Command::SUCCESS;
        }

        foreach ($brackets as $bracket) {
            if ($this->gameRepository->countMissingFirstRoundTeams($bracket) > 0) {
                $this->espn->populateBracketTeams($bracket);
            }

            $updated = 0;
            for ($round = 1; $round <= 6; $round++) {
                $this->espn->pullSpreads($bracket, $round);
                $updated += $this->espn->updateScores($bracket, $round)['updated'];
                $this->scoring->settleRound($bracket, $round);
            }

            $output->writeln(sprintf('%s (#%d): %d game(s) finalised', $bracket->getName(), $bracket->getId(), $updated));
        }

        return Command::SUCCESS;
    }
}
```

Rounds are handled in order, so a winner advanced in round N has a spread and score pulled for round N+1 in the same run.

- [ ] **Step 6: Run tests**

Run: `ddev exec rm -rf var/cache/test && ddev exec php bin/phpunit tests/Functional/TournamentSyncCommandTest.php && ddev composer test`
Expected: PASS.

- [ ] **Step 7: Document the cron job**

`README.md`, under `## Deployment`, add:

```markdown
### Scheduled sync (March–April)

`app:tournament:sync` pulls missing teams, spreads for rounds with no picks yet, and final scores for every bracket of the current tournament. Add it in the Dreamhost panel (Advanced → Cron Jobs) as the site's user:

    0 * * 3,4 *   cd <app path> && php bin/console app:tournament:sync >> var/log/sync.log 2>&1

Cron may use a different PHP than your shell; if the log shows a version error, use the full path shown by `which php` in an SSH session. Outside March and April the job doesn't run; the buttons on each bracket page still work at any time.
```

- [ ] **Step 8: Commit**

```bash
git add src/Command/TournamentSyncCommand.php src/Service/ScoringService.php src/Service/EspnApiService.php src/Controller/BracketController.php tests/Functional/TournamentSyncCommandTest.php README.md
git commit -m "Add app:tournament:sync for scheduled team, spread and score updates"
```

---

### Task 8: Remove dead code; update docs

**Files:**
- Delete: `src/Service/OddsApiService.php`, `src/Entity/Round.php`, `src/Repository/RoundRepository.php`
- Create: `migrations/Version20261001000000.php`
- Modify: `config/services.yaml`, `.env.example`, `src/Entity/Game.php`, `src/Controller/BracketController.php`, `src/Service/BracketBuilderService.php`, `CLAUDE.md`, `README.md`

**Interfaces:**
- Produces: `Game::nameForRound(int $roundNumber): string` (static; replaces `Round::getRoundName()`).

- [ ] **Step 1: Write the failing test** (append to `BracketBuilderServiceTest`)

```php
    public function testBuildingDoesNotWriteRoundRows(): void
    {
        $conn = $this->em->getConnection();
        $bracket = $this->createBracket($this->createUser('bb_norounds_p1'), $this->createUser('bb_norounds_p2'));
        static::getContainer()->get(BracketBuilderService::class)->buildBracket($bracket);

        $this->assertFalse(
            $conn->createSchemaManager()->tablesExist(['round']),
            'The round table is dropped',
        );
        $this->assertSame('Sweet 16', Game::nameForRound(3));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `ddev exec php bin/phpunit --filter testBuildingDoesNotWriteRoundRows`
Expected: FAIL (`round` table exists; `nameForRound` undefined).

- [ ] **Step 3: Move round names to `Game`, drop `Round`**

`src/Entity/Game.php`: add

```php
    public static function nameForRound(int $roundNumber): string
    {
        return match ($roundNumber) {
            1 => 'Round of 64',
            2 => 'Round of 32',
            3 => 'Sweet 16',
            4 => 'Elite 8',
            5 => 'Final Four',
            6 => 'Championship',
            default => 'Round ' . $roundNumber,
        };
    }
```

and change `getRoundName()` to `return self::nameForRound($this->roundNumber);`. Remove any `use App\Entity\Round;` (same namespace, so possibly none).

`BracketController::show()`: `\App\Entity\Round::getRoundName($r)` → `\App\Entity\Game::nameForRound($r)` (`Game` is already imported via the `GameRepository` use? If not, add `use App\Entity\Game;` and write `Game::nameForRound($r)`).

`BracketBuilderService`: delete the `// Create Round entities` loop and `use App\Entity\Round;`.

Delete `src/Entity/Round.php` and `src/Repository/RoundRepository.php`.

`migrations/Version20261001000000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the round table: rows were written per bracket and never read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE `round`');
    }

    public function down(Schema $schema): void
    {
        // Same DDL as Version20260319213941.
        $this->addSql('CREATE TABLE `round` (id INT AUTO_INCREMENT NOT NULL, year INT NOT NULL, round_number INT NOT NULL, name VARCHAR(50) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }
}
```

- [ ] **Step 4: Remove the Odds API**

Delete `src/Service/OddsApiService.php`. In `config/services.yaml` delete the `App\Service\OddsApiService:` block (3 lines). In `.env.example` delete the `ODDS_API_KEY=…` line and any comment directly above it that refers only to it.

- [ ] **Step 5: Migrate, run everything**

Run: `ddev exec php bin/console doctrine:migrations:migrate --no-interaction && ddev exec php bin/console doctrine:schema:validate && ddev exec rm -rf var/cache/test && ddev composer test`
Expected: migration applies; schema validate reports mapping and database in sync; all tests PASS.

- [ ] **Step 6: Update `CLAUDE.md`**

- **Core Domain Model:** delete the "**Round** stores per-year round metadata…" sentence. Change "Region pairs East/West and South/Midwest merge in the Final Four." to "Which regions meet in the Final Four is set per year by an admin (`TournamentCalendar`); brackets can't be created until it is."
- **Key Services:** delete the `OddsApiService` bullet. Change the ESPN bullet to: "**EspnApiService** — Pulls teams, spreads and scores from ESPN. Queries whole months (`dates=YYYYMM`); ESPN rejects date ranges. Matches games via event IDs stored on Game entities." Add: "**TournamentCalendar** — The current tournament year (the year of the next March; users never choose it) and the per-year Final Four pairing."
- **API Routes:** delete the `/api/games/{id}/score` line (no such route). Keep `pull-teams` and describe it as "Fill empty first-round slots from ESPN (e.g. after the First Four)". Change the pull-spreads line to "Refresh spreads for a round; locked once the round has a pick".
- Add a section:

```markdown
### Tournament data

`app:tournament:sync` (cron, hourly in March–April) fills missing teams, refreshes spreads for rounds without picks, and pulls final scores for every bracket of the current year. Tests never call ESPN: `tests/Support/FakeEspn.php` is wired as the HTTP client's mock response factory in the test environment.
```

- [ ] **Step 7: Add the yearly checklist to `README.md`**

```markdown
## Each tournament

1. **Selection Sunday:** in `/admin`, set which region plays East in the Final Four. Brackets open once this is set and ESPN lists the field.
2. **After the First Four (Tue/Wed):** the sync job fills the four play-in slots; the "Load missing teams" button on a bracket does the same on demand.
3. **Before each round's first pick:** spreads keep refreshing (sync or "Pull Spreads"). The first pick in a round locks that round's spreads.
```

- [ ] **Step 8: Commit**

```bash
git add -A src/Service/OddsApiService.php src/Entity/Round.php src/Repository/RoundRepository.php src/Entity/Game.php src/Controller/BracketController.php src/Service/BracketBuilderService.php migrations/Version20261001000000.php config/services.yaml .env.example CLAUDE.md README.md tests/Functional/BracketBuilderServiceTest.php
git commit -m "Remove unused Odds API service and Round table; document tournament operations"
```

---

## After the plan: operational steps (not code)

- **Deploy** as usual (merge to `main`; the workflow migrates and clears the cache).
- **Add the cron job** in the Dreamhost panel exactly as in the README.
- **Remove `ODDS_API_KEY`** from the server's `.env.local` if present.
- **Selection Sunday, March 14, 2027:** set the Final Four pairing in `/admin`, then create a test bracket to confirm teams load.
