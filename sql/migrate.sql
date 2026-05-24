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

-- Add future migrations below as additional blocks of the same shape.
