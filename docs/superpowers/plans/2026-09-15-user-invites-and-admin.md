# Invite-Based Registration and Admin Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace CLI-only account creation with admin-issued email invitations, add an admin dashboard, let users pick their opponent by autocomplete, and close the bracket authorization hole.

**Architecture:** Authentication stays custom and session-based — Symfony's security component is not adopted. A new `SessionAuthenticator` service replaces the `requireUser()` method currently duplicated privately across two controllers, and grows `requireAdmin()` and `requireBracketAccess()`. Invites and password resets are separate entities storing only a SHA-256 hash of their token, with the raw token existing solely in the email.

**Tech Stack:** Symfony 7.2, PHP 8.4, Doctrine ORM 3, MySQL 8, Twig, Tailwind (standalone CLI via `symfonycasts/tailwind-bundle`), vanilla JS over AssetMapper, PHPUnit 13 with `dama/doctrine-test-bundle`.

**Spec:** `docs/superpowers/specs/2026-09-15-user-invites-and-admin-design.md`

## Global Constraints

- **The repository `adamzimmermann/spread-dread` is PUBLIC.** No real email address, credential, or other personal datum may appear in any tracked file — migrations, fixtures, tests, specs, commit messages included. All test data uses `@example.com`.
- Authentication is custom. Never use `$this->getUser()`, `IsGranted`, or the security firewall. `config/packages/security.yaml` stays vestigial.
- PHPUnit fails on deprecations, notices, and warnings (`phpunit.dist.xml`). Code must be clean under `failOnDeprecation="true"`.
- Tests share the dev database; `dama/doctrine-test-bundle` rolls back each test. Test fixtures must use unique usernames per test to avoid unique-constraint collisions within a transaction.
- Run all console commands inside DDEV: `ddev exec php bin/console <command>`.
- Run `ddev exec php bin/console tailwind:build` after any template change that adds Tailwind classes, and commit `var/tailwind/app.built.css`.
- Email addresses are stored and compared lowercased, always.
- Every state-changing route is POST and carries a CSRF token. No GET link mutates state.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `src/Entity/UserStatus.php` | Backed enum: active / disabled |
| `src/Entity/Invite.php` | Invite record + token hash |
| `src/Entity/InviteStatus.php` | Backed enum: sent / accepted / revoked |
| `src/Entity/PasswordResetToken.php` | Reset token record |
| `src/Repository/InviteRepository.php` | Invite lookups by token hash |
| `src/Repository/PasswordResetTokenRepository.php` | Reset token lookups |
| `src/Security/SessionAuthenticator.php` | Session auth + authorization guards |
| `src/Service/InviteService.php` | Create / resend / revoke / accept invites |
| `src/Service/PasswordResetService.php` | Request / validate / consume resets |
| `src/Controller/InviteController.php` | Public invite acceptance |
| `src/Controller/PasswordResetController.php` | Public reset flow |
| `src/Controller/AdminController.php` | Dashboard + admin actions |
| `templates/invite/accept.html.twig` | Set username + password |
| `templates/invite/invalid.html.twig` | Generic token-failure page |
| `templates/password_reset/request.html.twig` | Forgot-password form |
| `templates/password_reset/reset.html.twig` | New-password form |
| `templates/admin/dashboard.html.twig` | Users + invites tables |
| `templates/email/invite.html.twig` + `.txt.twig` | Invite email |
| `templates/email/password_reset.html.twig` + `.txt.twig` | Reset email |

**Modified:**

| File | Change |
|---|---|
| `src/Entity/User.php` | email, isAdmin, status, createdAt, lastLoginAt, invitedBy |
| `src/Entity/Bracket.php` | `hasPlayer()` |
| `src/Repository/UserRepository.php` | `findByEmail()`, `findActiveOpponents()` |
| `src/Controller/SecurityController.php` | status check, session migrate, lastLoginAt, CSRF |
| `src/Controller/BracketController.php` | drop private `requireUser()`, use guards, opponent resolution |
| `src/Controller/GameController.php` | drop private `requireUser()`, use guards |
| `src/Command/UserCommand.php` | `--email`, `--admin`, optional password |
| `templates/base.html.twig` | CSRF meta tag, Admin nav link |
| `templates/bracket/create.html.twig` | fixed player 1, opponent datalist |
| `templates/bracket/edit.html.twig` | opponent datalist |
| `templates/security/login.html.twig` | CSRF token, forgot-password link |
| `assets/app.js` | attach CSRF token to every fetch |
| `tests/Functional/WebTestCase.php` | `createUser()` sets email/status; invite + admin helpers |

---

## Task 1: Extend the User entity

**Files:**
- Create: `src/Entity/UserStatus.php`
- Modify: `src/Entity/User.php`, `src/Repository/UserRepository.php`, `tests/Functional/WebTestCase.php:24-32`
- Test: `tests/Entity/UserTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `UserStatus::Active` / `UserStatus::Disabled`; `User::setEmail(string): self` (lowercases), `User::getEmail(): string`, `User::isAdmin(): bool`, `User::setIsAdmin(bool): self`, `User::getStatus(): UserStatus`, `User::setStatus(UserStatus): self`, `User::isActive(): bool`, `User::getCreatedAt(): \DateTimeImmutable`, `User::getLastLoginAt(): ?\DateTimeImmutable`, `User::setLastLoginAt(?\DateTimeImmutable): self`, `User::getInvitedBy(): ?User`, `User::setInvitedBy(?User): self`; `UserRepository::findByEmail(string): ?User`, `UserRepository::findActiveOpponents(User $excluding): User[]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Entity/UserTest.php`:

```php
<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserStatus;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testEmailIsLowercasedOnSet(): void
    {
        $user = new User();
        $user->setEmail('Player.One@Example.COM');
        $this->assertSame('player.one@example.com', $user->getEmail());
    }

    public function testEmailIsTrimmed(): void
    {
        $user = new User();
        $user->setEmail('  player.one@example.com  ');
        $this->assertSame('player.one@example.com', $user->getEmail());
    }

    public function testNewUserDefaultsToActiveNonAdmin(): void
    {
        $user = new User();
        $this->assertSame(UserStatus::Active, $user->getStatus());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isAdmin());
    }

    public function testDisabledUserIsNotActive(): void
    {
        $user = new User();
        $user->setStatus(UserStatus::Disabled);
        $this->assertFalse($user->isActive());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $user = new User();
        $this->assertNotNull($user->getCreatedAt());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec php bin/phpunit tests/Entity/UserTest.php`
Expected: FAIL — `Error: Call to undefined method App\Entity\User::setEmail()`

- [ ] **Step 3: Create the status enum**

Create `src/Entity/UserStatus.php`:

```php
<?php

namespace App\Entity;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
        };
    }
}
```

- [ ] **Step 4: Add the fields to User**

In `src/Entity/User.php`, add after the existing `$password` property:

```php
    #[ORM\Column(length: 180, unique: true, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    private bool $isAdmin = false;

    #[ORM\Column(length: 16, enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::Active;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
```

Then the accessors:

```php
    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email === null ? null : strtolower(trim($email));
        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function setIsAdmin(bool $isAdmin): self
    {
        $this->isAdmin = $isAdmin;
        return $this;
    }

    public function getStatus(): UserStatus
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): self
    {
        $this->invitedBy = $invitedBy;
        return $this;
    }
```

**Note:** `email` is `nullable: true` at this stage on purpose — Task 12 flips it to `NOT NULL` after the production backfill. See the spec's Migration section.

- [ ] **Step 5: Run the entity test**

Run: `ddev exec php bin/phpunit tests/Entity/UserTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Add the repository methods**

In `src/Repository/UserRepository.php`:

```php
    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    /**
     * Active users other than the given one, for opponent selection.
     *
     * @return User[]
     */
    public function findActiveOpponents(User $excluding): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.status = :status')
            ->andWhere('u.id != :self')
            ->setParameter('status', UserStatus::Active->value)
            ->setParameter('self', $excluding->getId())
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
```

Add `use App\Entity\UserStatus;` to the imports.

- [ ] **Step 7: Update the test helper so every existing test still passes**

In `tests/Functional/WebTestCase.php`, replace `createUser()`:

```php
    protected function createUser(
        string $username = 'testuser',
        string $password = 'password',
        ?string $email = null,
        bool $isAdmin = false,
        UserStatus $status = UserStatus::Active,
    ): User {
        $user = new User();
        $user->setUsername($username);
        $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        $user->setEmail($email ?? $username . '@example.com');
        $user->setIsAdmin($isAdmin);
        $user->setStatus($status);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
```

Add `use App\Entity\UserStatus;` to the imports. Every existing test passes a unique username, so the derived `@example.com` addresses are unique too.

- [ ] **Step 8: Generate and run the migration**

Run:
```bash
ddev exec php bin/console doctrine:migrations:diff
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
```

Open the generated file in `migrations/` and confirm it contains **no** `UPDATE` statements with real email addresses — it should only `ALTER TABLE` to add columns. Delete any `DROP`/`CREATE` noise Doctrine emits for unrelated tables.

- [ ] **Step 9: Run the full suite**

Run: `ddev composer test`
Expected: PASS — all pre-existing tests still green.

- [ ] **Step 10: Commit**

```bash
git add src/Entity/User.php src/Entity/UserStatus.php src/Repository/UserRepository.php tests/ migrations/
git commit -m "Add email, status, admin flag and timestamps to User"
```

---

## Task 2: SessionAuthenticator and login hardening

**Files:**
- Create: `src/Security/SessionAuthenticator.php`
- Modify: `src/Controller/SecurityController.php:24-51`, `src/Controller/BracketController.php:219-227`, `src/Controller/GameController.php:219-227`
- Test: `tests/Functional/SecurityControllerTest.php`

**Interfaces:**
- Consumes: `User::isActive()`, `User::setLastLoginAt()` (Task 1).
- Produces: `SessionAuthenticator::getUser(): ?User`, `::requireUser(): User`, `::requireAdmin(): User`, `::login(User): void`, `::logout(): void`. All throw `Symfony\Component\Security\Core\Exception\AccessDeniedException` on failure.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Functional/SecurityControllerTest.php`:

```php
    public function testDisabledUserCannotLogIn(): void
    {
        $this->createUser('test_disabled_user', 'secret123', null, false, \App\Entity\UserStatus::Disabled);
        $this->client->request('POST', '/login', [
            'username' => 'test_disabled_user',
            'password' => 'secret123',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.bg-red-100');
    }

    public function testLoginRecordsLastLoginAt(): void
    {
        $user = $this->createUser('test_lastlogin_user', 'secret123');
        $this->assertNull($user->getLastLoginAt());

        $this->loginViaForm('test_lastlogin_user', 'secret123');

        $this->em->refresh($user);
        $this->assertNotNull($user->getLastLoginAt());
    }

    public function testDisablingAUserEjectsThemFromTheNextRequest(): void
    {
        $user = $this->createUser('test_eject_user', 'secret123');
        $this->loginViaForm('test_eject_user', 'secret123');
        $this->client->request('GET', '/brackets');
        $this->assertResponseIsSuccessful();

        $user->setStatus(\App\Entity\UserStatus::Disabled);
        $this->em->flush();

        $this->client->catchExceptions(false);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->client->request('GET', '/brackets');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/SecurityControllerTest.php`
Expected: FAIL — the disabled user logs in successfully and `lastLoginAt` stays null.

- [ ] **Step 3: Write the service**

Create `src/Security/SessionAuthenticator.php`:

```php
<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Session-based authentication. This app deliberately does not use Symfony's
 * security component — see CLAUDE.md. All authorization goes through here.
 */
class SessionAuthenticator
{
    public function __construct(
        private RequestStack $requestStack,
        private UserRepository $userRepository,
    ) {
    }

    public function getUser(): ?User
    {
        $session = $this->requestStack->getSession();
        $userId = $session->get('user_id');
        if (!$userId) {
            return null;
        }

        $user = $this->userRepository->find($userId);

        // A user disabled mid-session is ejected on their next request.
        if (!$user || !$user->isActive()) {
            return null;
        }

        return $user;
    }

    public function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user) {
            throw new AccessDeniedException('Authentication required.');
        }
        return $user;
    }

    public function requireAdmin(): User
    {
        $user = $this->requireUser();
        if (!$user->isAdmin()) {
            throw new AccessDeniedException('Administrator access required.');
        }
        return $user;
    }

    public function login(User $user): void
    {
        $session = $this->requestStack->getSession();
        // Regenerate the session id to defeat session fixation.
        $session->migrate(true);
        $session->set('user_id', $user->getId());
        $session->set('username', $user->getUsername());
    }

    public function logout(): void
    {
        $this->requestStack->getSession()->invalidate();
    }
}
```

- [ ] **Step 4: Use it in SecurityController**

Replace the body of `login()` in `src/Controller/SecurityController.php` so it takes `SessionAuthenticator $auth` and `EntityManagerInterface $em`, and the success branch becomes:

```php
            $user = $userRepository->findByUsername($username);

            if ($user && $user->isActive() && password_verify($password, $user->getPassword())) {
                $auth->login($user);
                $user->setLastLoginAt(new \DateTimeImmutable());
                $em->flush();
                return $this->redirectToRoute('app_bracket_index');
            }

            // Deliberately identical message for bad credentials and disabled
            // accounts — don't disclose which accounts exist or are disabled.
            $error = 'Invalid username or password.';
```

Change `logout()` to call `$auth->logout()`, and both `home()` and `login()` to use `$auth->getUser()` instead of reading `user_id` from the session directly.

- [ ] **Step 5: Replace the duplicated helpers**

In `src/Controller/BracketController.php` and `src/Controller/GameController.php`: delete the private `requireUser()` method from each, inject `SessionAuthenticator $auth` into each action that used it, and replace `$this->requireUser($request, $userRepository)` with `$auth->requireUser()`. Remove now-unused `UserRepository` parameters where nothing else uses them.

- [ ] **Step 6: Run the suite**

Run: `ddev composer test`
Expected: PASS, including the three new tests.

- [ ] **Step 7: Commit**

```bash
git add src/Security/ src/Controller/ tests/
git commit -m "Add SessionAuthenticator, block disabled users, fix session fixation"
```

---

## Task 3: Close the bracket authorization hole

**Files:**
- Modify: `src/Entity/Bracket.php`, `src/Security/SessionAuthenticator.php`, `src/Controller/BracketController.php`, `src/Controller/GameController.php`
- Test: `tests/Functional/BracketAuthorizationTest.php`

**Interfaces:**
- Consumes: `SessionAuthenticator::requireUser()` (Task 2).
- Produces: `Bracket::hasPlayer(User): bool`; `SessionAuthenticator::requireBracketAccess(Bracket): User`.

- [ ] **Step 1: Write the failing test**

Create `tests/Functional/BracketAuthorizationTest.php`:

```php
<?php

namespace App\Tests\Functional;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class BracketAuthorizationTest extends WebTestCase
{
    public function testOutsiderCannotViewAnotherUsersBracket(): void
    {
        $p1 = $this->createUser('authz_p1');
        $p2 = $this->createUser('authz_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_outsider');
        $this->loginViaForm('authz_outsider');

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', "/brackets/{$bracket->getId()}");
    }

    public function testOutsiderCannotEditAnotherUsersBracket(): void
    {
        $p1 = $this->createUser('authz_edit_p1');
        $p2 = $this->createUser('authz_edit_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_edit_outsider');
        $this->loginViaForm('authz_edit_outsider');

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', "/brackets/{$bracket->getId()}/edit");
    }

    public function testOutsiderCannotCallBracketApiEndpoints(): void
    {
        $p1 = $this->createUser('authz_api_p1');
        $p2 = $this->createUser('authz_api_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_api_outsider');
        $this->loginViaForm('authz_api_outsider');

        $id = $bracket->getId();
        foreach (["/api/brackets/$id/pull-spreads", "/api/brackets/$id/update-scores"] as $url) {
            $this->client->catchExceptions(false);
            try {
                $this->client->request('POST', $url, ['round' => 1]);
                $this->fail("Expected AccessDeniedException for $url");
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPlayerCanViewTheirOwnBracket(): void
    {
        $p1 = $this->createUser('authz_own_p1');
        $p2 = $this->createUser('authz_own_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createTeam('Duke', 1);
        $this->loginViaForm('authz_own_p1');

        $this->client->request('GET', "/brackets/{$bracket->getId()}");
        $this->assertResponseIsSuccessful();
    }

    public function testAdminCanViewAnyBracket(): void
    {
        $p1 = $this->createUser('authz_admin_p1');
        $p2 = $this->createUser('authz_admin_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_admin', 'password', null, true);
        $this->loginViaForm('authz_admin');

        $this->client->request('GET', "/brackets/{$bracket->getId()}");
        $this->assertResponseIsSuccessful();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/BracketAuthorizationTest.php`
Expected: FAIL — the outsider tests return 200 instead of throwing.

- [ ] **Step 3: Add the ownership test to Bracket**

In `src/Entity/Bracket.php`:

```php
    public function hasPlayer(User $user): bool
    {
        return ($this->player1 !== null && $this->player1->getId() === $user->getId())
            || ($this->player2 !== null && $this->player2->getId() === $user->getId());
    }
```

- [ ] **Step 4: Add the guard to SessionAuthenticator**

```php
    public function requireBracketAccess(Bracket $bracket): User
    {
        $user = $this->requireUser();
        if (!$bracket->hasPlayer($user) && !$user->isAdmin()) {
            throw new AccessDeniedException('This bracket belongs to someone else.');
        }
        return $user;
    }
```

Add `use App\Entity\Bracket;`.

- [ ] **Step 5: Apply it**

In `src/Controller/BracketController.php`, replace `$auth->requireUser()` with `$auth->requireBracketAccess($bracket)` in `show()`, `edit()`, `pullSpreads()`, `updateScores()`, and the remaining two bracket API actions. In `src/Controller/GameController.php`, use `$auth->requireBracketAccess($game->getBracket())` in every action that loads a `Game`.

- [ ] **Step 6: Run the suite**

Run: `ddev composer test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/ tests/
git commit -m "Require bracket ownership for bracket and game routes"
```

---

## Task 4: CSRF protection

**Files:**
- Modify: `templates/base.html.twig`, `templates/security/login.html.twig`, `templates/bracket/create.html.twig`, `templates/bracket/edit.html.twig`, `assets/app.js`, `src/Controller/SecurityController.php`, `src/Controller/BracketController.php`, `src/Controller/GameController.php`
- Test: `tests/Functional/CsrfTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a single CSRF token id, `'app'`, used by every form and every `fetch`.

- [ ] **Step 1: Install the component**

Run: `ddev composer require symfony/security-csrf`

- [ ] **Step 2: Write the failing test**

Create `tests/Functional/CsrfTest.php`:

```php
<?php

namespace App\Tests\Functional;

class CsrfTest extends WebTestCase
{
    public function testLoginRejectsMissingCsrfToken(): void
    {
        $this->createUser('csrf_login_user', 'secret123');
        $this->client->request('POST', '/login', [
            'username' => 'csrf_login_user',
            'password' => 'secret123',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.bg-red-100');
    }

    public function testLoginAcceptsFormSubmittedWithToken(): void
    {
        $this->createUser('csrf_ok_user', 'secret123');
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Login')->form([
            'username' => 'csrf_ok_user',
            'password' => 'secret123',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/brackets');
    }

    public function testBaseTemplateExposesCsrfTokenMetaTag(): void
    {
        $this->createUser('csrf_meta_user');
        $this->loginViaForm('csrf_meta_user');
        $this->client->request('GET', '/brackets');
        $this->assertSelectorExists('meta[name="csrf-token"]');
    }
}
```

**Note:** `loginViaForm()` in `WebTestCase` posts directly without a token, so it must be updated in Step 5 or every functional test breaks.

- [ ] **Step 3: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/CsrfTest.php`
Expected: FAIL — login without a token currently succeeds; no meta tag exists.

- [ ] **Step 4: Add the token to templates**

In `templates/base.html.twig`, inside `<head>`:

```twig
    <meta name="csrf-token" content="{{ csrf_token('app') }}">
```

In `templates/security/login.html.twig`, `templates/bracket/create.html.twig`, and `templates/bracket/edit.html.twig`, add inside each `<form>`:

```twig
    <input type="hidden" name="_token" value="{{ csrf_token('app') }}">
```

- [ ] **Step 5: Update the test login helper**

In `tests/Functional/WebTestCase.php`, replace `loginViaForm()` so it goes through the real form and therefore carries a token:

```php
    protected function loginViaForm(string $username = 'testuser', string $password = 'password'): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Login')->form([
            'username' => $username,
            'password' => $password,
        ]);
        $this->client->submit($form);
        if ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }
    }
```

- [ ] **Step 6: Validate server-side**

In every POST action across `SecurityController`, `BracketController`, and `GameController`, add as the first statement inside the `isMethod('POST')` branch (or at the top of a POST-only action):

```php
        if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
```

For `SecurityController::login()`, set `$error = 'Invalid username or password.'` instead of throwing, so a stale login tab shows the normal error rather than a 403 page.

- [ ] **Step 7: Send the token from JavaScript**

At the top of `assets/app.js`, after the CSS import:

```js
// CSRF token, published by base.html.twig, attached to every state-changing fetch.
function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function postForm(url, fields) {
    var formData = new FormData();
    Object.keys(fields || {}).forEach(function(key) {
        formData.append(key, fields[key]);
    });
    formData.append('_token', csrfToken());
    return fetch(url, { method: 'POST', body: formData });
}
```

Then rewrite each existing `fetch(...)` call — in `assignPick`, `setSpread`, `pullSpreads`, `pullTeams`, `updateScores` — to use `postForm()`. For example, `assignPick` becomes:

```js
    postForm('/api/games/' + gameId + '/pick', { team_id: teamId })
    .then(function(response) {
        if (!response.ok) throw new Error('Failed to save pick');
        return response.json();
    })
```

`pullTeams` passes an empty object: `postForm('/api/brackets/' + bracketId + '/pull-teams', {})`.

- [ ] **Step 8: Rebuild CSS and run the suite**

Run:
```bash
ddev exec php bin/console tailwind:build
ddev composer test
```
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock config/ templates/ assets/ src/ tests/ var/tailwind/
git commit -m "Add CSRF protection to all forms and AJAX endpoints"
```

---

## Task 5: Extend app:user with --email and --admin

**Files:**
- Modify: `src/Command/UserCommand.php`
- Test: `tests/Command/UserCommandTest.php`

**Interfaces:**
- Consumes: `User::setEmail()`, `User::setIsAdmin()` (Task 1).
- Produces: `app:user <username> [password] [--email=] [--admin] [--no-admin]`.

This command is the break-glass path and the mechanism for the production email backfill (spec, Migration phase 2), so password becomes optional when updating an existing account.

- [ ] **Step 1: Write the failing test**

Create `tests/Command/UserCommandTest.php`:

```php
<?php

namespace App\Tests\Command;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        return new CommandTester($application->find('app:user'));
    }

    public function testCreatesUserWithEmail(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_create_user',
            'password' => 'secret123',
            '--email' => 'cmd.create@example.com',
        ]);
        $tester->assertCommandIsSuccessful();

        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_create_user');
        $this->assertNotNull($user);
        $this->assertSame('cmd.create@example.com', $user->getEmail());
        $this->assertFalse($user->isAdmin());
    }

    public function testCreatingWithoutPasswordFails(): void
    {
        $tester = $this->tester();
        $tester->execute(['username' => 'cmd_nopass_user']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Password is required', $tester->getDisplay());
    }

    public function testUpdatesEmailWithoutTouchingPassword(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_update_user',
            'password' => 'secret123',
            '--email' => 'old@example.com',
        ]);

        $repo = self::getContainer()->get(UserRepository::class);
        $originalHash = $repo->findByUsername('cmd_update_user')->getPassword();

        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_update_user',
            '--email' => 'New@Example.com',
            '--admin' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        self::getContainer()->get('doctrine')->getManager()->clear();
        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_update_user');
        $this->assertSame('new@example.com', $user->getEmail());
        $this->assertTrue($user->isAdmin());
        $this->assertSame($originalHash, $user->getPassword());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Command/UserCommandTest.php`
Expected: FAIL — `--email` is not a defined option.

- [ ] **Step 3: Rewrite configure() and execute()**

In `src/Command/UserCommand.php`:

```php
    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username')
            ->addArgument('password', InputArgument::OPTIONAL, 'The password (required when creating)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant administrator access')
            ->addOption('no-admin', null, InputOption::VALUE_NONE, 'Revoke administrator access');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        $email = $input->getOption('email');

        $user = $this->userRepository->findByUsername($username);
        $isNew = $user === null;

        if ($isNew) {
            if (!$password) {
                $io->error('Password is required when creating a new user.');
                return Command::FAILURE;
            }
            $user = new User();
            $user->setUsername($username);
            $this->em->persist($user);
        }

        if ($password) {
            $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        }

        if ($email !== null) {
            $existing = $this->userRepository->findByEmail($email);
            if ($existing && $existing->getId() !== $user->getId()) {
                $io->error(sprintf('That email address already belongs to "%s".', $existing->getUsername()));
                return Command::FAILURE;
            }
            $user->setEmail($email);
        }

        if ($input->getOption('admin')) {
            $user->setIsAdmin(true);
        }
        if ($input->getOption('no-admin')) {
            $user->setIsAdmin(false);
        }

        $this->em->flush();

        $io->success(sprintf('%s user "%s".', $isNew ? 'Created' : 'Updated', $username));

        return Command::SUCCESS;
    }
```

Add `use Symfony\Component\Console\Input\InputOption;`.

- [ ] **Step 4: Run the tests**

Run: `ddev exec php bin/phpunit tests/Command/UserCommandTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Command/UserCommand.php tests/Command/
git commit -m "Add --email and --admin options to app:user"
```

---

## Task 6: Mailer configuration

**Files:**
- Modify: `.env`, `.env.example`, `.env.test`, `config/packages/` (new `mailer.yaml`), `composer.json`
- Test: `tests/Functional/MailerConfigTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `MailerInterface` in the container; `%env(MAILER_FROM)%` available as the `app.mail_from` parameter.

- [ ] **Step 1: Install**

Run: `ddev composer require symfony/mailer`

- [ ] **Step 2: Configure**

Create `config/packages/mailer.yaml`:

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'

parameters:
    app.mail_from: '%env(MAILER_FROM)%'
    app.mail_from_name: 'Spread Dread'
```

Add to `.env` and `.env.example` (placeholders only — never real credentials):

```
###> symfony/mailer ###
# Production uses a third-party SMTP provider; set the real DSN in .env.local
# on the server. Local development discards mail.
MAILER_DSN=null://null
MAILER_FROM=no-reply@example.com
###< symfony/mailer ###
```

Add to `.env.test`:

```
MAILER_DSN=null://null
MAILER_FROM=no-reply@example.com
```

- [ ] **Step 3: Write the test**

Create `tests/Functional/MailerConfigTest.php`:

```php
<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

class MailerConfigTest extends KernelTestCase
{
    public function testMailerIsWired(): void
    {
        self::bootKernel();
        $this->assertInstanceOf(MailerInterface::class, self::getContainer()->get(MailerInterface::class));
    }

    public function testMailFromParameterIsSet(): void
    {
        self::bootKernel();
        $this->assertNotEmpty(self::getContainer()->getParameter('app.mail_from'));
    }
}
```

- [ ] **Step 4: Run it**

Run: `ddev exec php bin/phpunit tests/Functional/MailerConfigTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock config/ .env .env.example .env.test tests/ symfony.lock
git commit -m "Add Symfony Mailer with null transport by default"
```

---

## Task 7: Invite entity and InviteService

**Files:**
- Create: `src/Entity/Invite.php`, `src/Entity/InviteStatus.php`, `src/Repository/InviteRepository.php`, `src/Service/InviteService.php`, `templates/email/invite.html.twig`, `templates/email/invite.txt.twig`
- Test: `tests/Service/InviteServiceTest.php`

**Interfaces:**
- Consumes: `User`, `UserStatus` (Task 1), `MailerInterface` (Task 6).
- Produces:
  - `InviteStatus::Sent` / `::Accepted` / `::Revoked`
  - `Invite::getEmail(): string`, `::getStatus(): InviteStatus`, `::getExpiresAt(): \DateTimeImmutable`, `::isExpired(): bool`, `::isRedeemable(): bool`, `::getCreatedBy(): User`, `::getAcceptedUser(): ?User`
  - `InviteService::create(string $email, User $createdBy): Invite` — returns the invite; the raw token is available via `InviteService::lastToken()`
  - `InviteService::urlFor(string $rawToken): string`
  - `InviteService::findRedeemable(string $rawToken): ?Invite`
  - `InviteService::accept(Invite, string $username, string $password): User`
  - `InviteService::resend(Invite): void`, `::revoke(Invite): void`
  - `InviteService::send(Invite, string $rawToken): bool` — false when the transport fails

- [ ] **Step 1: Write the failing tests**

Create `tests/Service/InviteServiceTest.php`:

```php
<?php

namespace App\Tests\Service;

use App\Entity\InviteStatus;
use App\Repository\UserRepository;
use App\Service\InviteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Entity\User;

class InviteServiceTest extends KernelTestCase
{
    private InviteService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(InviteService::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function admin(string $username): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username . '@example.com');
        $user->setPassword(password_hash('x', PASSWORD_BCRYPT));
        $user->setIsAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    public function testCreateStoresHashNotRawToken(): void
    {
        $invite = $this->service->create('inv.hash@example.com', $this->admin('inv_hash_admin'));
        $raw = $this->service->lastToken();

        $this->assertSame(64, strlen($raw));
        $this->assertNotSame($raw, $invite->getTokenHash());
        $this->assertSame(hash('sha256', $raw), $invite->getTokenHash());
    }

    public function testCreateLowercasesEmailAndSetsExpiry(): void
    {
        $invite = $this->service->create('Inv.Case@Example.com', $this->admin('inv_case_admin'));

        $this->assertSame('inv.case@example.com', $invite->getEmail());
        $this->assertSame(InviteStatus::Sent, $invite->getStatus());
        $this->assertGreaterThan(new \DateTimeImmutable('+13 days'), $invite->getExpiresAt());
        $this->assertLessThan(new \DateTimeImmutable('+15 days'), $invite->getExpiresAt());
    }

    public function testFindRedeemableReturnsInviteForValidToken(): void
    {
        $this->service->create('inv.find@example.com', $this->admin('inv_find_admin'));
        $raw = $this->service->lastToken();

        $this->assertNotNull($this->service->findRedeemable($raw));
    }

    public function testFindRedeemableRejectsUnknownToken(): void
    {
        $this->assertNull($this->service->findRedeemable(str_repeat('a', 64)));
    }

    public function testFindRedeemableRejectsExpiredInvite(): void
    {
        $invite = $this->service->create('inv.exp@example.com', $this->admin('inv_exp_admin'));
        $raw = $this->service->lastToken();

        $invite->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->assertNull($this->service->findRedeemable($raw));
    }

    public function testFindRedeemableRejectsRevokedInvite(): void
    {
        $invite = $this->service->create('inv.rev@example.com', $this->admin('inv_rev_admin'));
        $raw = $this->service->lastToken();

        $this->service->revoke($invite);

        $this->assertNull($this->service->findRedeemable($raw));
    }

    public function testAcceptCreatesActiveUserAndConsumesInvite(): void
    {
        $admin = $this->admin('inv_acc_admin');
        $invite = $this->service->create('inv.acc@example.com', $admin);

        $user = $this->service->accept($invite, 'inv_acc_user', 'secret123');

        $this->assertSame('inv.acc@example.com', $user->getEmail());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isAdmin());
        $this->assertSame($admin->getId(), $user->getInvitedBy()->getId());
        $this->assertTrue(password_verify('secret123', $user->getPassword()));
        $this->assertSame(InviteStatus::Accepted, $invite->getStatus());
        $this->assertSame($user->getId(), $invite->getAcceptedUser()->getId());
    }

    public function testAcceptedInviteIsNoLongerRedeemable(): void
    {
        $invite = $this->service->create('inv.once@example.com', $this->admin('inv_once_admin'));
        $raw = $this->service->lastToken();
        $this->service->accept($invite, 'inv_once_user', 'secret123');

        $this->assertNull($this->service->findRedeemable($raw));
    }

    public function testResendIssuesNewTokenAndKillsOldOne(): void
    {
        $invite = $this->service->create('inv.resend@example.com', $this->admin('inv_resend_admin'));
        $oldRaw = $this->service->lastToken();

        $this->service->resend($invite);
        $newRaw = $this->service->lastToken();

        $this->assertNotSame($oldRaw, $newRaw);
        $this->assertNull($this->service->findRedeemable($oldRaw));
        $this->assertNotNull($this->service->findRedeemable($newRaw));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Service/InviteServiceTest.php`
Expected: FAIL — `App\Service\InviteService` does not exist.

- [ ] **Step 3: Create the status enum**

Create `src/Entity/InviteStatus.php`:

```php
<?php

namespace App\Entity;

/**
 * Only these three are ever persisted. Expiry is derived from Invite::expiresAt
 * at read time — nothing sweeps the table, so a stored "expired" could only ever
 * be stale.
 */
enum InviteStatus: string
{
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
}
```

- [ ] **Step 4: Create the entity**

Create `src/Entity/Invite.php`:

```php
<?php

namespace App\Entity;

use App\Repository\InviteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InviteRepository::class)]
#[ORM\Index(name: 'idx_invite_token_hash', columns: ['token_hash'])]
class Invite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 20, enumType: InviteStatus::class)]
    private InviteStatus $status = InviteStatus::Sent;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedUser = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));
        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getStatus(): InviteStatus
    {
        return $this->status;
    }

    public function setStatus(InviteStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): self
    {
        $this->acceptedAt = $acceptedAt;
        return $this;
    }

    public function getAcceptedUser(): ?User
    {
        return $this->acceptedUser;
    }

    public function setAcceptedUser(?User $acceptedUser): self
    {
        $this->acceptedUser = $acceptedUser;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isRedeemable(): bool
    {
        return $this->status === InviteStatus::Sent && !$this->isExpired();
    }

    /** Display-only label; "expired" is derived, never stored. */
    public function getDisplayStatus(): string
    {
        if ($this->status === InviteStatus::Sent && $this->isExpired()) {
            return 'expired';
        }
        return $this->status->value;
    }
}
```

- [ ] **Step 5: Create the repository**

Create `src/Repository/InviteRepository.php`:

```php
<?php

namespace App\Repository;

use App\Entity\Invite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invite> */
class InviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invite::class);
    }

    public function findByTokenHash(string $tokenHash): ?Invite
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** @return Invite[] */
    public function findAllNewestFirst(): array
    {
        return $this->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
```

- [ ] **Step 6: Create the email templates**

Create `templates/email/invite.txt.twig`:

```twig
You've been invited to Spread Dread.

Set up your account here (the link expires {{ expiresAt|date('F j, Y') }}):

{{ url }}

If you weren't expecting this, you can ignore it.
```

Create `templates/email/invite.html.twig`:

```twig
<p>You've been invited to <strong>Spread Dread</strong>.</p>
<p><a href="{{ url }}">Set up your account</a></p>
<p>The link expires {{ expiresAt|date('F j, Y') }}.</p>
<p style="color:#666;font-size:12px">If you weren't expecting this, you can ignore it.</p>
```

- [ ] **Step 7: Write the service**

Create `src/Service/InviteService.php`:

```php
<?php

namespace App\Service;

use App\Entity\Invite;
use App\Entity\InviteStatus;
use App\Entity\User;
use App\Repository\InviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Owns invite creation, delivery and redemption.
 *
 * Only the SHA-256 of a token is persisted; the raw token exists in the email
 * and, transiently, in lastToken() for the request that created it. When invites
 * later become user-initiated with admin approval, the policy change lands here:
 * create() gains a pending status and send() moves behind approval.
 */
class InviteService
{
    private const TOKEN_BYTES = 32;
    private const LIFETIME = '+14 days';

    private ?string $lastToken = null;

    public function __construct(
        private EntityManagerInterface $em,
        private InviteRepository $inviteRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private string $mailFrom,
        private string $mailFromName,
    ) {
    }

    public function create(string $email, User $createdBy): Invite
    {
        $invite = new Invite();
        $invite->setEmail($email);
        $invite->setCreatedBy($createdBy);
        $invite->setTokenHash($this->issueToken());
        $invite->setExpiresAt(new \DateTimeImmutable(self::LIFETIME));

        $this->em->persist($invite);
        $this->em->flush();

        return $invite;
    }

    public function resend(Invite $invite): void
    {
        $invite->setTokenHash($this->issueToken());
        $invite->setExpiresAt(new \DateTimeImmutable(self::LIFETIME));
        $invite->setStatus(InviteStatus::Sent);
        $this->em->flush();
    }

    public function revoke(Invite $invite): void
    {
        $invite->setStatus(InviteStatus::Revoked);
        $this->em->flush();
    }

    /** The raw token generated by the most recent create() or resend(). */
    public function lastToken(): ?string
    {
        return $this->lastToken;
    }

    public function findRedeemable(string $rawToken): ?Invite
    {
        $invite = $this->inviteRepository->findByTokenHash(hash('sha256', $rawToken));

        return ($invite && $invite->isRedeemable()) ? $invite : null;
    }

    public function accept(Invite $invite, string $username, string $password): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($invite->getEmail());
        $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        $user->setInvitedBy($invite->getCreatedBy());
        $this->em->persist($user);

        $invite->setStatus(InviteStatus::Accepted);
        $invite->setAcceptedAt(new \DateTimeImmutable());
        $invite->setAcceptedUser($user);

        $this->em->flush();

        return $user;
    }

    public function urlFor(string $rawToken): string
    {
        return $this->urlGenerator->generate(
            'app_invite_accept',
            ['token' => $rawToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /** Returns false when the transport rejected the message. */
    public function send(Invite $invite, string $rawToken): bool
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->mailFromName))
            ->to($invite->getEmail())
            ->subject('You have been invited to Spread Dread')
            ->htmlTemplate('email/invite.html.twig')
            ->textTemplate('email/invite.txt.twig')
            ->context([
                'url' => $this->urlFor($rawToken),
                'expiresAt' => $invite->getExpiresAt(),
            ]);

        try {
            $this->mailer->send($email);
            return true;
        } catch (TransportExceptionInterface $e) {
            // The invite row survives so the admin can copy the link by hand.
            $this->logger->error('Invite email failed to send', [
                'invite_id' => $invite->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function issueToken(): string
    {
        $this->lastToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        return hash('sha256', $this->lastToken);
    }
}
```

- [ ] **Step 8: Bind the mail parameters**

In `config/services.yaml`, under `services:`, add:

```yaml
    App\Service\InviteService:
        arguments:
            $mailFrom: '%app.mail_from%'
            $mailFromName: '%app.mail_from_name%'
```

- [ ] **Step 9: Generate the migration and run the tests**

Run:
```bash
ddev exec php bin/console doctrine:migrations:diff
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev exec php bin/phpunit tests/Service/InviteServiceTest.php
```
Expected: PASS (9 tests)

- [ ] **Step 10: Commit**

```bash
git add src/ config/ templates/email/ tests/ migrations/
git commit -m "Add Invite entity and InviteService with hashed tokens"
```

---

## Task 8: Invite acceptance flow

**Files:**
- Create: `src/Controller/InviteController.php`, `templates/invite/accept.html.twig`, `templates/invite/invalid.html.twig`
- Test: `tests/Functional/InviteControllerTest.php`

**Interfaces:**
- Consumes: `InviteService` (Task 7), `SessionAuthenticator::login()` (Task 2).
- Produces: routes `app_invite_accept` (`GET|POST /invite/{token}`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Functional/InviteControllerTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Entity\InviteStatus;
use App\Repository\UserRepository;
use App\Service\InviteService;

class InviteControllerTest extends WebTestCase
{
    private function newInvite(string $adminUsername, string $email): array
    {
        $admin = $this->createUser($adminUsername, 'password', null, true);
        $service = static::getContainer()->get(InviteService::class);
        $invite = $service->create($email, $admin);
        return [$invite, $service->lastToken()];
    }

    public function testValidTokenRendersTheAcceptForm(): void
    {
        [, $token] = $this->newInvite('inv_form_admin', 'inv.form@example.com');

        $this->client->request('GET', "/invite/$token");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="username"]');
        $this->assertSelectorTextContains('body', 'inv.form@example.com');
    }

    public function testAcceptingCreatesAnActiveUserAndLogsThemIn(): void
    {
        [$invite, $token] = $this->newInvite('inv_accept_admin', 'inv.accept@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_accept_user',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/brackets');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $user = static::getContainer()->get(UserRepository::class)->findByUsername('inv_accept_user');
        $this->assertNotNull($user);
        $this->assertSame('inv.accept@example.com', $user->getEmail());
        $this->assertSame(InviteStatus::Accepted, $invite->getStatus());
    }

    public function testUnknownTokenShowsGenericInvalidPage(): void
    {
        $this->client->request('GET', '/invite/' . str_repeat('b', 64));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'no longer valid');
        $this->assertSelectorNotExists('input[name="username"]');
    }

    public function testExpiredTokenShowsTheSameGenericPage(): void
    {
        [$invite, $token] = $this->newInvite('inv_exp_admin', 'inv.expired@example.com');
        $invite->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->client->request('GET', "/invite/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testRevokedTokenShowsTheSameGenericPage(): void
    {
        [$invite, $token] = $this->newInvite('inv_revoked_admin', 'inv.revoked@example.com');
        $invite->setStatus(InviteStatus::Revoked);
        $this->em->flush();

        $this->client->request('GET', "/invite/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testTokenCannotBeReused(): void
    {
        [, $token] = $this->newInvite('inv_reuse_admin', 'inv.reuse@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_reuse_user',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        $this->client->submit($form);

        $this->client->request('GET', "/invite/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $this->createUser('inv_taken_user');
        [, $token] = $this->newInvite('inv_taken_admin', 'inv.taken@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_taken_user',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bg-red-100', 'already taken');
    }

    public function testMismatchedPasswordsAreRejected(): void
    {
        [, $token] = $this->newInvite('inv_mismatch_admin', 'inv.mismatch@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_mismatch_user',
            'password' => 'secret123',
            'password_confirm' => 'different',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bg-red-100', 'do not match');
    }

    public function testShortPasswordIsRejected(): void
    {
        [, $token] = $this->newInvite('inv_short_admin', 'inv.short@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_short_user',
            'password' => 'abc',
            'password_confirm' => 'abc',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bg-red-100', 'at least 8');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/InviteControllerTest.php`
Expected: FAIL — 404, no `/invite/{token}` route.

- [ ] **Step 3: Write the controller**

Create `src/Controller/InviteController.php`:

```php
<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\SessionAuthenticator;
use App\Service\InviteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InviteController extends AbstractController
{
    private const MIN_PASSWORD_LENGTH = 8;

    #[Route('/invite/{token}', name: 'app_invite_accept', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function accept(
        string $token,
        Request $request,
        InviteService $inviteService,
        UserRepository $userRepository,
        SessionAuthenticator $auth,
    ): Response {
        $invite = $inviteService->findRedeemable($token);

        // Unknown, expired, revoked and already-accepted all land here, and all
        // render the same page. Never disclose which case applies.
        if (!$invite) {
            return $this->render('invite/invalid.html.twig');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $username = trim($request->request->get('username', ''));
            $password = $request->request->get('password', '');
            $confirm = $request->request->get('password_confirm', '');

            if ($username === '') {
                $error = 'Choose a username.';
            } elseif ($userRepository->findByUsername($username)) {
                $error = 'That username is already taken.';
            } elseif (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $error = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
            } elseif ($password !== $confirm) {
                $error = 'The passwords do not match.';
            } else {
                $user = $inviteService->accept($invite, $username, $password);
                $auth->login($user);
                return $this->redirectToRoute('app_bracket_index');
            }
        }

        return $this->render('invite/accept.html.twig', [
            'invite' => $invite,
            'error' => $error,
        ]);
    }
}
```

**Note:** the `token` route requirement is `[a-f0-9]{64}`, so a malformed token 404s before touching the database. The test for an unknown token uses 64 hex characters so it reaches the controller.

- [ ] **Step 4: Write the templates**

Create `templates/invite/accept.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Create your account - Spread Dread{% endblock %}

{% block body %}
<div class="mt-16 flex flex-col items-center">
    <h1 class="text-2xl font-bold text-gray-800 mb-2">Create your account</h1>
    <p class="text-sm text-gray-500 mb-6">{{ invite.email }}</p>

    {% if error %}
        <div class="w-full max-w-xs bg-red-100 text-red-800 px-4 py-2 rounded mb-4 text-sm">{{ error }}</div>
    {% endif %}

    <form method="post" class="w-full max-w-xs">
        <input type="hidden" name="_token" value="{{ csrf_token('app') }}">

        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
        <input type="text" name="username" id="username" required autofocus
               class="w-full border border-gray-300 rounded px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500">

        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <input type="password" name="password" id="password" required minlength="8"
               class="w-full border border-gray-300 rounded px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500">

        <label for="password_confirm" class="block text-sm font-medium text-gray-700 mb-1">Confirm password</label>
        <input type="password" name="password_confirm" id="password_confirm" required minlength="8"
               class="w-full border border-gray-300 rounded px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500">

        <button type="submit" class="w-full bg-blue-700 text-white py-2 rounded font-medium hover:bg-blue-800">
            Create account
        </button>
    </form>
</div>
{% endblock %}
```

Create `templates/invite/invalid.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Invitation - Spread Dread{% endblock %}

{% block body %}
<div class="mt-16 flex flex-col items-center text-center">
    <h1 class="text-2xl font-bold text-gray-800 mb-4">This invitation is no longer valid</h1>
    <p class="text-gray-600 max-w-sm">It may have expired or already been used. Ask whoever invited you to send a new one.</p>
    <a href="{{ path('app_login') }}" class="text-sm text-blue-600 mt-6 hover:underline">Back to login</a>
</div>
{% endblock %}
```

- [ ] **Step 5: Rebuild CSS and run the tests**

Run:
```bash
ddev exec php bin/console tailwind:build
ddev exec php bin/phpunit tests/Functional/InviteControllerTest.php
```
Expected: PASS (9 tests)

- [ ] **Step 6: Commit**

```bash
git add src/Controller/InviteController.php templates/invite/ tests/ var/tailwind/
git commit -m "Add invite acceptance flow"
```

---

## Task 9: Admin dashboard

**Files:**
- Create: `src/Controller/AdminController.php`, `templates/admin/dashboard.html.twig`
- Modify: `templates/base.html.twig`, `src/Repository/UserRepository.php`
- Test: `tests/Functional/AdminControllerTest.php`

**Interfaces:**
- Consumes: `SessionAuthenticator::requireAdmin()` (Task 2), `InviteService` (Task 7), `InviteRepository::findAllNewestFirst()` (Task 7).
- Produces: routes `app_admin_dashboard` (`GET /admin`), `app_admin_invite_create` (`POST /admin/invites`), `app_admin_invite_resend`, `app_admin_invite_revoke`, `app_admin_user_status`, `app_admin_user_admin`; `UserRepository::findAllForAdmin(): User[]`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Functional/AdminControllerTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Entity\InviteStatus;
use App\Entity\UserStatus;
use App\Repository\InviteRepository;
use App\Repository\UserRepository;
use App\Service\InviteService;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AdminControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testGuestCannotReachTheDashboard(): void
    {
        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/admin');
    }

    public function testNonAdminCannotReachTheDashboard(): void
    {
        $this->createUser('adm_plain_user');
        $this->loginViaForm('adm_plain_user');

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/admin');
    }

    public function testAdminSeesAllUsers(): void
    {
        $this->createUser('adm_dash_admin', 'password', null, true);
        $this->createUser('adm_dash_other');
        $this->loginViaForm('adm_dash_admin');

        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'adm_dash_other');
    }

    public function testCreatingAnInviteSendsAnEmail(): void
    {
        $this->createUser('adm_send_admin', 'password', null, true);
        $this->loginViaForm('adm_send_admin');

        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Send invite')->form([
            'email' => 'adm.invitee@example.com',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailHeaderSame($email, 'To', 'adm.invitee@example.com');

        $invites = static::getContainer()->get(InviteRepository::class)->findBy(['email' => 'adm.invitee@example.com']);
        $this->assertCount(1, $invites);
        $this->assertSame(InviteStatus::Sent, $invites[0]->getStatus());
    }

    public function testInvitingAnExistingEmailIsRejected(): void
    {
        $this->createUser('adm_dup_admin', 'password', null, true);
        $this->createUser('adm_dup_target', 'password', 'adm.dup@example.com');
        $this->loginViaForm('adm_dup_admin');

        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Send invite')->form(['email' => 'adm.dup@example.com']);
        $this->client->submit($form);
        $this->client->followRedirect();

        $this->assertSelectorTextContains('body', 'already has an account');
        $this->assertEmailCount(0);
    }

    public function testDisablingAUserBlocksTheirLogin(): void
    {
        $this->createUser('adm_disable_admin', 'password', null, true);
        $target = $this->createUser('adm_disable_target', 'secret123');
        $this->loginViaForm('adm_disable_admin');

        $this->client->request('POST', "/admin/users/{$target->getId()}/status", [
            '_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('app')->getValue(),
            'status' => 'disabled',
        ]);
        $this->assertResponseRedirects('/admin');

        $this->em->refresh($target);
        $this->assertSame(UserStatus::Disabled, $target->getStatus());
    }

    public function testGrantingAdmin(): void
    {
        $this->createUser('adm_grant_admin', 'password', null, true);
        $target = $this->createUser('adm_grant_target');
        $this->loginViaForm('adm_grant_admin');

        $this->client->request('POST', "/admin/users/{$target->getId()}/admin", [
            '_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('app')->getValue(),
            'is_admin' => '1',
        ]);
        $this->assertResponseRedirects('/admin');

        $this->em->refresh($target);
        $this->assertTrue($target->isAdmin());
    }

    public function testAdminCannotDisableThemselves(): void
    {
        $admin = $this->createUser('adm_self_admin', 'password', null, true);
        $this->loginViaForm('adm_self_admin');

        $this->client->request('POST', "/admin/users/{$admin->getId()}/status", [
            '_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('app')->getValue(),
            'status' => 'disabled',
        ]);
        $this->client->followRedirect();

        $this->em->refresh($admin);
        $this->assertSame(UserStatus::Active, $admin->getStatus());
    }

    public function testRevokingAnInvite(): void
    {
        $admin = $this->createUser('adm_revoke_admin', 'password', null, true);
        $service = static::getContainer()->get(InviteService::class);
        $invite = $service->create('adm.revoke@example.com', $admin);
        $this->loginViaForm('adm_revoke_admin');

        $this->client->request('POST', "/admin/invites/{$invite->getId()}/revoke", [
            '_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('app')->getValue(),
        ]);
        $this->assertResponseRedirects('/admin');

        $this->em->refresh($invite);
        $this->assertSame(InviteStatus::Revoked, $invite->getStatus());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/AdminControllerTest.php`
Expected: FAIL — 404 on `/admin`.

- [ ] **Step 3: Add the repository method**

In `src/Repository/UserRepository.php`:

```php
    /** @return User[] */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.lastLoginAt', 'DESC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
```

- [ ] **Step 4: Write the controller**

Create `src/Controller/AdminController.php`:

```php
<?php

namespace App\Controller;

use App\Entity\Invite;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\InviteRepository;
use App\Repository\UserRepository;
use App\Security\SessionAuthenticator;
use App\Service\InviteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(
        SessionAuthenticator $auth,
        UserRepository $userRepository,
        InviteRepository $inviteRepository,
    ): Response {
        $auth->requireAdmin();

        return $this->render('admin/dashboard.html.twig', [
            'users' => $userRepository->findAllForAdmin(),
            'invites' => $inviteRepository->findAllNewestFirst(),
        ]);
    }

    #[Route('/admin/invites', name: 'app_admin_invite_create', methods: ['POST'])]
    public function createInvite(
        Request $request,
        SessionAuthenticator $auth,
        InviteService $inviteService,
        UserRepository $userRepository,
    ): Response {
        $admin = $auth->requireAdmin();
        $this->assertCsrf($request);

        $email = trim($request->request->get('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'That does not look like an email address.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if ($userRepository->findByEmail($email)) {
            $this->addFlash('error', 'That address already has an account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $invite = $inviteService->create($email, $admin);
        $token = (string) $inviteService->lastToken();

        if ($inviteService->send($invite, $token)) {
            $this->addFlash('success', 'Invitation sent to ' . $invite->getEmail() . '.');
        } else {
            // Only the hash is stored, so this is the one and only chance to
            // show the link. Say so plainly.
            $this->addFlash('warning', sprintf(
                'Invitation created but the email could not be sent. Copy this link now — it cannot be shown again: %s',
                $inviteService->urlFor($token),
            ));
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/invites/{id}/resend', name: 'app_admin_invite_resend', methods: ['POST'])]
    public function resendInvite(
        Request $request,
        Invite $invite,
        SessionAuthenticator $auth,
        InviteService $inviteService,
    ): Response {
        $auth->requireAdmin();
        $this->assertCsrf($request);

        $inviteService->resend($invite);
        $token = (string) $inviteService->lastToken();

        if ($inviteService->send($invite, $token)) {
            $this->addFlash('success', 'Invitation resent to ' . $invite->getEmail() . '.');
        } else {
            $this->addFlash('warning', sprintf(
                'New link generated but the email could not be sent. Copy this link now — it cannot be shown again: %s',
                $inviteService->urlFor($token),
            ));
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/invites/{id}/revoke', name: 'app_admin_invite_revoke', methods: ['POST'])]
    public function revokeInvite(
        Request $request,
        Invite $invite,
        SessionAuthenticator $auth,
        InviteService $inviteService,
    ): Response {
        $auth->requireAdmin();
        $this->assertCsrf($request);

        $inviteService->revoke($invite);
        $this->addFlash('success', 'Invitation revoked.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/status', name: 'app_admin_user_status', methods: ['POST'])]
    public function setUserStatus(
        Request $request,
        User $user,
        SessionAuthenticator $auth,
        EntityManagerInterface $em,
    ): Response {
        $admin = $auth->requireAdmin();
        $this->assertCsrf($request);

        if ($user->getId() === $admin->getId()) {
            $this->addFlash('error', 'You cannot disable your own account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $status = UserStatus::tryFrom((string) $request->request->get('status'));
        if ($status === null) {
            $this->addFlash('error', 'Unknown status.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $user->setStatus($status);
        $em->flush();

        $this->addFlash('success', sprintf('%s is now %s.', $user->getUsername(), $status->label()));

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/admin', name: 'app_admin_user_admin', methods: ['POST'])]
    public function setUserAdmin(
        Request $request,
        User $user,
        SessionAuthenticator $auth,
        EntityManagerInterface $em,
    ): Response {
        $admin = $auth->requireAdmin();
        $this->assertCsrf($request);

        if ($user->getId() === $admin->getId()) {
            $this->addFlash('error', 'You cannot change your own admin access.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $user->setIsAdmin($request->request->get('is_admin') === '1');
        $em->flush();

        $this->addFlash('success', sprintf('Updated admin access for %s.', $user->getUsername()));

        return $this->redirectToRoute('app_admin_dashboard');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
```

- [ ] **Step 5: Write the dashboard template**

Create `templates/admin/dashboard.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Admin - Spread Dread{% endblock %}
{% block main_class %}max-w-5xl mx-auto px-4 py-4{% endblock %}

{% block body %}
<h1 class="text-xl font-bold text-gray-800 mb-4">Admin</h1>

<section class="bg-white rounded-lg shadow p-4 mb-6">
    <h2 class="font-semibold text-gray-800 mb-3">Invite someone</h2>
    <form method="post" action="{{ path('app_admin_invite_create') }}" class="flex flex-wrap gap-2">
        <input type="hidden" name="_token" value="{{ csrf_token('app') }}">
        <input type="email" name="email" required placeholder="their@email.com"
               class="flex-1 min-w-[16rem] border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <button type="submit" class="bg-blue-700 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-800">
            Send invite
        </button>
    </form>
</section>

<section class="bg-white rounded-lg shadow p-4 mb-6 overflow-x-auto">
    <h2 class="font-semibold text-gray-800 mb-3">Users ({{ users|length }})</h2>
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 border-b">
            <tr>
                <th class="py-2 pr-3">Username</th>
                <th class="py-2 pr-3">Email</th>
                <th class="py-2 pr-3">Status</th>
                <th class="py-2 pr-3">Admin</th>
                <th class="py-2 pr-3">Last login</th>
                <th class="py-2 pr-3">Invited by</th>
                <th class="py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
        {% for u in users %}
            <tr class="border-b last:border-0">
                <td class="py-2 pr-3 font-medium text-gray-800">{{ u.username }}</td>
                <td class="py-2 pr-3 text-gray-600">
                    {{ u.email ?? '—' }}
                    {% if u.email is null %}<span class="text-red-600 text-xs">(missing)</span>{% endif %}
                </td>
                <td class="py-2 pr-3">
                    <span class="{{ u.active ? 'text-green-700' : 'text-gray-400' }}">{{ u.status.label }}</span>
                </td>
                <td class="py-2 pr-3">{{ u.admin ? 'Yes' : '—' }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ u.lastLoginAt ? u.lastLoginAt|date('M j, Y') : 'never' }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ u.invitedBy ? u.invitedBy.username : '—' }}</td>
                <td class="py-2 whitespace-nowrap">
                    <form method="post" action="{{ path('app_admin_user_status', {id: u.id}) }}" class="inline">
                        <input type="hidden" name="_token" value="{{ csrf_token('app') }}">
                        <input type="hidden" name="status" value="{{ u.active ? 'disabled' : 'active' }}">
                        <button type="submit" class="text-blue-600 hover:underline">{{ u.active ? 'Disable' : 'Enable' }}</button>
                    </form>
                    <form method="post" action="{{ path('app_admin_user_admin', {id: u.id}) }}" class="inline ml-2">
                        <input type="hidden" name="_token" value="{{ csrf_token('app') }}">
                        <input type="hidden" name="is_admin" value="{{ u.admin ? '0' : '1' }}">
                        <button type="submit" class="text-blue-600 hover:underline">{{ u.admin ? 'Revoke admin' : 'Make admin' }}</button>
                    </form>
                </td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
</section>

<section class="bg-white rounded-lg shadow p-4 overflow-x-auto">
    <h2 class="font-semibold text-gray-800 mb-3">Invites ({{ invites|length }})</h2>
    {% if invites is empty %}
        <p class="text-gray-500 text-sm">No invitations yet.</p>
    {% else %}
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 border-b">
            <tr>
                <th class="py-2 pr-3">Email</th>
                <th class="py-2 pr-3">Status</th>
                <th class="py-2 pr-3">Sent</th>
                <th class="py-2 pr-3">Expires</th>
                <th class="py-2 pr-3">Invited by</th>
                <th class="py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
        {% for i in invites %}
            <tr class="border-b last:border-0">
                <td class="py-2 pr-3 text-gray-800">{{ i.email }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ i.displayStatus }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ i.createdAt|date('M j, Y') }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ i.expiresAt|date('M j, Y') }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ i.createdBy.username }}</td>
                <td class="py-2 whitespace-nowrap">
                    {% if i.redeemable %}
                        <form method="post" action="{{ path('app_admin_invite_resend', {id: i.id}) }}" class="inline">
                            <input type="hidden" name="_token" value="{{ csrf_token('app') }}">
                            <button type="submit" class="text-blue-600 hover:underline">Resend</button>
                        </form>
                        <form method="post" action="{{ path('app_admin_invite_revoke', {id: i.id}) }}" class="inline ml-2">
                            <input type="hidden" name="_token" value="{{ csrf_token('app') }}">
                            <button type="submit" class="text-red-600 hover:underline">Revoke</button>
                        </form>
                    {% else %}
                        <span class="text-gray-400">—</span>
                    {% endif %}
                </td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
    {% endif %}
</section>
{% endblock %}
```

- [ ] **Step 6: Add the nav link and the warning flash type**

In `templates/base.html.twig`, change the flash loop to include `warning`:

```twig
    {% for type in ['success', 'error', 'warning', 'info'] %}
```

and give warnings a colour by changing the class expression to:

```twig
{{ type == 'error' ? 'bg-red-100 text-red-800' : (type == 'success' ? 'bg-green-100 text-green-800' : (type == 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800')) }}
```

Add the Admin link in the nav, between the username and Logout:

```twig
            {% if app.request.session.get('user_id') and is_admin ?? false %}
                <a href="{{ path('app_admin_dashboard') }}" class="text-sm text-blue-200 hover:text-white mr-3">Admin</a>
            {% endif %}
```

To make `is_admin` available everywhere without touching every controller, add to `config/packages/twig.yaml`:

```yaml
twig:
    default_path: '%kernel.project_dir%/templates'
    globals:
        app_auth: '@App\Security\SessionAuthenticator'
```

and use `app_auth.user and app_auth.user.admin` in the condition instead of `is_admin`:

```twig
            {% if app_auth.user and app_auth.user.admin %}
                <a href="{{ path('app_admin_dashboard') }}" class="text-sm text-blue-200 hover:text-white mr-3">Admin</a>
            {% endif %}
```

- [ ] **Step 7: Rebuild CSS and run the tests**

Run:
```bash
ddev exec php bin/console tailwind:build
ddev exec php bin/phpunit tests/Functional/AdminControllerTest.php
```
Expected: PASS (9 tests)

- [ ] **Step 8: Run the full suite**

Run: `ddev composer test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add src/ config/ templates/ tests/ var/tailwind/
git commit -m "Add admin dashboard for users and invites"
```

---

## Task 10: Password reset

**Files:**
- Create: `src/Entity/PasswordResetToken.php`, `src/Repository/PasswordResetTokenRepository.php`, `src/Service/PasswordResetService.php`, `src/Controller/PasswordResetController.php`, `templates/password_reset/request.html.twig`, `templates/password_reset/reset.html.twig`, `templates/email/password_reset.html.twig`, `templates/email/password_reset.txt.twig`
- Modify: `templates/security/login.html.twig`
- Test: `tests/Functional/PasswordResetTest.php`

**Interfaces:**
- Consumes: `UserRepository::findByEmail()` (Task 1), `SessionAuthenticator::login()` (Task 2), `MailerInterface` (Task 6).
- Produces: `PasswordResetService::request(string $email): void`, `::findValid(string $rawToken): ?PasswordResetToken`, `::consume(PasswordResetToken, string $newPassword): User`; routes `app_password_forgot` (`GET|POST /forgot-password`), `app_password_reset` (`GET|POST /reset-password/{token}`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Functional/PasswordResetTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Repository\PasswordResetTokenRepository;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

class PasswordResetTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testRequestFormRenders(): void
    {
        $this->client->request('GET', '/forgot-password');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
    }

    public function testKnownAddressSendsAnEmail(): void
    {
        $this->createUser('pr_known_user', 'password', 'pr.known@example.com');

        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->selectButton('Send reset link')->form(['email' => 'pr.known@example.com']);
        $this->client->submit($form);

        $this->assertEmailCount(1);
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'To', 'pr.known@example.com');
    }

    public function testUnknownAddressLooksIdenticalButSendsNothing(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->selectButton('Send reset link')->form(['email' => 'pr.nobody@example.com']);
        $this->client->submit($form);
        $unknownResponse = $this->client->getResponse()->getContent();

        $this->assertEmailCount(0);

        $this->createUser('pr_cmp_user', 'password', 'pr.cmp@example.com');
        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->selectButton('Send reset link')->form(['email' => 'pr.cmp@example.com']);
        $this->client->submit($form);
        $knownResponse = $this->client->getResponse()->getContent();

        $this->assertSame($knownResponse, $unknownResponse);
    }

    public function testResettingChangesThePassword(): void
    {
        $this->createUser('pr_change_user', 'oldpassword', 'pr.change@example.com');
        $token = $this->requestToken('pr.change@example.com');

        $crawler = $this->client->request('GET', "/reset-password/$token");
        $form = $crawler->selectButton('Set new password')->form([
            'password' => 'newpassword123',
            'password_confirm' => 'newpassword123',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/brackets');

        $this->client->request('GET', '/logout');
        $this->loginViaForm('pr_change_user', 'newpassword123');
        $this->client->request('GET', '/brackets');
        $this->assertResponseIsSuccessful();
    }

    public function testTokenIsSingleUse(): void
    {
        $this->createUser('pr_once_user', 'oldpassword', 'pr.once@example.com');
        $token = $this->requestToken('pr.once@example.com');

        $crawler = $this->client->request('GET', "/reset-password/$token");
        $form = $crawler->selectButton('Set new password')->form([
            'password' => 'newpassword123',
            'password_confirm' => 'newpassword123',
        ]);
        $this->client->submit($form);

        $this->client->request('GET', "/reset-password/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->createUser('pr_exp_user', 'oldpassword', 'pr.exp@example.com');
        $token = $this->requestToken('pr.exp@example.com');

        $record = static::getContainer()->get(PasswordResetTokenRepository::class)
            ->findByTokenHash(hash('sha256', $token));
        $record->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $this->client->request('GET', "/reset-password/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testSuccessfulResetInvalidatesOtherOutstandingTokens(): void
    {
        $this->createUser('pr_multi_user', 'oldpassword', 'pr.multi@example.com');
        $first = $this->requestToken('pr.multi@example.com');
        $second = $this->requestToken('pr.multi@example.com');

        $crawler = $this->client->request('GET', "/reset-password/$second");
        $form = $crawler->selectButton('Set new password')->form([
            'password' => 'newpassword123',
            'password_confirm' => 'newpassword123',
        ]);
        $this->client->submit($form);

        $this->client->request('GET', "/reset-password/$first");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    /** Requests a reset through the service and returns the raw token. */
    private function requestToken(string $email): string
    {
        $service = static::getContainer()->get(PasswordResetService::class);
        $service->request($email);
        return $service->lastToken();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/PasswordResetTest.php`
Expected: FAIL — 404 on `/forgot-password`.

- [ ] **Step 3: Create the entity**

Create `src/Entity/PasswordResetToken.php`:

```php
<?php

namespace App\Entity;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Index(name: 'idx_reset_token_hash', columns: ['token_hash'])]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): self
    {
        $this->usedAt = $usedAt;
        return $this;
    }

    public function isValid(): bool
    {
        return $this->usedAt === null && $this->expiresAt >= new \DateTimeImmutable();
    }
}
```

- [ ] **Step 4: Create the repository**

Create `src/Repository/PasswordResetTokenRepository.php`:

```php
<?php

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PasswordResetToken> */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findByTokenHash(string $tokenHash): ?PasswordResetToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** Marks every unused token for this user as used. */
    public function invalidateAllForUser(User $user, \DateTimeImmutable $at): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':at')
            ->where('t.user = :user')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
```

- [ ] **Step 5: Create the email templates**

Create `templates/email/password_reset.txt.twig`:

```twig
Someone asked to reset the password for your Spread Dread account.

Set a new password here (the link expires in one hour):

{{ url }}

If this wasn't you, ignore this message — your password hasn't changed.
```

Create `templates/email/password_reset.html.twig`:

```twig
<p>Someone asked to reset the password for your <strong>Spread Dread</strong> account.</p>
<p><a href="{{ url }}">Set a new password</a></p>
<p>The link expires in one hour.</p>
<p style="color:#666;font-size:12px">If this wasn't you, ignore this message — your password hasn't changed.</p>
```

- [ ] **Step 6: Write the service**

Create `src/Service/PasswordResetService.php`:

```php
<?php

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasswordResetService
{
    private const TOKEN_BYTES = 32;
    private const LIFETIME = '+1 hour';

    private ?string $lastToken = null;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $tokenRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private string $mailFrom,
        private string $mailFromName,
    ) {
    }

    /**
     * Always succeeds silently. Callers must render the same response whether or
     * not the address exists, so the form can't enumerate accounts.
     */
    public function request(string $email): void
    {
        $this->lastToken = null;

        $user = $this->userRepository->findByEmail($email);
        if (!$user || !$user->isActive() || $user->getEmail() === null) {
            return;
        }

        $raw = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->lastToken = $raw;

        $token = new PasswordResetToken();
        $token->setUser($user);
        $token->setTokenHash(hash('sha256', $raw));
        $token->setExpiresAt(new \DateTimeImmutable(self::LIFETIME));
        $this->em->persist($token);
        $this->em->flush();

        $this->send($user, $raw);
    }

    public function lastToken(): ?string
    {
        return $this->lastToken;
    }

    public function findValid(string $rawToken): ?PasswordResetToken
    {
        $token = $this->tokenRepository->findByTokenHash(hash('sha256', $rawToken));

        return ($token && $token->isValid()) ? $token : null;
    }

    public function consume(PasswordResetToken $token, string $newPassword): User
    {
        $user = $token->getUser();
        $user->setPassword(password_hash($newPassword, PASSWORD_BCRYPT));

        $now = new \DateTimeImmutable();
        $token->setUsedAt($now);
        $this->em->flush();

        // Any other link sitting in the mailbox dies with this reset.
        $this->tokenRepository->invalidateAllForUser($user, $now);

        return $user;
    }

    private function send(User $user, string $rawToken): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->mailFromName))
            ->to($user->getEmail())
            ->subject('Reset your Spread Dread password')
            ->htmlTemplate('email/password_reset.html.twig')
            ->textTemplate('email/password_reset.txt.twig')
            ->context([
                'url' => $this->urlGenerator->generate(
                    'app_password_reset',
                    ['token' => $rawToken],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Password reset email failed to send', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

Bind its mail parameters in `config/services.yaml` alongside `InviteService`:

```yaml
    App\Service\PasswordResetService:
        arguments:
            $mailFrom: '%app.mail_from%'
            $mailFromName: '%app.mail_from_name%'
```

- [ ] **Step 7: Write the controller**

Create `src/Controller/PasswordResetController.php`:

```php
<?php

namespace App\Controller;

use App\Security\SessionAuthenticator;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    private const MIN_PASSWORD_LENGTH = 8;

    #[Route('/forgot-password', name: 'app_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request, PasswordResetService $service): Response
    {
        $submitted = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $service->request(trim($request->request->get('email', '')));

            // Identical response either way — never reveal whether the address
            // is registered.
            $submitted = true;
        }

        return $this->render('password_reset/request.html.twig', [
            'submitted' => $submitted,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_password_reset', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function reset(
        string $token,
        Request $request,
        PasswordResetService $service,
        SessionAuthenticator $auth,
    ): Response {
        $record = $service->findValid($token);

        if (!$record) {
            return $this->render('invite/invalid.html.twig');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $password = $request->request->get('password', '');
            $confirm = $request->request->get('password_confirm', '');

            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $error = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
            } elseif ($password !== $confirm) {
                $error = 'The passwords do not match.';
            } else {
                $user = $service->consume($record, $password);
                $auth->login($user);
                $this->addFlash('success', 'Your password has been updated.');
                return $this->redirectToRoute('app_bracket_index');
            }
        }

        return $this->render('password_reset/reset.html.twig', [
            'error' => $error,
        ]);
    }
}
```

**Note:** `reset()` reuses `templates/invite/invalid.html.twig` — the "no longer valid" copy is identical and duplicating it would let the two drift apart.

- [ ] **Step 8: Write the templates**

Create `templates/password_reset/request.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Forgot password - Spread Dread{% endblock %}

{% block body %}
<div class="mt-16 flex flex-col items-center">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Forgot your password?</h1>

    {% if submitted %}
        <p class="max-w-xs text-center text-gray-600">
            If that address has an account, a reset link is on its way. Check your inbox.
        </p>
        <a href="{{ path('app_login') }}" class="text-sm text-blue-600 mt-6 hover:underline">Back to login</a>
    {% else %}
        <form method="post" class="w-full max-w-xs">
            <input type="hidden" name="_token" value="{{ csrf_token('app') }}">

            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
            <input type="email" name="email" id="email" required autofocus
                   class="w-full border border-gray-300 rounded px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500">

            <button type="submit" class="w-full bg-blue-700 text-white py-2 rounded font-medium hover:bg-blue-800">
                Send reset link
            </button>
        </form>
        <a href="{{ path('app_login') }}" class="text-sm text-blue-600 mt-4 hover:underline">Back to login</a>
    {% endif %}
</div>
{% endblock %}
```

Create `templates/password_reset/reset.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Set a new password - Spread Dread{% endblock %}

{% block body %}
<div class="mt-16 flex flex-col items-center">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Set a new password</h1>

    {% if error %}
        <div class="w-full max-w-xs bg-red-100 text-red-800 px-4 py-2 rounded mb-4 text-sm">{{ error }}</div>
    {% endif %}

    <form method="post" class="w-full max-w-xs">
        <input type="hidden" name="_token" value="{{ csrf_token('app') }}">

        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New password</label>
        <input type="password" name="password" id="password" required minlength="8" autofocus
               class="w-full border border-gray-300 rounded px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500">

        <label for="password_confirm" class="block text-sm font-medium text-gray-700 mb-1">Confirm password</label>
        <input type="password" name="password_confirm" id="password_confirm" required minlength="8"
               class="w-full border border-gray-300 rounded px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-blue-500">

        <button type="submit" class="w-full bg-blue-700 text-white py-2 rounded font-medium hover:bg-blue-800">
            Set new password
        </button>
    </form>
</div>
{% endblock %}
```

Add the link to `templates/security/login.html.twig`, after the Login button:

```twig
        <a href="{{ path('app_password_forgot') }}" class="block text-center text-sm text-blue-600 mt-4 hover:underline">
            Forgot your password?
        </a>
```

- [ ] **Step 9: Migrate, rebuild CSS, run the tests**

Run:
```bash
ddev exec php bin/console doctrine:migrations:diff
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev exec php bin/console tailwind:build
ddev exec php bin/phpunit tests/Functional/PasswordResetTest.php
```
Expected: PASS (7 tests)

- [ ] **Step 10: Commit**

```bash
git add src/ config/ templates/ tests/ migrations/ var/tailwind/
git commit -m "Add self-service password reset"
```

---

## Task 11: Opponent autocomplete on bracket creation

**Files:**
- Modify: `src/Controller/BracketController.php:32-79` (create), `:81-113` (edit), `templates/bracket/create.html.twig`, `templates/bracket/edit.html.twig`
- Test: `tests/Functional/BracketOpponentTest.php`

**Interfaces:**
- Consumes: `UserRepository::findActiveOpponents()` (Task 1), `SessionAuthenticator::requireUser()` (Task 2).
- Produces: bracket creation takes `opponent_username` instead of `player1_id`/`player2_id`; the creator is always player 1.

- [ ] **Step 1: Write the failing tests**

Create `tests/Functional/BracketOpponentTest.php`:

```php
<?php

namespace App\Tests\Functional;

use App\Entity\UserStatus;
use App\Repository\BracketRepository;

class BracketOpponentTest extends WebTestCase
{
    public function testCreateFormListsActiveUsersExceptYourself(): void
    {
        $this->createUser('opp_list_me');
        $this->createUser('opp_list_other');
        $this->createUser('opp_list_disabled', 'password', null, false, UserStatus::Disabled);
        $this->loginViaForm('opp_list_me');

        $this->client->request('GET', '/brackets/create');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('datalist#opponents');

        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('opp_list_other', $html);
        $this->assertStringNotContainsString('opp_list_disabled', $html);
    }

    public function testCreatingABracketMakesYouPlayerOne(): void
    {
        $me = $this->createUser('opp_create_me');
        $them = $this->createUser('opp_create_them');
        $this->loginViaForm('opp_create_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Opponent Test Bracket',
            'year' => 2026,
            'opponent_username' => 'opp_create_them',
        ]);
        $this->client->submit($form);

        $brackets = static::getContainer()->get(BracketRepository::class)
            ->findBy(['name' => 'Opponent Test Bracket']);
        $this->assertCount(1, $brackets);
        $this->assertSame($me->getId(), $brackets[0]->getPlayer1()->getId());
        $this->assertSame($them->getId(), $brackets[0]->getPlayer2()->getId());
    }

    public function testUnknownOpponentIsRejected(): void
    {
        $this->createUser('opp_unknown_me');
        $this->loginViaForm('opp_unknown_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Bad Opponent',
            'year' => 2026,
            'opponent_username' => 'nobody_by_that_name',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');
        $this->assertCount(0, static::getContainer()->get(BracketRepository::class)->findBy(['name' => 'Bad Opponent']));
    }

    public function testDisabledOpponentIsRejected(): void
    {
        $this->createUser('opp_dis_me');
        $this->createUser('opp_dis_them', 'password', null, false, UserStatus::Disabled);
        $this->loginViaForm('opp_dis_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Disabled Opponent',
            'year' => 2026,
            'opponent_username' => 'opp_dis_them',
        ]);
        $this->client->submit($form);

        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');
    }

    public function testYouCannotPlayYourself(): void
    {
        $this->createUser('opp_self_me');
        $this->loginViaForm('opp_self_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Self Bracket',
            'year' => 2026,
            'opponent_username' => 'opp_self_me',
        ]);
        $this->client->submit($form);

        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');
    }
}
```

**Note:** these tests exercise bracket creation, which calls `EspnApiService::populateBracketTeams()`. That makes a live HTTP request. Before running, confirm whether the existing suite already tolerates this — `BracketControllerTest::testCreateBracketPage` only does a GET. If creation is slow or flaky against the network, wrap the ESPN call so a failure only produces the existing `warning` flash (it already does) and keep the assertions on bracket rows, not flashes, exactly as written above.

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec php bin/phpunit tests/Functional/BracketOpponentTest.php`
Expected: FAIL — no `datalist#opponents`; the form has `player1_id`/`player2_id`.

- [ ] **Step 3: Rewrite the create action**

In `src/Controller/BracketController.php`, replace the player-assignment portion of `create()`:

```php
        $user = $auth->requireUser();
        $opponents = $userRepository->findActiveOpponents($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $name = trim($request->request->get('name', ''));
            $year = (int) $request->request->get('year', date('Y'));
            $opponentUsername = trim($request->request->get('opponent_username', ''));

            $opponent = $opponentUsername === '' ? null : $userRepository->findByUsername($opponentUsername);
            $opponentIsValid = $opponent
                && $opponent->isActive()
                && $opponent->getId() !== $user->getId();

            $error = null;
            if ($name === '') {
                $error = 'Bracket name is required.';
            } elseif (!$opponentIsValid) {
                $error = 'Pick an opponent from the list.';
            }

            if ($error !== null) {
                $this->addFlash('error', $error);
                return $this->render('bracket/create.html.twig', [
                    'opponents' => $opponents,
                    'currentUser' => $user,
                ]);
            }

            $bracket = new Bracket();
            $bracket->setName($name);
            $bracket->setYear($year);
            $bracket->setFirstPicker(random_int(1, 2));
            $bracket->setPlayer1($user);
            $bracket->setPlayer2($opponent);

            $em->persist($bracket);
            $bracketBuilder->buildBracket($bracket);

            // ... existing ESPN team population block unchanged ...

            return $this->redirectToRoute('app_bracket_show', ['id' => $bracket->getId()]);
        }

        return $this->render('bracket/create.html.twig', [
            'opponents' => $opponents,
            'currentUser' => $user,
        ]);
```

- [ ] **Step 4: Rewrite the edit action's player handling**

In `edit()`, keep player 1 untouched and resolve only the opponent:

```php
        $user = $auth->requireBracketAccess($bracket);
        $opponents = $userRepository->findActiveOpponents($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $name = trim($request->request->get('name', ''));
            if ($name !== '') {
                $bracket->setName($name);
            }

            $opponentUsername = trim($request->request->get('opponent_username', ''));
            if ($opponentUsername !== '') {
                $opponent = $userRepository->findByUsername($opponentUsername);
                if ($opponent && $opponent->isActive() && $opponent->getId() !== $bracket->getPlayer1()?->getId()) {
                    $bracket->setPlayer2($opponent);
                } else {
                    $this->addFlash('error', 'Pick an opponent from the list.');
                    return $this->render('bracket/edit.html.twig', [
                        'bracket' => $bracket,
                        'opponents' => $opponents,
                    ]);
                }
            }

            $em->flush();
            $this->addFlash('success', 'Bracket updated.');
            return $this->redirectToRoute('app_bracket_show', ['id' => $bracket->getId()]);
        }

        return $this->render('bracket/edit.html.twig', [
            'bracket' => $bracket,
            'opponents' => $opponents,
        ]);
```

- [ ] **Step 5: Rewrite the create template's player block**

In `templates/bracket/create.html.twig`, replace the entire `grid grid-cols-2` player block with:

```twig
    <div>
        <span class="block text-sm font-medium text-gray-700 mb-1">Player 1</span>
        <p class="border border-gray-200 bg-gray-50 rounded px-3 py-2 text-gray-700">{{ currentUser.username }} (you)</p>
    </div>

    <div>
        <label for="opponent_username" class="block text-sm font-medium text-gray-700 mb-1">Who are you playing?</label>
        <input type="text" name="opponent_username" id="opponent_username" list="opponents" required
               autocomplete="off" placeholder="Start typing a username"
               class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
        <datalist id="opponents">
            {% for u in opponents %}<option value="{{ u.username }}">{% endfor %}
        </datalist>
        {% if opponents is empty %}
            <p class="text-sm text-gray-500 mt-1">No other active players yet — ask an admin to invite someone.</p>
        {% endif %}
    </div>
```

- [ ] **Step 6: Apply the same field to the edit template**

In `templates/bracket/edit.html.twig`, replace its two player selects with the player-1 display (`{{ bracket.player1Name }}`) and the same `opponent_username` input plus `datalist#opponents`, pre-filling `value="{{ bracket.player2 ? bracket.player2.username : '' }}"`.

- [ ] **Step 7: Rebuild CSS and run the tests**

Run:
```bash
ddev exec php bin/console tailwind:build
ddev exec php bin/phpunit tests/Functional/BracketOpponentTest.php
```
Expected: PASS (5 tests)

- [ ] **Step 8: Run the full suite**

Run: `ddev composer test`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add src/ templates/ tests/ var/tailwind/
git commit -m "Select bracket opponent by autocomplete, creator is always player 1"
```

---

## Task 12: Enforce NOT NULL on email, and documentation

**Files:**
- Create: a hand-written migration in `migrations/`
- Modify: `CLAUDE.md`, `README.md`, `.env.example`
- Test: `tests/Functional/UserEmailConstraintTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `user.email` is `NOT NULL UNIQUE` in the database.

**Deployment order — read before starting.** `.github/workflows/deploy.yml` runs `doctrine:migrations:migrate` on every push to `main`. This migration must not reach production until the phase 2 backfill has run there. Sequence:

1. Merge and push Tasks 1–11. Production gets the nullable `email` column.
2. On production, run `app:user <username> --email=<address>` for each existing account, and `--admin` for the administrator. The username-to-email map is held outside this repository — ask the project owner.
3. Verify: `SELECT username FROM user WHERE email IS NULL` returns zero rows.
4. Only then push this task's migration.

- [ ] **Step 1: Write the test**

Create `tests/Functional/UserEmailConstraintTest.php`:

```php
<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the schema directly rather than provoking a constraint violation —
 * a failed flush poisons the DAMA transaction for every later test in the class.
 */
class UserEmailConstraintTest extends KernelTestCase
{
    private function emailColumn(): array
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        return $connection->fetchAssociative(
            'SELECT IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['user', 'email'],
        );
    }

    public function testEmailColumnIsNotNullable(): void
    {
        $this->assertSame('NO', $this->emailColumn()['IS_NULLABLE']);
    }

    public function testEmailColumnIsUnique(): void
    {
        $this->assertSame('UNI', $this->emailColumn()['COLUMN_KEY']);
    }

    public function testNoAccountIsMissingAnEmail(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $missing = $connection->fetchFirstColumn(
            'SELECT username FROM `user` WHERE email IS NULL OR email = \'\''
        );

        $this->assertSame([], $missing);
    }
}
```

- [ ] **Step 2: Make the column non-nullable in the entity**

In `src/Entity/User.php`, change the mapping and the accessors:

```php
    #[ORM\Column(length: 180, unique: true)]
    private string $email;
```

```php
    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));
        return $this;
    }
```

Update the admin dashboard template: the `{{ u.email ?? '—' }}` and `(missing)` markup in `templates/admin/dashboard.html.twig` is now unreachable — replace it with plain `{{ u.email }}`.

Update `src/Service/PasswordResetService.php`: drop the now-redundant `|| $user->getEmail() === null` clause from `request()`.

- [ ] **Step 3: Write the migration by hand**

Run `ddev exec php bin/console doctrine:migrations:generate` and fill in `up()`:

```php
    public function up(Schema $schema): void
    {
        // Guard: refuse to run against rows the phase 2 backfill missed, rather
        // than failing on an opaque constraint violation.
        $missing = $this->connection->fetchFirstColumn(
            'SELECT username FROM `user` WHERE email IS NULL OR email = \'\''
        );

        $this->abortIf(
            count($missing) > 0,
            'Cannot enforce NOT NULL on user.email — these accounts have no address: '
                . implode(', ', $missing)
                . '. Run app:user <username> --email=<address> for each, then retry.'
        );

        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(180) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(180) DEFAULT NULL');
    }
```

- [ ] **Step 4: Backfill locally, then migrate and test**

Run, using placeholder addresses for local development only:

```bash
ddev exec php bin/console dbal:run-sql "SELECT username FROM \`user\` WHERE email IS NULL"
# For each username returned:
ddev exec php bin/console app:user <username> --email=<username>@example.com
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
ddev composer test
```

Expected: migration applies; full suite PASSES.

- [ ] **Step 5: Update the documentation**

In `CLAUDE.md`, replace the Authentication section with:

```markdown
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
```

In `README.md`, replace the "Create or Update Users" section with the invite flow, keeping the `app:user` command documented as the break-glass path and noting that `MAILER_DSN` must be set in `.env.local` for invitations to send.

- [ ] **Step 6: Commit**

```bash
git add src/ templates/ migrations/ tests/ CLAUDE.md README.md .env.example
git commit -m "Enforce NOT NULL on user.email and update documentation"
```

---

## Deployment Checklist

Run through this before and after the two pushes.

- [ ] `MAILER_DSN` and `MAILER_FROM` set in `.env.local` on the Dreamhost server (never committed).
- [ ] `MAILER_FROM` is an address on a domain the SMTP provider is authorized to send for.
- [ ] Push Tasks 1–11. Confirm the deploy ran migrations cleanly.
- [ ] On production: `app:user <username> --email=<address>` for all five accounts, `--admin` for the administrator. Map supplied out-of-band.
- [ ] On production: `SELECT username FROM user WHERE email IS NULL` returns zero rows.
- [ ] Push Task 12's migration. Confirm the guard did not abort.
- [ ] Log in as the admin, load `/admin`, send a real invitation to yourself, and accept it.
- [ ] Confirm the invitation email arrives in an inbox, not a spam folder.
