# Invite-Based Registration, Admin Dashboard, and Opponent Selection

**Date:** 2026-09-15
**Status:** Approved; production email map confirmed 2026-09-15 (held outside the repo)

## Problem

Accounts exist only via the `app:user` CLI command run on the server. The `User`
entity holds three fields (`id`, `username`, `password`) — no email, no roles, no
status. Consequences:

- Adding a player requires SSH access to Dreamhost.
- A user who forgets a password has no recovery path except the admin re-running
  `app:user`.
- There is no admin surface of any kind.
- Bracket creation (`templates/bracket/create.html.twig`) renders two unscoped
  `<select>` elements over `userRepository->findAll()`. The creator is not tied to
  the bracket, and both player slots are free-choice.
- `/brackets/{id}`, `/brackets/{id}/edit`, and the four bracket API endpoints
  check only that *someone* is logged in, never that the bracket belongs to them.
  Any authenticated user can read or edit any bracket by ID, including
  reassigning its players.

## Goals

1. Admin-issued email invitations replace CLI-only account creation.
2. An admin dashboard listing all users and all invites, with lifecycle actions.
3. Opponent selection by autocomplete over active users when creating a bracket.
4. Self-service password reset by email.
5. Close the bracket authorization hole.
6. Add CSRF protection and session-fixation defence to the custom auth.

## Non-Goals

- Public self-service registration. Invite-only, admin-issued.
- User-initiated invites with admin approval. Explicitly deferred — the data
  model reserves room for it (see "Extension seam").
- Deleting users. Disable only; deletion would orphan bracket history.
- Migrating to Symfony's security component. Authentication stays custom and
  session-based, per the existing architecture.
- Asynchronous mail. Dreamhost shared hosting has no queue worker; sends are
  synchronous.

## Constraint: the repository is public

`adamzimmermann/spread-dread` is public. No real email address, credential, or
other personal datum may appear in a tracked file — migrations, fixtures, tests,
and specs included. Test fixtures use `@example.com` addresses. This constraint
shapes the migration strategy below.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Mail transport | Third-party SMTP via `MAILER_DSN` | Dreamhost shared IPs have inconsistent deliverability and no bounce visibility. A silently spam-foldered invite fails invisibly. |
| Entry path | Invite-only, admin-issued | Friends-only app; smallest attack surface. |
| Who may invite | Admin only | Deferred: users inviting with admin approval. |
| Player slots | Creator is always player 1 | Makes "your brackets" correct by construction and pairs with the ownership check. |
| `User.email` | `NOT NULL`, unique | Required for reset; admin supplies addresses for existing accounts. |
| Token storage | SHA-256 hash only | A database leak must not confer the ability to mint accounts. |
| Autocomplete | Native `<datalist>` | Matches the vanilla-JS codebase; free mobile and a11y behavior. |

## Data Model

### `User` (extended)

| Field | Type | Notes |
|---|---|---|
| `email` | `string(180)` | unique, NOT NULL, stored lowercased |
| `isAdmin` | `bool` | default `false` |
| `status` | `string(16)` | `active` \| `disabled`, default `active` |
| `createdAt` | `datetime_immutable` | |
| `lastLoginAt` | `datetime_immutable` | nullable |
| `invitedBy` | `ManyToOne User` | nullable; audit trail |

`status` is a string column backed by a PHP enum. Disabling blocks login and
removes the user from opponent autocomplete, but leaves brackets and picks intact.

`email` is normalised to lowercase on write and on lookup. A `UNIQUE` column is
case-sensitive where mail providers are not, so without normalising,
`Player.One@example.com` and `player.one@example.com` would be two accounts and
a reset request could silently miss the real one.

### `Invite` (new)

| Field | Type | Notes |
|---|---|---|
| `email` | `string(180)` | |
| `tokenHash` | `string(64)` | SHA-256 hex, indexed |
| `status` | `string(20)` | `sent` \| `accepted` \| `revoked` (see below) |
| `createdBy` | `ManyToOne User` | |
| `createdAt` | `datetime_immutable` | |
| `expiresAt` | `datetime_immutable` | +14 days |
| `acceptedAt` | `datetime_immutable` | nullable |
| `acceptedUser` | `ManyToOne User` | nullable |

Only three statuses are ever persisted. Expiry is **derived** from `expiresAt` at
validation and display time, never written back — nothing sweeps the table, so a
stored `expired` value could only ever be stale. The dashboard shows "expired"
for a `sent` invite past its `expiresAt`.

### `PasswordResetToken` (new)

`user`, `tokenHash` (`string(64)`, indexed), `expiresAt` (+1 hour), `usedAt`
(nullable).

Kept separate from `Invite` rather than unified into a polymorphic token table:
different lifetimes, different semantics, and two small focused entities are
easier to reason about than one general one.

## Extension seam

`Invite.status` is an enum from the outset even though admin-issued invites only
ever take `sent`/`accepted`/`revoked`. Adding user-initiated invites
with admin approval later means adding a `pending_approval` case, a second email
template, and relaxing one guard — all inside `InviteService`. `Invite.createdBy`
is populated now for audit value and is already the field that feature needs.

All invite creation funnels through `InviteService`, which owns both the "who may
invite" policy and the "send now vs. queue for approval" decision.

## Lifecycles

### Invite

1. Admin submits an email address on `/admin`.
2. `InviteService` generates 32 random bytes (`random_bytes(32)`, hex-encoded),
   persists the SHA-256 hash, and sends `/invite/{token}`.
3. Recipient opens the link, sets a username and password. Email is displayed
   read-only, taken from the invite.
4. On submit: `User` created with `status = active`, `email` from the invite, and
   `invitedBy = invite.createdBy`; invite marked `accepted`; session started
   (with `migrate(true)`); redirect to the bracket index.
5. Expired, revoked, already-accepted, and unrecognised tokens all render the
   **same** generic "this link is no longer valid" page. No state is disclosed.

Resending overwrites `tokenHash` on the same row and resets `expiresAt`, which
invalidates the previously mailed link. The invite keeps its identity and audit
fields.

### Password reset

1. `/forgot-password` renders an identical confirmation whether or not the
   address exists, so the form cannot be used to enumerate accounts.
2. Token is single-use, valid one hour.
3. A successful reset invalidates every other outstanding token for that user,
   calls `session->migrate(true)`, and logs the user in.

### Login

Unchanged in shape — lookup by username, `password_verify()` — plus:
`status === active` required, `session->migrate(true)` on success, `lastLoginAt`
updated. `requireUser()` also re-checks `status`, so disabling a user ejects them
on their next request rather than whenever their session expires.

## Routes

**Public**

| Route | Purpose |
|---|---|
| `GET\|POST /invite/{token}` | Accept invite |
| `GET\|POST /forgot-password` | Request reset |
| `GET\|POST /reset-password/{token}` | Set new password |

**Admin** (`requireAdmin()`)

| Route | Purpose |
|---|---|
| `GET /admin` | Dashboard |
| `POST /admin/invites` | Create and send |
| `POST /admin/invites/{id}/resend` | New token, old one dies |
| `POST /admin/invites/{id}/revoke` | Revoke outstanding invite |
| `POST /admin/users/{id}/status` | Enable / disable |
| `POST /admin/users/{id}/admin` | Grant / revoke admin |

Every state-changing action is a POST carrying a CSRF token. No GET links mutate.

## Components

- `App\Security\SessionAuthenticator` — `getUser()`, `requireUser()`,
  `requireAdmin()`. Replaces the `requireUser()` method currently duplicated
  privately in `BracketController` and `GameController`.
- `App\Service\InviteService` — create, resend, revoke, accept; token generation
  and hashing; sending.
- `App\Service\PasswordResetService` — request, validate, consume.
- `Bracket::hasPlayer(User): bool` — ownership test.
- `AdminController`, `InviteController`, `PasswordResetController` — thin HTTP
  layers over the services.

## Admin dashboard

One page, two tables.

**Users:** username, email, status, admin flag, created, last login, bracket
count, invited by. Inline enable/disable and grant/revoke admin.

**Invites:** email, status, sent, expires, invited by. Inline resend and revoke.

The invite URL cannot be listed in this table: only the token's hash is stored, so
the link is unrecoverable after the request that created it. When sending fails,
the link is surfaced once in the resulting flash message, labelled as
un-repeatable. Recovering from a missed one means resending, which issues a fresh
token.

Sorted by last login descending. No pagination — the expected scale is dozens.

## Opponent autocomplete

`templates/bracket/create.html.twig` renders player 1 as fixed text (the logged-in
user) and a single field for the opponent:

```html
<input list="opponents" name="opponent_username" autocomplete="off" required>
<datalist id="opponents">
  {% for u in activeUsers %}<option value="{{ u.username }}">{% endfor %}
</datalist>
```

The form submits the username string; the controller resolves it server-side.
Usernames are already unique, and this avoids the failure mode where a hidden ID
field drifts out of sync with the visible text. Validation rejects any value that
is not an active user, and rejects the current user.

`templates/bracket/edit.html.twig` gets the same treatment for its opponent slot.

This ships the full active-user list in the page source, which is acceptable for
an invite-only app among friends. If that changes, the upgrade is a
`GET /api/users/search?q=` endpoint behind a combobox, discarding the datalist
markup.

## Email

`composer require symfony/mailer`. Two `TemplatedEmail` templates under
`templates/email/` — invite and password reset — each with an HTML and a plain
text alternative. Deliberately plain: heavily styled mail from a new sending
domain is what trips spam filters.

`MAILER_DSN` and `MAILER_FROM` live in `.env.local` on the server and are never
committed. `.env.example` documents both.

Sends are synchronous. `InviteService` catches `TransportException` and surfaces
the failure in the dashboard. The invite row is still created when sending fails,
and the failing request surfaces the link once so it can be delivered by hand — so
a broken SMTP configuration never means a lost invite, though it does mean acting
on the spot or resending later.

## Security

- Tokens: 32 random bytes, hex in the URL, SHA-256 in the database.
- Generic failure page for every invalid-token case.
- Identical response for known and unknown addresses on `/forgot-password`.
- `session->migrate(true)` on login, invite acceptance, and password reset.
- CSRF: a single `<meta name="csrf-token">` in `base.html.twig`, read once in
  `assets/app.js` and appended to every `FormData` in the existing `fetch` calls.
  This covers all AJAX endpoints without changing each call site's contract.
  Server-side validation via `isCsrfTokenValid()`. Requires
  `symfony/security-csrf`.
- Ownership: `show`, `edit`, and all four bracket API endpoints require
  `bracket.hasPlayer(user) || user.isAdmin`.
- `app:user` is retained as a break-glass path, extended with `--email` and
  `--admin`. It is how the first admin is created and the recovery path if SMTP
  fails.

## Migration

`adamzimmermann/spread-dread` is a **public** GitHub repository, and
`.github/workflows/deploy.yml` runs `doctrine:migrations:migrate` automatically on
every push to `main`. Migration files are therefore tracked and published, so the
existing users' email addresses — four of which belong to other people — must not
appear in one. The backfill is split across two deploys instead.

**Phase 1** (migration A, first deploy)

1. Add `email` as nullable, plus `is_admin`, `status`, `created_at`,
   `last_login_at`, `invited_by_id`.
2. Set `status = 'active'` and a `created_at` for all existing rows.
3. Create the `invite` and `password_reset_token` tables.

**Phase 2** (manual, between deploys)

Run on production, once:

```
php bin/console app:user <username> --email=<address>
```

for each existing account, and `--admin` for the administrator. `app:user` is
extended to accept `--email` and `--admin` and to update an existing account
without requiring a password argument.

**Phase 3** (migration B, second deploy)

1. Count rows with a NULL `email`. If any, throw naming the offending usernames,
   so an unfilled account halts the deploy with a clear message rather than an
   opaque constraint violation.
2. `ALTER` `email` to `NOT NULL UNIQUE`.

Migration B is written in the same branch as A but must not be pushed until the
phase 2 backfill has run, or the deploy will halt at step 1. This is the intended
behaviour — halting is strictly better than inventing addresses — but it means
the two pushes are ordered, not simultaneous.

The username-to-email map itself is held outside the repository and supplied to
whoever runs phase 2.

Remaining tables and columns generated with `doctrine:migrations:diff`.

## Testing

Extending `tests/Functional/WebTestCase.php`, with `MailerAssertionsTrait` and a
null transport.

- **Invite:** valid token creates an active user and establishes a session;
  expired, revoked, reused, and malformed tokens all render the same generic
  failure; accepting sets `invitedBy`.
- **Admin:** every `/admin` route returns 403 for a non-admin; disabling a user
  blocks their next login; granting admin grants access.
- **Password reset:** known and unknown addresses produce identical responses;
  single-use enforced; expiry enforced; other outstanding tokens invalidated.
- **Bracket authorization:** user B receives 403 on user A's bracket for `show`,
  `edit`, and each of the four API endpoints. Regression test for the closed hole.
- **Unit:** `InviteService` token hashing and expiry; opponent username
  resolution rejecting inactive users and self-selection.

## Documentation

- `CLAUDE.md` — the Authentication section describes `requireUser()` as a private
  controller helper; that becomes wrong. Add the admin/invite model.
- `README.md` — registration is no longer CLI-only.
- `.env.example` — `MAILER_DSN`, `MAILER_FROM`.
- Run `tailwind:build` for the new templates before committing.
