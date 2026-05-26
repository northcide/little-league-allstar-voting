-- Allstar — idempotent migration script
--
-- Run this any time you pull updates from GitHub onto an existing install.
-- Safe to re-run: each step checks INFORMATION_SCHEMA before altering, so
-- already-applied changes are skipped (no errors).
--
-- From cPanel: phpMyAdmin → select your database → SQL tab → paste this whole
-- file → Go. From CLI: mysql -u USER -p DBNAME < sql/migrate.sql

-- ── 1. Add elections.max_roster_size (introduced 2026-05-24) ─────────────────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'elections'
             AND COLUMN_NAME  = 'max_roster_size');
SET @s := IF(@c = 0,
  'ALTER TABLE elections ADD COLUMN max_roster_size INT NOT NULL DEFAULT 12 AFTER expected_voters',
  'SELECT "skip: elections.max_roster_size already exists" AS migration');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 2. Drop rounds.is_tiebreak (removed 2026-05-24 with dynamic-rounds refactor) ──
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'rounds'
             AND COLUMN_NAME  = 'is_tiebreak');
SET @s := IF(@c = 1,
  'ALTER TABLE rounds DROP COLUMN is_tiebreak',
  'SELECT "skip: rounds.is_tiebreak already removed" AS migration');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. Add elections.coach_password (introduced 2026-05-26 with shared-password auth) ──
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'elections'
             AND COLUMN_NAME  = 'coach_password');
SET @s := IF(@c = 0,
  'ALTER TABLE elections ADD COLUMN coach_password VARCHAR(255) DEFAULT NULL AFTER max_roster_size',
  'SELECT "skip: elections.coach_password already exists" AS migration');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. Add rounds.round_type (introduced 2026-05-26 with alternate rounds) ────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'rounds'
             AND COLUMN_NAME  = 'round_type');
SET @s := IF(@c = 0,
  "ALTER TABLE rounds ADD COLUMN round_type ENUM('regular','alternate') NOT NULL DEFAULT 'regular' AFTER picks_to_lock",
  'SELECT "skip: rounds.round_type already exists" AS migration');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 5. Add ballot_picks.rank (NULL for regular rounds; 1..N for alternates) ───
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'ballot_picks'
             AND COLUMN_NAME  = 'rank');
SET @s := IF(@c = 0,
  'ALTER TABLE ballot_picks ADD COLUMN `rank` INT DEFAULT NULL AFTER player_id',
  'SELECT "skip: ballot_picks.rank already exists" AS migration');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 6. Add locked_roster.alternate_rank (NULL for regular roster; 1..N for alt) ─
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'locked_roster'
             AND COLUMN_NAME  = 'alternate_rank');
SET @s := IF(@c = 0,
  'ALTER TABLE locked_roster ADD COLUMN alternate_rank INT DEFAULT NULL AFTER locked_in_round',
  'SELECT "skip: locked_roster.alternate_rank already exists" AS migration');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 7. Create round_candidates table (per-round eligible-player whitelist) ────
SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'round_candidates');
SET @s := IF(@c = 0,
  'CREATE TABLE round_candidates (
     round_id  INT NOT NULL,
     player_id INT NOT NULL,
     PRIMARY KEY (round_id, player_id),
     FOREIGN KEY (round_id)  REFERENCES rounds(id)  ON DELETE CASCADE,
     FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
   ) ENGINE=InnoDB',
  'SELECT "skip: round_candidates table already exists" AS migration');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add future migrations below as additional blocks of the same shape.
