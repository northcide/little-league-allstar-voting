# Allstar ⚾ — Anonymous Little League All-Star Coach Voting

A self-hosted web app for running anonymous All-Star coach votes. The admin sets
up an election, distributes simple "word codes" to coaches, runs multiple rounds
of voting with live submission tracking, and finalizes results with override
controls.

## Features

- **Admin** creates named elections (e.g., "Majors International"), sets the
  player roster, configures any number of rounds with custom *picks per coach*
  and *picks to lock per round*, generates a list of memorable single-word codes
  to hand to each coach.
- **Coach** logs in with the election code + their word, picks players each
  round (multi-select, no ranking), and submits. Submitted ballots are locked
  unless the admin resets them.
- **Live admin dashboard** (2-second polling): expected voters, # logged in, #
  submitted this round, # outstanding, plus a per-code badge grid.
- **Locked players** from prior rounds appear inline in the next ballot, grayed
  out and unselectable.
- **Coach result view** shows the round's winners and a tie-at-cutoff flag. No
  raw vote counts are shown to coaches. Locked players display the round they
  were locked in (`RD1 🔒`, `RD2 🔒`, …). Unlocked players that were tied at the
  cutoff in the most recent finalized round get a `⚖ TIED` indicator so coaches
  know who's still in play.
- **Admin result view** shows the full tally with vote counts per player.
- **Tiebreak rounds** can be added on the fly after the configured rounds.
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

## Word codes

`data/wordlist.txt` ships with several hundred short common nouns. The admin
clicks "Generate codes (n)" and the system draws `n` random unused words for
that election. Distribute one word per coach via text/print without recording
who got which word.

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
| Round tied at the cutoff | Dashboard shows the tie banner; click "+ Add Tiebreak Round" |
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
