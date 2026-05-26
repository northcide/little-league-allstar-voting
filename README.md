# Allstar ⚾ — Anonymous Little League All-Star Coach Voting

A self-hosted web app for running anonymous All-Star coach votes. The admin sets
up an election, distributes simple numeric codes to coaches, runs multiple rounds
of voting with live submission tracking, and finalizes results with override
controls.

## Features

- **Admin** creates named elections (e.g., "Majors International"), sets the
  player roster, sets a target roster size, and picks a single shared coach
  password.
- **Coaches sign in** by picking the election from the public list and
  entering the shared password. The first device to sign in is auto-assigned
  Coach #1, the second is #2, etc. Cookies keep each device tied to its
  assigned number across reloads.
- **Dynamic rounds**: rounds are created one at a time. Each time the admin
  clicks **Start Next Round**, they enter that round's *Picks per coach* and
  *Picks to lock* — defaults match the previous round. There's no upfront
  round configuration and no preset number of rounds. The admin decides when
  to keep going and when to **Finalize All Rounds**.
- **Coach** picks players each round (multi-select, no ranking), and submits.
  Submitted ballots are locked unless the admin resets them.
- **Live admin dashboard** (2-second polling): coaches signed in, # logged
  in, # submitted this round, # outstanding, plus a per-coach badge grid.
- **Locked players** from prior rounds appear inline in the next ballot, grayed
  out and unselectable. The ballot is grouped by round so coaches see which
  players were locked in each round.
- **Coach result view** shows the round's winners and a tie-at-cutoff flag. No
  raw vote counts are shown to coaches. Unlocked players that were tied at the
  cutoff in the most recent finalized round get a `⚖ TIED` indicator so coaches
  know who's still in play.
- **Admin result view** shows the full tally with vote counts per player.
- **Admin overrides**: reset a coach's ballot, force-finalize a round, edit the
  locked players post-finalize, manually lock/unlock a player, revoke a code.

## Stack

LAMP — PHP 8.0+, MySQL 8, Apache. Vanilla JS SPA, no build step, no
dependencies.

## Install

1. Place the contents of this folder under your web root (e.g.
   `/var/www/html/allstar/`).
2. Make `data/` writable by the web server: `chmod 775 data && chown www-data data`.
3. Visit `https://your-host/allstar/api/setup.php` in a browser.
   Fill in DB credentials, league name, and choose an Admin PIN.
4. The wizard creates the database, loads `sql/schema.sql`, writes
   `api/config.php`, and stores a hashed Admin PIN.
5. **Delete or chmod 600 `api/setup.php`** afterward.
6. Open `https://your-host/allstar/` → log in as Admin and create your first
   election.

## Anonymity model — read this

The system is **pseudonymous, not cryptographically secret**:

- Coach ballots are stored in `ballot_picks`, keyed by a random `ballot_token`.
- The bridge `voter_code → ballot_token → picks` lives in **one** table,
  `submissions`. No production query joins `submissions` against `ballot_picks`
  to read votes — tallying reads only `ballot_picks`.
- However, an admin with raw database access can run that join manually and
  reconstruct who voted for whom. The user-facing requirement to show
  "X of N coaches have submitted" makes strict ballot secrecy impossible.

This matches the actual threat model for Little League coach voting: the admin
is a trusted league coordinator running the vote, not an adversary. If your
context demands cryptographic secrecy, this app is not the right tool.

### What coaches can see

Coaches see who got locked (and in which round) and which players tied at the
cutoff in the most recent finalized round, but never raw vote counts. The intent
is transparency about *outcomes* without revealing per-player vote totals, which
some leagues consider sensitive even when individual ballots remain anonymous.

What the system *does* prevent reliably:
- Other coaches cannot see who voted for whom.
- A coach cannot vote twice (one word = one ballot per round, enforced by a
  `UNIQUE(round_id, voter_code_id)` constraint).
- A coach cannot change their submitted ballot without admin intervention.

## Signing coaches in

Each election has a single shared coach password. The admin sets it when
creating the election (or changes it later from the Setup page). Coaches:

1. Open the site (or a `?e=<vote_code>` direct link).
2. Pick the election from the public list.
3. Enter the shared password.

The first device to sign in becomes Coach #1, the next is #2, etc. A
session cookie keeps that device tied to its assigned number across page
reloads. Once a coach is signed in, their number is shown in the top bar.

The admin can revoke any coach number from the **Signed In** page — that
immediately signs them out and prevents them from submitting future
ballots. This is the equivalent of removing a coach mid-election.

Trust model: the password gates entry, but anyone with the password can
sign in. The intended use is in-person at the league event where coaches
sign in on their own devices once. If you need stronger per-coach
identity (e.g., remote / unsupervised voting), this app isn't the right
tool.

## Tech notes

- Sessions: `allstar_session` cookie, HttpOnly, SameSite=Lax, 8-hour lifetime.
- CSRF: token in session, required on every POST as `X-CSRF-Token` header.
- All endpoints under `api/*.php` return JSON.
- Real-time updates use 2-second polling of `api/state.php`. Coach view backs
  off to 5 seconds once submitted.
- Concurrent submits during finalize are serialized via `SELECT … FOR UPDATE`
  on the round row.

## Override cheatsheet

| Situation | What to do |
| --- | --- |
| A coach voted by mistake and wants to re-vote | Overrides → "Reset a coach's ballot" |
| A coach lost their cookie / switched devices | They re-enter the same word; session resumes |
| One coach can't make it; you want to finalize anyway | On dashboard, click "Force-Finalize (n/N)" |
| Round tied at the cutoff | Click "Start Next Round" again — pick a tighter Pick/Lock (e.g., Pick 1, Lock 1) to resolve between the tied players |
| You want to manually pick the locked players | Results page → "Edit locked players (override)" |
| Add a player mid-election to the locked roster | Overrides → "Lock in" next to the player |
| A coach lost / shared their code | Voter Codes → Revoke their word |

## Database

Schema in `sql/schema.sql`. To inspect the audit trail:

```sql
SELECT created_at, actor, action, detail
FROM audit_log
WHERE election_id = ?
ORDER BY created_at DESC;
```

## License

Built for Little League boards to use freely. No warranty.
